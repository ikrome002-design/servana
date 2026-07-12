<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PromotionalDiscountType;
use App\Domain\Billing\Exceptions\PromotionCurrencyMismatchException;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Services\CalculatePromotionalDiscount;

uses()->group('billing', 'phase20c', 'phase20c-calculator');

/*
 | Phase 20C promotional-discount arithmetic (Plan §53; ADR-005; Gate C5). Integer minor units only;
 | percentage uses basis points + round-half-up; fixed is capped at the subtotal; totals never negative.
 | Pure unit test — in-memory models, no DB.
 */

function promo(PromotionalDiscountType $type, int $value, ?string $currency): PromotionalDiscount
{
    $discount = new PromotionalDiscount;
    $discount->type = $type;
    $discount->value = $value;
    $discount->currency = $currency;

    return $discount;
}

it('applies a percentage discount in basis points', function (): void {
    $calc = new CalculatePromotionalDiscount;
    // 10% of 500000 = 50000.
    expect($calc->calculate(promo(PromotionalDiscountType::Percentage, 1000, null), 500000, 'KES'))->toBe(50000);
});

it('rounds a percentage discount half-up (ADR-005)', function (): void {
    $calc = new CalculatePromotionalDiscount;
    // 10% of 5 = 0.5 minor → rounds UP to 1.
    expect($calc->calculate(promo(PromotionalDiscountType::Percentage, 1000, null), 5, 'KES'))->toBe(1);
    // 10% of 4 = 0.4 → rounds DOWN to 0.
    expect($calc->calculate(promo(PromotionalDiscountType::Percentage, 1000, null), 4, 'KES'))->toBe(0);
});

it('never lets a percentage discount exceed the subtotal', function (): void {
    $calc = new CalculatePromotionalDiscount;
    // 100% of 500000 = 500000 (equal, not exceeding).
    $applied = $calc->calculate(promo(PromotionalDiscountType::Percentage, 10000, null), 500000, 'KES');
    expect($applied)->toBe(500000)->toBeLessThanOrEqual(500000);
});

it('applies a fixed discount below the subtotal in full', function (): void {
    $calc = new CalculatePromotionalDiscount;
    expect($calc->calculate(promo(PromotionalDiscountType::FixedAmount, 20000, 'KES'), 500000, 'KES'))->toBe(20000);
});

it('produces a zero total when a fixed discount equals the subtotal', function (): void {
    $calc = new CalculatePromotionalDiscount;
    $subtotal = 500000;
    $applied = $calc->calculate(promo(PromotionalDiscountType::FixedAmount, $subtotal, 'KES'), $subtotal, 'KES');
    expect($applied)->toBe($subtotal)
        ->and($subtotal - $applied)->toBe(0);
});

it('caps an oversized fixed discount at the subtotal (Gate C5)', function (): void {
    $calc = new CalculatePromotionalDiscount;
    $subtotal = 500000;
    // Configured 900000 > subtotal → applied capped at 500000; total floors at 0, never negative.
    $applied = $calc->calculate(promo(PromotionalDiscountType::FixedAmount, 900000, 'KES'), $subtotal, 'KES');
    expect($applied)->toBe($subtotal)
        ->and($subtotal - $applied)->toBe(0)
        ->and($subtotal - $applied)->toBeGreaterThanOrEqual(0);
});

it('rejects a fixed discount whose currency differs from the invoice', function (): void {
    $calc = new CalculatePromotionalDiscount;
    expect(fn () => $calc->calculate(promo(PromotionalDiscountType::FixedAmount, 20000, 'USD'), 500000, 'KES'))
        ->toThrow(PromotionCurrencyMismatchException::class);
});

it('returns zero for a non-positive subtotal', function (): void {
    $calc = new CalculatePromotionalDiscount;
    expect($calc->calculate(promo(PromotionalDiscountType::Percentage, 1000, null), 0, 'KES'))->toBe(0)
        ->and($calc->calculate(promo(PromotionalDiscountType::FixedAmount, 20000, 'KES'), 0, 'KES'))->toBe(0);
});

it('always keeps 0 <= applied <= subtotal across a sweep', function (): void {
    $calc = new CalculatePromotionalDiscount;
    foreach ([1, 99, 100, 12345, 500000, 999999] as $subtotal) {
        foreach ([1, 2500, 5000, 9999, 10000] as $bps) {
            $applied = $calc->calculate(promo(PromotionalDiscountType::Percentage, $bps, null), $subtotal, 'KES');
            expect($applied)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($subtotal);
        }
        foreach ([1, $subtotal, $subtotal + 1, $subtotal * 2] as $fixed) {
            $applied = $calc->calculate(promo(PromotionalDiscountType::FixedAmount, max(1, $fixed), 'KES'), $subtotal, 'KES');
            expect($applied)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($subtotal);
        }
    }
});
