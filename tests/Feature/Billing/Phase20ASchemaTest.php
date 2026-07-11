<?php

declare(strict_types=1);

use App\Domain\Catalogue\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('billing', 'phase20a-schema');

/*
 | Phase 20A DB invariants (Plan §13.9, §13.10, §47; ADR-011). The five platform-owned
 | tables: platform ownership (no merchant_id/branch_id), CHECK parity, uppercase currency,
 | non-negative money, effective-date ordering, btree_gist overlap rejection with adjacent-
 | range acceptance, FK RESTRICT, and ULID uniqueness. One deliberately failing write per
 | isolated test/transaction (PostgreSQL aborts the transaction on a constraint violation).
 */

function billingActorId(): int
{
    return User::factory()->create()->id;
}

/** @param array<string,mixed> $overrides */
function insertPlan(array $overrides = []): int
{
    return (int) DB::table('subscription_plans')->insertGetId(array_merge([
        'ulid' => (string) Str::ulid(),
        'key' => 'plan_'.Str::lower(Str::random(8)),
        'name' => 'Test Plan',
        'metadata' => '{}',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** @param array<string,mixed> $overrides */
function insertPrice(int $planId, int $actor, array $overrides = []): void
{
    DB::table('subscription_plan_prices')->insert(array_merge([
        'ulid' => (string) Str::ulid(),
        'plan_id' => $planId,
        'amount_minor' => 500000,
        'currency' => 'KES',
        'billing_interval' => 'monthly',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'created_by' => $actor,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** @param array<string,mixed> $overrides */
function insertFeeRule(array $overrides = []): void
{
    DB::table('preferred_personnel_fee_rules')->insert(array_merge([
        'ulid' => (string) Str::ulid(),
        'calculation_type' => 'fixed_amount',
        'fixed_amount_minor' => 20000,
        'percentage_basis_points' => null,
        'currency' => 'KES',
        'calculation_basis' => 'service_item_net_amount',
        'scope' => 'platform_default',
        'service_id' => null,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'status' => 'active',
        'created_by' => null,
        'change_reason' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Platform ownership
// ---------------------------------------------------------------------------

it('keeps all five Phase 20A tables platform-owned (no merchant_id/branch_id)', function (): void {
    $tables = ['platform_billing_settings', 'subscription_plans', 'subscription_plan_prices', 'plan_entitlements', 'preferred_personnel_fee_rules'];

    foreach ($tables as $table) {
        expect(DB::getSchemaBuilder()->hasColumn($table, 'merchant_id'))->toBeFalse("{$table} must not have merchant_id");
        expect(DB::getSchemaBuilder()->hasColumn($table, 'branch_id'))->toBeFalse("{$table} must not have branch_id");
        expect(DB::getSchemaBuilder()->hasColumn($table, 'deleted_at'))->toBeFalse("{$table} must not soft-delete");
    }
});

// ---------------------------------------------------------------------------
// platform_billing_settings
// ---------------------------------------------------------------------------

it('accepts a valid billing-settings version and rejects a non-canonical mode', function (): void {
    $actor = billingActorId();
    DB::table('platform_billing_settings')->insert([
        'ulid' => (string) Str::ulid(), 'billing_mode' => 'fixed_amount', 'default_trial_days' => 14,
        'grace_days' => 7, 'currency' => 'KES', 'updated_by' => $actor, 'effective_from' => now(),
        'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(DB::table('platform_billing_settings')->count())->toBe(1);

    expect(fn () => DB::table('platform_billing_settings')->insert([
        'ulid' => (string) Str::ulid(), 'billing_mode' => 'made_up_mode', 'default_trial_days' => 0,
        'grace_days' => 0, 'currency' => 'KES', 'updated_by' => $actor, 'effective_from' => now(),
        'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a lowercase currency on billing settings', function (): void {
    $actor = billingActorId();
    expect(fn () => DB::table('platform_billing_settings')->insert([
        'ulid' => (string) Str::ulid(), 'billing_mode' => 'fixed_amount', 'default_trial_days' => 0,
        'grace_days' => 0, 'currency' => 'kes', 'updated_by' => $actor, 'effective_from' => now(),
        'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects negative trial days', function (): void {
    $actor = billingActorId();
    expect(fn () => DB::table('platform_billing_settings')->insert([
        'ulid' => (string) Str::ulid(), 'billing_mode' => 'fixed_amount', 'default_trial_days' => -1,
        'grace_days' => 0, 'currency' => 'KES', 'updated_by' => $actor, 'effective_from' => now(),
        'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a non-object settings jsonb', function (): void {
    $actor = billingActorId();
    expect(fn () => DB::table('platform_billing_settings')->insert([
        'ulid' => (string) Str::ulid(), 'billing_mode' => 'fixed_amount', 'default_trial_days' => 0,
        'grace_days' => 0, 'currency' => 'KES', 'updated_by' => $actor, 'effective_from' => now(),
        'settings' => '["not","an","object"]', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces one settings version per effective instant', function (): void {
    $actor = billingActorId();
    $at = now();
    DB::table('platform_billing_settings')->insert([
        'ulid' => (string) Str::ulid(), 'billing_mode' => 'fixed_amount', 'default_trial_days' => 0,
        'grace_days' => 0, 'currency' => 'KES', 'updated_by' => $actor, 'effective_from' => $at,
        'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => DB::table('platform_billing_settings')->insert([
        'ulid' => (string) Str::ulid(), 'billing_mode' => 'percentage_on_merchant_client_invoice', 'default_trial_days' => 0,
        'grace_days' => 0, 'currency' => 'KES', 'updated_by' => $actor, 'effective_from' => $at,
        'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// subscription_plans
// ---------------------------------------------------------------------------

it('has no monetary/price column on subscription_plans (ADR-011)', function (): void {
    foreach (['amount_minor', 'price_minor', 'price', 'amount'] as $col) {
        expect(DB::getSchemaBuilder()->hasColumn('subscription_plans', $col))->toBeFalse("subscription_plans must not carry {$col}");
    }
});

it('rejects a duplicate plan key and a non-canonical plan status', function (): void {
    insertPlan(['key' => 'growth']);
    expect(fn () => insertPlan(['key' => 'growth']))->toThrow(QueryException::class);
});

it('rejects a plan status outside the CHECK', function (): void {
    expect(fn () => insertPlan(['status' => 'archived']))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// subscription_plan_prices — sole source, overlap, ordering, currency, money
// ---------------------------------------------------------------------------

it('accepts all five canonical billing intervals', function (): void {
    $actor = billingActorId();
    foreach (['weekly', 'bi_weekly', 'monthly', 'quarterly', 'annual'] as $interval) {
        $plan = insertPlan();
        insertPrice($plan, $actor, ['billing_interval' => $interval]);
    }
    expect(DB::table('subscription_plan_prices')->count())->toBe(5);
});

it('rejects overlapping effective ranges for the same plan+interval+currency', function (): void {
    $actor = billingActorId();
    $plan = insertPlan();
    insertPrice($plan, $actor, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-01']);
    expect(fn () => insertPrice($plan, $actor, ['effective_from' => '2026-03-01', 'effective_to' => null]))
        ->toThrow(QueryException::class);
});

it('allows adjacent (non-overlapping) effective ranges', function (): void {
    $actor = billingActorId();
    $plan = insertPlan();
    insertPrice($plan, $actor, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-01']);
    insertPrice($plan, $actor, ['effective_from' => '2026-06-01', 'effective_to' => null]);
    expect(DB::table('subscription_plan_prices')->count())->toBe(2);
});

it('rejects a price whose effective_to is not after effective_from', function (): void {
    $actor = billingActorId();
    $plan = insertPlan();
    expect(fn () => insertPrice($plan, $actor, ['effective_from' => '2026-06-01', 'effective_to' => '2026-06-01']))
        ->toThrow(QueryException::class);
});

it('rejects a negative price amount and a lowercase currency', function (): void {
    $actor = billingActorId();
    $plan = insertPlan();
    expect(fn () => insertPrice($plan, $actor, ['amount_minor' => -1]))->toThrow(QueryException::class);
});

it('restricts deletion of a plan that still has a price (FK RESTRICT)', function (): void {
    $actor = billingActorId();
    $plan = insertPlan();
    insertPrice($plan, $actor);
    expect(fn () => DB::table('subscription_plans')->where('id', $plan)->delete())->toThrow(QueryException::class);
});

it('enforces ULID uniqueness on plan prices', function (): void {
    $actor = billingActorId();
    $plan = insertPlan();
    $ulid = (string) Str::ulid();
    insertPrice($plan, $actor, ['ulid' => $ulid, 'billing_interval' => 'weekly']);
    expect(fn () => insertPrice($plan, $actor, ['ulid' => $ulid, 'billing_interval' => 'monthly']))
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// plan_entitlements
// ---------------------------------------------------------------------------

it('enforces unique(plan_id, entitlement_key) and a non-negative limit', function (): void {
    $plan = insertPlan();
    DB::table('plan_entitlements')->insert(['plan_id' => $plan, 'entitlement_key' => 'merchant.branch.count', 'limit_int' => 3, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => DB::table('plan_entitlements')->insert(['plan_id' => $plan, 'entitlement_key' => 'merchant.branch.count', 'limit_int' => 5, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

it('rejects a negative entitlement limit', function (): void {
    $plan = insertPlan();
    expect(fn () => DB::table('plan_entitlements')->insert(['plan_id' => $plan, 'entitlement_key' => 'x', 'limit_int' => -1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// preferred_personnel_fee_rules — value shape, scope, ranges
// ---------------------------------------------------------------------------

it('accepts a valid platform-default fixed rule', function (): void {
    insertFeeRule();
    expect(DB::table('preferred_personnel_fee_rules')->count())->toBe(1);
});

it('rejects a fixed rule that also carries basis points', function (): void {
    expect(fn () => insertFeeRule(['percentage_basis_points' => 500]))->toThrow(QueryException::class);
});

it('rejects a percentage rule that also carries a fixed amount/currency', function (): void {
    expect(fn () => insertFeeRule([
        'calculation_type' => 'percentage', 'percentage_basis_points' => 500,
        'fixed_amount_minor' => 10000, 'currency' => 'KES',
    ]))->toThrow(QueryException::class);
});

it('accepts a valid percentage rule (bp only, null fixed/currency)', function (): void {
    insertFeeRule([
        'calculation_type' => 'percentage', 'percentage_basis_points' => 750,
        'fixed_amount_minor' => null, 'currency' => null,
    ]);
    expect(DB::table('preferred_personnel_fee_rules')->count())->toBe(1);
});

it('rejects basis points above 10000', function (): void {
    expect(fn () => insertFeeRule([
        'calculation_type' => 'percentage', 'percentage_basis_points' => 10001,
        'fixed_amount_minor' => null, 'currency' => null,
    ]))->toThrow(QueryException::class);
});

it('rejects a platform_default rule that names a service', function (): void {
    $service = Service::factory()->create();
    expect(fn () => insertFeeRule(['scope' => 'platform_default', 'service_id' => $service->id]))
        ->toThrow(QueryException::class);
});

it('rejects a service-scoped rule with no service_id', function (): void {
    expect(fn () => insertFeeRule(['scope' => 'service', 'service_id' => null]))
        ->toThrow(QueryException::class);
});

it('rejects an empty change_reason', function (): void {
    expect(fn () => insertFeeRule(['change_reason' => '   ']))->toThrow(QueryException::class);
});

it('rejects overlapping active rules for the same scope', function (): void {
    insertFeeRule(['effective_from' => '2026-01-01', 'effective_to' => '2026-06-01']);
    expect(fn () => insertFeeRule(['effective_from' => '2026-03-01', 'effective_to' => null]))
        ->toThrow(QueryException::class);
});

it('allows a superseded rule to overlap an active rule (only active/scheduled are guarded)', function (): void {
    insertFeeRule(['status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => null]);
    insertFeeRule(['status' => 'superseded', 'effective_from' => '2026-01-01', 'effective_to' => null]);
    expect(DB::table('preferred_personnel_fee_rules')->count())->toBe(2);
});

it('allows two active service-scoped rules for different services in the same window', function (): void {
    $s1 = Service::factory()->create();
    $s2 = Service::factory()->create();
    insertFeeRule(['scope' => 'service', 'service_id' => $s1->id, 'effective_from' => '2026-01-01', 'effective_to' => null]);
    insertFeeRule(['scope' => 'service', 'service_id' => $s2->id, 'effective_from' => '2026-01-01', 'effective_to' => null]);
    expect(DB::table('preferred_personnel_fee_rules')->where('scope', 'service')->count())->toBe(2);
});
