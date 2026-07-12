<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Parent target scope shared by promotional discounts and free-period offers
 * (Plan §53; Phase 20C). `all_new_merchants` is global reach and carries NO target
 * rows; the other three scopes require one or more matching normalized target rows
 * ({@see PromotionTargetType}). Mirrored across the PHP enum, the PostgreSQL CHECK on
 * `{promotional_discounts,free_period_offers}.target_scope`, factories, request
 * validation/OpenAPI/TS, and audit context. Parity guarded by `Phase20CEnumParityTest`.
 */
enum PromotionTargetScope: string
{
    case AllNewMerchants = 'all_new_merchants';
    case SelectedMerchants = 'selected_merchants';
    case SelectedPlans = 'selected_plans';
    case BillingMode = 'billing_mode';

    /**
     * All backing values, in canonical order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** Whether this scope requires one or more explicit target rows. */
    public function requiresTargets(): bool
    {
        return $this !== self::AllNewMerchants;
    }

    /** The single target type valid under this scope, or null for the global scope. */
    public function targetType(): ?PromotionTargetType
    {
        return match ($this) {
            self::AllNewMerchants => null,
            self::SelectedMerchants => PromotionTargetType::Merchant,
            self::SelectedPlans => PromotionTargetType::Plan,
            self::BillingMode => PromotionTargetType::BillingMode,
        };
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::AllNewMerchants => 'All new merchants',
            self::SelectedMerchants => 'Selected merchants',
            self::SelectedPlans => 'Selected plans',
            self::BillingMode => 'Billing mode',
        };
    }
}
