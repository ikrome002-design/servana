<?php

declare(strict_types=1);

use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\FinanceOps\Support\DatabasePeriodLockRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'period-locks');

function createLock(User $finance, array $body): TestResponse
{
    return test()->actingAs($finance, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/period-locks', $body);
}

it('binds the database-backed repository (no longer always-open)', function (): void {
    expect(app(PeriodLockRepository::class))->toBeInstanceOf(DatabasePeriodLockRepository::class);
});

it('blocks a period-gated mutation whose business date falls inside a lock (423) but never a read', function (): void {
    $scn = cashUpScenario();
    $today = cashUpBusinessDate();

    createLock($scn['finance'], ['period_start' => $today, 'period_end' => $today])
        ->assertCreated()->assertJsonPath('data.status', 'locked')->assertJsonPath('data.scope', 'merchant');

    // A period-gated cash-up draft on the locked day is refused.
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 1000]])
        ->assertStatus(423)->assertJsonPath('error.code', 'financial_period_locked');

    // A pure read is never blocked.
    test()->actingAs($scn['finance'], 'sanctum')->getJson('/api/v1/cash-ups')->assertOk();
});

it('scopes enforcement: a merchant-wide lock covers all branches; a branch lock covers only its branch', function (): void {
    $scn = cashUpScenario();
    $merchantId = $scn['merchant']->id;
    $branchId = $scn['branch']->id;
    $today = CarbonImmutable::now('Africa/Nairobi');
    $repo = app(PeriodLockRepository::class);

    // Branch-specific lock.
    FinancialPeriodLock::factory()->create([
        'merchant_id' => $merchantId, 'branch_id' => $branchId,
        'period_start' => $today->toDateString(), 'period_end' => $today->toDateString(),
        'status' => FinancialPeriodLockStatus::Locked,
    ]);

    expect($repo->isLocked($merchantId, $branchId, $today))->toBeTrue()          // that branch
        ->and($repo->isLocked($merchantId, $branchId + 999, $today))->toBeFalse() // another branch
        ->and($repo->isLocked($merchantId, null, $today))->toBeFalse()            // merchant-wide op
        ->and($repo->isLocked($merchantId, $branchId, $today->addDay()))->toBeFalse(); // outside range
});

it('a merchant-wide lock blocks a merchant-wide operation and every branch', function (): void {
    $scn = cashUpScenario();
    $merchantId = $scn['merchant']->id;
    $today = CarbonImmutable::now('Africa/Nairobi');

    FinancialPeriodLock::factory()->create([
        'merchant_id' => $merchantId, 'branch_id' => null,
        'period_start' => $today->toDateString(), 'period_end' => $today->toDateString(),
        'status' => FinancialPeriodLockStatus::Locked,
    ]);
    $repo = app(PeriodLockRepository::class);

    expect($repo->isLocked($merchantId, null, $today))->toBeTrue()
        ->and($repo->isLocked($merchantId, $scn['branch']->id, $today))->toBeTrue();
});

it('rejects a lock over a range already covered by an active lock for the same scope (422 overlapping)', function (): void {
    $scn = cashUpScenario();

    createLock($scn['finance'], ['period_start' => '2026-06-01', 'period_end' => '2026-06-30'])->assertCreated();

    // Overlapping same-scope (merchant-wide) range → 422.
    createLock($scn['finance'], ['period_start' => '2026-06-15', 'period_end' => '2026-07-15'])
        ->assertStatus(422)->assertJsonPath('error.code', 'overlapping_period_lock');

    // Non-overlapping range → allowed.
    createLock($scn['finance'], ['period_start' => '2026-07-01', 'period_end' => '2026-07-31'])->assertCreated();

    // A branch-scoped lock over the SAME range is a different scope → allowed.
    createLock($scn['finance'], ['branch' => $scn['branch']->ulid, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30'])->assertCreated();
});

it('rejects an inverted period range (422 invalid_period_range)', function (): void {
    $scn = cashUpScenario();

    createLock($scn['finance'], ['period_start' => '2026-06-30', 'period_end' => '2026-06-01'])
        ->assertStatus(422); // after_or_equal validation OR invalid_period_range
});

it('forbids a non-Finance role from creating a period lock (403)', function (): void {
    $scn = cashUpScenario();

    createLock($scn['branchManager'], ['period_start' => cashUpBusinessDate(), 'period_end' => cashUpBusinessDate()])->assertForbidden();
    createLock($scn['frontOffice'], ['period_start' => cashUpBusinessDate(), 'period_end' => cashUpBusinessDate()])->assertForbidden();
});

it('returns 404 for a foreign-tenant period lock ULID', function (): void {
    $scn = cashUpScenario();
    $ulid = (string) createLock($scn['finance'], ['period_start' => cashUpBusinessDate(), 'period_end' => cashUpBusinessDate()])
        ->assertCreated()->json('data.id');

    $other = cashUpScenario();
    test()->actingAs($other['finance'], 'sanctum')->getJson("/api/v1/period-locks/{$ulid}")->assertNotFound();
});
