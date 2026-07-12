<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionalDiscountType;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Enums\PromotionTargetType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('billing', 'phase20c', 'phase20c-enum-parity');

/*
 | Phase 20C canonical-enum parity (Plan §53). Proves the PHP enum backing values are EXACTLY the
 | values in each PostgreSQL CHECK constraint — zero mismatch. Uniquely-named helpers avoid colliding
 | with Phase20BEnumParityTest's global functions.
 */

/** @return list<string> */
function phase20cCheckValues(string $table, string $constraint): array
{
    $rows = DB::select(
        'select pg_get_constraintdef(oid) as def from pg_constraint
         where conrelid = ?::regclass and conname = ?',
        [$table, $constraint],
    );

    expect($rows)->not->toBeEmpty("constraint {$constraint} on {$table} must exist");

    preg_match_all("/'([^']+)'/", $rows[0]->def, $matches);

    $values = array_values(array_unique($matches[1]));
    sort($values);

    return $values;
}

/** @param list<string> $enumValues */
function phase20cExpectParity(string $table, string $constraint, array $enumValues): void
{
    sort($enumValues);
    expect(phase20cCheckValues($table, $constraint))->toBe($enumValues);
}

it('promotional_discounts.type matches PromotionalDiscountType', function (): void {
    phase20cExpectParity('promotional_discounts', 'promotional_discounts_type_check', PromotionalDiscountType::values());
});

it('promotional_discounts.status matches PromotionStatus', function (): void {
    phase20cExpectParity('promotional_discounts', 'promotional_discounts_status_check', PromotionStatus::values());
});

it('promotional_discounts.target_scope matches PromotionTargetScope', function (): void {
    phase20cExpectParity('promotional_discounts', 'promotional_discounts_target_scope_check', PromotionTargetScope::values());
});

it('promotional_discount_targets.target_type matches PromotionTargetType', function (): void {
    phase20cExpectParity('promotional_discount_targets', 'promotional_discount_targets_target_type_check', PromotionTargetType::values());
});

it('promotional_discount_targets.billing_mode matches BillingMode', function (): void {
    phase20cExpectParity('promotional_discount_targets', 'promotional_discount_targets_billing_mode_check', BillingMode::values());
});

it('free_period_offers.status matches FreePeriodOfferStatus', function (): void {
    phase20cExpectParity('free_period_offers', 'free_period_offers_status_check', FreePeriodOfferStatus::values());
});

it('free_period_offers.target_scope matches PromotionTargetScope', function (): void {
    phase20cExpectParity('free_period_offers', 'free_period_offers_target_scope_check', PromotionTargetScope::values());
});

it('free_period_offer_targets.target_type matches PromotionTargetType', function (): void {
    phase20cExpectParity('free_period_offer_targets', 'free_period_offer_targets_target_type_check', PromotionTargetType::values());
});

it('free_period_offer_targets.billing_mode matches BillingMode', function (): void {
    phase20cExpectParity('free_period_offer_targets', 'free_period_offer_targets_billing_mode_check', BillingMode::values());
});

it('subscription_invoices.promotion_type matches PromotionalDiscountType', function (): void {
    phase20cExpectParity('subscription_invoices', 'subscription_invoices_promotion_type_check', PromotionalDiscountType::values());
});

it('PromotionStatus and FreePeriodOfferStatus share the same six status values', function (): void {
    expect(PromotionStatus::values())->toBe(FreePeriodOfferStatus::values())
        ->and(PromotionStatus::values())->toBe(['draft', 'scheduled', 'active', 'paused', 'expired', 'cancelled']);
});
