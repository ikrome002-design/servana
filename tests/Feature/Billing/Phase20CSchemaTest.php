<?php

declare(strict_types=1);

use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantOwnership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('billing', 'phase20c', 'phase20c-schema');

/*
 | Phase 20C DB invariants (Plan §53). Four platform-scoped tables + two forward-only snapshot
 | expands. One deliberately failing write per isolated test (PostgreSQL aborts the transaction on a
 | constraint violation). Parents (users/merchants/plans) are built with factories; the 20C rows use
 | raw DB::table inserts so invalid values can be exercised directly (proving DB-level enforcement).
 */

function p20cUserId(): int
{
    return User::factory()->create()->id;
}

/** @param array<string,mixed> $overrides */
function p20cDiscountRow(array $overrides = []): array
{
    return array_merge([
        'ulid' => (string) Str::ulid(),
        'name' => 'Promo '.Str::random(6),
        'type' => 'percentage',
        'value' => 1000,
        'currency' => null,
        'target_scope' => 'all_new_merchants',
        'effective_from' => today()->toDateString(),
        'effective_to' => null,
        'status' => 'draft',
        'created_by' => p20cUserId(),
        'approved_by' => null,
        'approved_at' => null,
        'change_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function p20cInsertDiscount(array $overrides = []): int
{
    return (int) DB::table('promotional_discounts')->insertGetId(p20cDiscountRow($overrides));
}

/** @param array<string,mixed> $overrides */
function p20cOfferRow(array $overrides = []): array
{
    return array_merge([
        'ulid' => (string) Str::ulid(),
        'name' => 'Free '.Str::random(6),
        'free_period_days' => 30,
        'target_scope' => 'all_new_merchants',
        'effective_from' => today()->toDateString(),
        'effective_to' => null,
        'status' => 'draft',
        'created_by' => p20cUserId(),
        'approved_by' => null,
        'approved_at' => null,
        'change_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function p20cInsertOffer(array $overrides = []): int
{
    return (int) DB::table('free_period_offers')->insertGetId(p20cOfferRow($overrides));
}

/** @param array<string,mixed> $overrides */
function p20cTargetRow(int $discountId, array $overrides = []): array
{
    return array_merge([
        'ulid' => (string) Str::ulid(),
        'promotional_discount_id' => $discountId,
        'target_type' => 'merchant',
        'merchant_id' => null,
        'subscription_plan_id' => null,
        'billing_mode' => null,
        'created_at' => now(),
    ], $overrides);
}

// --- Migrations applied + classification ------------------------------------------------------

it('creates all four Phase 20C tables', function (): void {
    expect(Schema::hasTable('promotional_discounts'))->toBeTrue()
        ->and(Schema::hasTable('promotional_discount_targets'))->toBeTrue()
        ->and(Schema::hasTable('free_period_offers'))->toBeTrue()
        ->and(Schema::hasTable('free_period_offer_targets'))->toBeTrue();
});

it('classifies all four tables as platform-scoped (EXEMPT, no merchant_id)', function (): void {
    foreach (['promotional_discounts', 'promotional_discount_targets', 'free_period_offers', 'free_period_offer_targets'] as $table) {
        expect(TenantOwnership::EXEMPT)->toHaveKey($table)
            ->and(TenantOwnership::TENANT_OWNED)->not->toContain($table)
            ->and(TenantOwnership::BRANCH_OWNED)->not->toContain($table);
    }

    // Parent configuration tables carry no merchant/branch ownership columns.
    expect(Schema::hasColumn('promotional_discounts', 'merchant_id'))->toBeFalse()
        ->and(Schema::hasColumn('promotional_discounts', 'branch_id'))->toBeFalse()
        ->and(Schema::hasColumn('free_period_offers', 'merchant_id'))->toBeFalse()
        ->and(Schema::hasColumn('free_period_offers', 'branch_id'))->toBeFalse();
});

// --- promotional_discounts constraints --------------------------------------------------------

it('rejects a duplicate promotional_discount ulid', function (): void {
    $ulid = (string) Str::ulid();
    p20cInsertDiscount(['ulid' => $ulid]);
    expect(fn () => p20cInsertDiscount(['ulid' => $ulid]))->toThrow(QueryException::class);
});

it('rejects a non-positive discount value', function (): void {
    expect(fn () => p20cInsertDiscount(['value' => 0]))->toThrow(QueryException::class);
});

it('rejects a percentage discount above 100% (10000 bps)', function (): void {
    expect(fn () => p20cInsertDiscount(['type' => 'percentage', 'value' => 10001, 'currency' => null]))
        ->toThrow(QueryException::class);
});

it('rejects a percentage discount that carries a currency', function (): void {
    expect(fn () => p20cInsertDiscount(['type' => 'percentage', 'value' => 1000, 'currency' => 'KES']))
        ->toThrow(QueryException::class);
});

it('rejects a fixed-amount discount without a currency', function (): void {
    expect(fn () => p20cInsertDiscount(['type' => 'fixed_amount', 'value' => 50000, 'currency' => null]))
        ->toThrow(QueryException::class);
});

it('rejects a fixed-amount discount with a lowercase currency', function (): void {
    expect(fn () => p20cInsertDiscount(['type' => 'fixed_amount', 'value' => 50000, 'currency' => 'kes']))
        ->toThrow(QueryException::class);
});

it('accepts a valid fixed-amount discount', function (): void {
    $id = p20cInsertDiscount(['type' => 'fixed_amount', 'value' => 50000, 'currency' => 'KES']);
    expect($id)->toBeGreaterThan(0);
});

it('rejects effective_to on or before effective_from', function (): void {
    expect(fn () => p20cInsertDiscount([
        'effective_from' => today()->toDateString(),
        'effective_to' => today()->subDay()->toDateString(),
    ]))->toThrow(QueryException::class);
});

it('rejects approved_by set without approved_at', function (): void {
    expect(fn () => p20cInsertDiscount([
        'status' => 'scheduled',
        'approved_by' => p20cUserId(),
        'approved_at' => null,
    ]))->toThrow(QueryException::class);
});

it('rejects a draft that already carries an approver', function (): void {
    expect(fn () => p20cInsertDiscount([
        'status' => 'draft',
        'approved_by' => p20cUserId(),
        'approved_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a scheduled discount without an approver', function (): void {
    expect(fn () => p20cInsertDiscount([
        'status' => 'scheduled',
        'approved_by' => null,
        'approved_at' => null,
    ]))->toThrow(QueryException::class);
});

it('accepts a cancelled discount reached from draft (no approver)', function (): void {
    $id = p20cInsertDiscount(['status' => 'cancelled', 'approved_by' => null, 'approved_at' => null]);
    expect($id)->toBeGreaterThan(0);
});

// --- free_period_offers constraints -----------------------------------------------------------

it('rejects free_period_days below 1', function (): void {
    expect(fn () => p20cInsertOffer(['free_period_days' => 0]))->toThrow(QueryException::class);
});

it('rejects free_period_days above 365', function (): void {
    expect(fn () => p20cInsertOffer(['free_period_days' => 366]))->toThrow(QueryException::class);
});

it('accepts free_period_days at the boundaries', function (): void {
    expect(p20cInsertOffer(['free_period_days' => 1]))->toBeGreaterThan(0)
        ->and(p20cInsertOffer(['free_period_days' => 365]))->toBeGreaterThan(0);
});

// --- promotional_discount_targets constraints -------------------------------------------------

it('rejects a target with two target fields set', function (): void {
    $discountId = p20cInsertDiscount(['target_scope' => 'selected_merchants']);
    $merchantId = Merchant::factory()->create()->id;
    $planId = SubscriptionPlan::factory()->create()->id;

    expect(fn () => DB::table('promotional_discount_targets')->insert(
        p20cTargetRow($discountId, ['target_type' => 'merchant', 'merchant_id' => $merchantId, 'subscription_plan_id' => $planId])
    ))->toThrow(QueryException::class);
});

it('rejects a target whose field does not match its target_type', function (): void {
    $discountId = p20cInsertDiscount(['target_scope' => 'selected_merchants']);
    $planId = SubscriptionPlan::factory()->create()->id;

    // target_type=merchant but only a plan id is set.
    expect(fn () => DB::table('promotional_discount_targets')->insert(
        p20cTargetRow($discountId, ['target_type' => 'merchant', 'subscription_plan_id' => $planId])
    ))->toThrow(QueryException::class);
});

it('rejects an invalid billing_mode target value', function (): void {
    $discountId = p20cInsertDiscount(['target_scope' => 'billing_mode']);

    expect(fn () => DB::table('promotional_discount_targets')->insert(
        p20cTargetRow($discountId, ['target_type' => 'billing_mode', 'billing_mode' => 'not_a_mode'])
    ))->toThrow(QueryException::class);
});

it('rejects duplicate merchant targets under the same discount', function (): void {
    $discountId = p20cInsertDiscount(['target_scope' => 'selected_merchants']);
    $merchantId = Merchant::factory()->create()->id;

    DB::table('promotional_discount_targets')->insert(
        p20cTargetRow($discountId, ['target_type' => 'merchant', 'merchant_id' => $merchantId])
    );

    expect(fn () => DB::table('promotional_discount_targets')->insert(
        p20cTargetRow($discountId, ['target_type' => 'merchant', 'merchant_id' => $merchantId])
    ))->toThrow(QueryException::class);
});

it('accepts two different merchant targets under the same discount', function (): void {
    $discountId = p20cInsertDiscount(['target_scope' => 'selected_merchants']);
    $a = Merchant::factory()->create()->id;
    $b = Merchant::factory()->create()->id;

    DB::table('promotional_discount_targets')->insert(p20cTargetRow($discountId, ['target_type' => 'merchant', 'merchant_id' => $a]));
    DB::table('promotional_discount_targets')->insert(p20cTargetRow($discountId, ['target_type' => 'merchant', 'merchant_id' => $b]));

    expect(DB::table('promotional_discount_targets')->where('promotional_discount_id', $discountId)->count())->toBe(2);
});

it('rejects duplicate billing_mode targets under the same discount', function (): void {
    $discountId = p20cInsertDiscount(['target_scope' => 'billing_mode']);

    DB::table('promotional_discount_targets')->insert(
        p20cTargetRow($discountId, ['target_type' => 'billing_mode', 'billing_mode' => 'fixed_amount'])
    );

    expect(fn () => DB::table('promotional_discount_targets')->insert(
        p20cTargetRow($discountId, ['target_type' => 'billing_mode', 'billing_mode' => 'fixed_amount'])
    ))->toThrow(QueryException::class);
});

it('restricts deletion of a discount referenced by a target', function (): void {
    $discountId = p20cInsertDiscount(['target_scope' => 'selected_merchants']);
    $merchantId = Merchant::factory()->create()->id;
    DB::table('promotional_discount_targets')->insert(
        p20cTargetRow($discountId, ['target_type' => 'merchant', 'merchant_id' => $merchantId])
    );

    expect(fn () => DB::table('promotional_discounts')->where('id', $discountId)->delete())
        ->toThrow(QueryException::class);
});

// --- Snapshot-expand coherence ----------------------------------------------------------------

it('rejects an incoherent promotion snapshot on subscription_invoices', function (): void {
    $invoice = SubscriptionInvoice::factory()->create();
    $discountId = p20cInsertDiscount();

    // promotional_discount_id set but promotion_type / value / resolved_at null → coherence CHECK fails.
    expect(fn () => DB::table('subscription_invoices')->where('id', $invoice->id)
        ->update(['promotional_discount_id' => $discountId]))
        ->toThrow(QueryException::class);
});

it('accepts a complete promotion snapshot on subscription_invoices', function (): void {
    $invoice = SubscriptionInvoice::factory()->create();
    $discountId = p20cInsertDiscount(['type' => 'fixed_amount', 'value' => 50000, 'currency' => 'KES']);

    DB::table('subscription_invoices')->where('id', $invoice->id)->update([
        'promotional_discount_id' => $discountId,
        'promotion_type' => 'fixed_amount',
        'promotion_value_snapshot' => 50000,
        'promotion_currency' => 'KES',
        'promotion_resolved_at' => now(),
    ]);

    expect(DB::table('subscription_invoices')->where('id', $invoice->id)->value('promotional_discount_id'))
        ->toBe($discountId);
});

it('rejects an incoherent free-period snapshot on merchant_subscriptions', function (): void {
    $subscription = MerchantSubscription::factory()->create();
    $offerId = p20cInsertOffer();

    // free_period_offer_id set but free_period_resolved_at null → coherence CHECK fails.
    expect(fn () => DB::table('merchant_subscriptions')->where('id', $subscription->id)
        ->update(['free_period_offer_id' => $offerId]))
        ->toThrow(QueryException::class);
});

it('has the promotion snapshot columns on subscription_invoices', function (): void {
    foreach (['promotional_discount_id', 'promotion_type', 'promotion_value_snapshot', 'promotion_currency', 'promotion_resolved_at'] as $col) {
        expect(Schema::hasColumn('subscription_invoices', $col))->toBeTrue("subscription_invoices.{$col} must exist");
    }
});

it('has the free-period snapshot columns on merchant_subscriptions', function (): void {
    foreach (['free_period_offer_id', 'free_period_resolved_at'] as $col) {
        expect(Schema::hasColumn('merchant_subscriptions', $col))->toBeTrue("merchant_subscriptions.{$col} must exist");
    }
});
