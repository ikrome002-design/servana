<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\PromotionalDiscountType;
use App\Domain\Billing\Exceptions\PromotionCurrencyMismatchException;
use App\Domain\Billing\Models\PromotionalDiscount;

/**
 * Computes the applied discount (integer minor units) for a resolved promotion against a subscription-
 * invoice subtotal (Plan §53; ADR-005; Gate C5; Phase 20C). Server-side, integer arithmetic only:
 *
 *   - percentage: round-half-up of `subtotal * basis_points / 10000` (ADR-005), capped at the subtotal
 *     (it can never exceed it since basis points ≤ 10000);
 *   - fixed_amount: `min(configured_fixed_minor, subtotal_minor)` — the configured amount is capped at
 *     the subtotal (Gate C5); the configured promotion is never mutated, only this application is
 *     capped. The invoice currency must match the promotion currency first, else the calculation fails
 *     closed ({@see PromotionCurrencyMismatchException}) — never a silent wrong-currency discount.
 *
 * The result satisfies `0 <= applied <= subtotal`, so the invoice total (`subtotal - applied`) is never
 * negative and never a merchant credit. The caller snapshots both the configured value
 * (`promotion_value_snapshot`) and this applied amount (`discount_minor`).
 */
final class CalculatePromotionalDiscount
{
    public function calculate(PromotionalDiscount $discount, int $subtotalMinor, string $currency): int
    {
        if ($subtotalMinor <= 0) {
            return 0;
        }

        return match ($discount->type) {
            PromotionalDiscountType::Percentage => min($this->roundHalfUp($subtotalMinor, $discount->value), $subtotalMinor),
            PromotionalDiscountType::FixedAmount => $this->fixed($discount, $subtotalMinor, $currency),
        };
    }

    private function fixed(PromotionalDiscount $discount, int $subtotalMinor, string $currency): int
    {
        $promotionCurrency = (string) $discount->currency;
        if ($promotionCurrency !== strtoupper($currency)) {
            throw PromotionCurrencyMismatchException::between($promotionCurrency, strtoupper($currency));
        }

        // Gate C5 — cap the configured fixed amount at the subtotal (never negative, never a credit).
        return min($discount->value, $subtotalMinor);
    }

    /** Round-half-up of basis * basisPoints / 10000 to integer minor units (ADR-005; basis >= 0). */
    private function roundHalfUp(int $basisMinor, int $basisPoints): int
    {
        return intdiv($basisMinor * $basisPoints + 5000, 10000);
    }
}
