<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Services\PermissionRegistry;
use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'period-locks');

function reopenRequest(User $finance, string $lockUlid, string $reason = 'Correcting a posting error.'): TestResponse
{
    return test()->actingAs($finance, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/period-locks/{$lockUlid}/reopen", ['reason' => $reason]);
}

function reopenApprove(User $admin, string $lockUlid): TestResponse
{
    return test()->actingAs($admin, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/period-locks/{$lockUlid}/reopen/approve");
}

function reopenExecute(User $finance, string $lockUlid, ?int $mfaAt = null): TestResponse
{
    return test()->statefulMfa($mfaAt ?? now()->getTimestamp())->actingAs($finance, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/period-locks/{$lockUlid}/reopen/execute");
}

it('runs a routine Finance reopen: request → execute (fresh MFA) → reopened', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    $lock = FinancialPeriodLock::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => null,
        'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'status' => FinancialPeriodLockStatus::Locked, 'exception_required' => false,
        'locked_by' => $scn['finance']->id,
    ]);

    reopenRequest($scn['finance'], $lock->ulid)->assertOk()->assertJsonPath('data.status', 'locked');
    reopenExecute($scn['finance'], $lock->ulid)->assertOk()->assertJsonPath('data.status', 'reopened');

    expect($lock->refresh()->status)->toBe(FinancialPeriodLockStatus::Reopened);
});

it('denies reopen execution without a fresh MFA step-up (403 step_up_required)', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    $lock = FinancialPeriodLock::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => null,
        'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'status' => FinancialPeriodLockStatus::Locked, 'exception_required' => false,
        'locked_by' => $scn['finance']->id,
    ]);
    reopenRequest($scn['finance'], $lock->ulid)->assertOk();

    $stale = now()->subMinutes((int) config('servana.mfa.step_up_window_minutes') + 1)->getTimestamp();
    reopenExecute($scn['finance'], $lock->ulid, $stale)
        ->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');
});

it('runs an exceptional reopen: Finance request → MA approve (distinct) → Finance execute', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    [$admin] = memberWithRole(MerchantUserRole::MerchantAdmin, $scn['merchant']);
    confirmedTotp($admin);

    $lock = FinancialPeriodLock::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => null,
        'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
        'status' => FinancialPeriodLockStatus::Locked, 'exception_required' => true,
        'locked_by' => $scn['finance']->id,
    ]);

    reopenRequest($scn['finance'], $lock->ulid)->assertOk();

    // Executing before approval is refused.
    reopenExecute($scn['finance'], $lock->ulid)
        ->assertStatus(422)->assertJsonPath('error.code', 'period_reopen_approval_required');

    // A distinct Merchant Administrator approves; Finance may NOT approve (no key).
    reopenApprove($scn['finance'], $lock->ulid)->assertForbidden();
    reopenApprove($admin, $lock->ulid)->assertOk();

    reopenExecute($scn['finance'], $lock->ulid)->assertOk()->assertJsonPath('data.status', 'reopened');
});

it('enforces the maker/checker incompatibility period_lock.reopen ⟂ merchant.period_reopen.approve_exception', function (): void {
    $registry = app(PermissionRegistry::class);

    $finance = $registry->defaultGrantsFor('finance');
    $admin = $registry->defaultGrantsFor('merchant_admin');

    // Finance holds reopen but NOT the exception approval; MA holds approval but NOT reopen.
    expect($finance)->toContain('period_lock.reopen')->not->toContain('merchant.period_reopen.approve_exception')
        ->and($admin)->toContain('merchant.period_reopen.approve_exception')->not->toContain('period_lock.reopen')
        ->and($admin)->not->toContain('period_lock.create');
});

it('emits the reopen governance audit chain', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    [$admin] = memberWithRole(MerchantUserRole::MerchantAdmin, $scn['merchant']);
    confirmedTotp($admin);
    $lock = FinancialPeriodLock::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => null,
        'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        'status' => FinancialPeriodLockStatus::Locked, 'exception_required' => true,
        'locked_by' => $scn['finance']->id,
    ]);

    reopenRequest($scn['finance'], $lock->ulid)->assertOk();
    reopenApprove($admin, $lock->ulid)->assertOk();
    reopenExecute($scn['finance'], $lock->ulid)->assertOk();

    $actions = AuditLog::query()->pluck('action')->all();
    expect($actions)->toContain(AuditEvent::FinancialPeriodReopenRequested->value)
        ->and($actions)->toContain(AuditEvent::FinancialPeriodReopenApproved->value)
        ->and($actions)->toContain(AuditEvent::FinancialPeriodReopened->value);
});
