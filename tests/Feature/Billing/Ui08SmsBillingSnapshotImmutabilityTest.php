<?php

declare(strict_types=1);

use App\Domain\Billing\Models\PlatformSmsBillingRule;
use App\Domain\Messaging\Sms\Models\SmsBillingEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('billing', 'ui08', 'ui08-sms-billing');

/*
 | COR-UI08-001 §9 — the pricing series is append-only and a charged row is never re-priced.
 |
 | These are DATABASE assertions on purpose. The service layer refuses these operations too, but
 | the guarantee that matters is the one that holds when the service layer is bypassed, so every
 | case here writes through raw SQL or a quiet save and expects the trigger to raise.
 */

/**
 * Run a statement expected to violate a database guard, inside its OWN savepoint.
 *
 * PostgreSQL aborts the whole transaction when a statement raises, and RefreshDatabase wraps each
 * test in one — so without a savepoint the FIRST expected violation poisons every later query in
 * the same test with "current transaction is aborted". A nested DB::transaction() issues a
 * SAVEPOINT and rolls back to it before rethrowing, which leaves the outer test transaction usable.
 */
function ui08ExpectGuardViolation(Closure $statement): void
{
    expect(static fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

it('registers the pricing table as platform-owned with no merchant or branch column', function (): void {
    expect(Schema::hasTable('platform_sms_billing_rules'))->toBeTrue()
        ->and(Schema::hasColumn('platform_sms_billing_rules', 'merchant_id'))->toBeFalse()
        ->and(Schema::hasColumn('platform_sms_billing_rules', 'branch_id'))->toBeFalse()
        // No currency column: the effective platform billing settings version stays the sole authority.
        ->and(Schema::hasColumn('platform_sms_billing_rules', 'currency'))->toBeFalse();
});

it('refuses to delete a pricing rule', function (): void {
    $rule = PlatformSmsBillingRule::factory()->create();

    ui08ExpectGuardViolation(fn () => DB::table('platform_sms_billing_rules')->where('id', $rule->id)->delete());

    expect(PlatformSmsBillingRule::query()->whereKey($rule->id)->exists())->toBeTrue();
});

it('freezes the unit cost of an existing rule', function (): void {
    $rule = PlatformSmsBillingRule::factory()->create(['unit_cost_minor' => 100]);

    ui08ExpectGuardViolation(fn () => DB::table('platform_sms_billing_rules')->where('id', $rule->id)->update(['unit_cost_minor' => 999]));

    expect($rule->refresh()->unit_cost_minor)->toBe(100);
});

it('freezes the effective date of an existing rule', function (): void {
    $instant = CarbonImmutable::now()->subMonth()->startOfSecond();
    $rule = PlatformSmsBillingRule::factory()->create(['effective_from' => $instant]);

    ui08ExpectGuardViolation(fn () => DB::table('platform_sms_billing_rules')
        ->where('id', $rule->id)
        ->update(['effective_from' => CarbonImmutable::now()->addYear()]));
});

it('refuses to cancel a rule that has already taken effect, at the database', function (): void {
    $rule = PlatformSmsBillingRule::factory()->create(['effective_from' => CarbonImmutable::now()->subDay()]);

    ui08ExpectGuardViolation(fn () => DB::table('platform_sms_billing_rules')->where('id', $rule->id)->update([
        'cancelled_at' => now(),
        'cancelled_by_user_id' => $rule->created_by_user_id,
        'cancellation_reason' => 'Bypassing the service layer.',
    ]));

    expect($rule->refresh()->cancelled_at)->toBeNull();
});

it('permits cancelling a still-pending rule exactly once', function (): void {
    $rule = PlatformSmsBillingRule::factory()->pending()->create();

    DB::table('platform_sms_billing_rules')->where('id', $rule->id)->update([
        'cancelled_at' => now(),
        'cancelled_by_user_id' => $rule->created_by_user_id,
        'cancellation_reason' => 'Withdrawn before taking effect.',
    ]);

    expect($rule->refresh()->cancelled_at)->not->toBeNull();

    // Terminal: a second cancellation is refused.
    ui08ExpectGuardViolation(fn () => DB::table('platform_sms_billing_rules')->where('id', $rule->id)->update([
        'cancelled_at' => now()->addMinute(),
    ]));
});

it('refuses two rules at the same effective instant', function (): void {
    $instant = CarbonImmutable::now()->addMonth()->startOfSecond();
    PlatformSmsBillingRule::factory()->create(['effective_from' => $instant]);

    ui08ExpectGuardViolation(fn () => PlatformSmsBillingRule::factory()->create(['effective_from' => $instant]));
});

it('refuses a negative unit cost and an out-of-range tax rate', function (): void {
    ui08ExpectGuardViolation(fn () => PlatformSmsBillingRule::factory()->create(['unit_cost_minor' => -1]));
    ui08ExpectGuardViolation(fn () => PlatformSmsBillingRule::factory()->create(['tax_basis_points' => 10001]));
});

it('refuses a half-stated cancellation', function (): void {
    $rule = PlatformSmsBillingRule::factory()->pending()->create();

    // cancelled_at without an actor or a reason must be impossible.
    ui08ExpectGuardViolation(fn () => DB::table('platform_sms_billing_rules')->where('id', $rule->id)->update([
        'cancelled_at' => now(),
    ]));
});

it('never re-prices a charged SMS billing entry when a new rule is scheduled', function (): void {
    // A charge that was already snapshotted at 100 per unit.
    $entry = SmsBillingEntry::factory()->billable()->create([
        'quantity' => 12,
        'unit_cost_minor' => 100,
        'amount_minor' => 1200,
    ]);

    // Schedule a very different price, effective immediately.
    PlatformSmsBillingRule::factory()->create([
        'unit_cost_minor' => 5_100,
        'effective_from' => CarbonImmutable::now()->subSecond(),
    ]);

    // The snapshot is untouched — the rule is resolved at the usage instant and stored once.
    expect($entry->refresh()->unit_cost_minor)->toBe(100)
        ->and($entry->amount_minor)->toBe(1200);

    // And it cannot be rewritten even by bypassing every application layer.
    ui08ExpectGuardViolation(fn () => DB::table('sms_billing_entries')->where('id', $entry->id)->update([
        'unit_cost_minor' => 5_100,
    ]));

    expect($entry->refresh()->unit_cost_minor)->toBe(100);
});
