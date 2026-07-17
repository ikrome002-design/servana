<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Service applicability of a commission rule (Plan §59; Scope §12.7 "Applies to";
 * Phase 20F). Mirrors the PostgreSQL CHECK on `commission_rules.applies_to`; parity
 * guarded by `Phase20FEnumParityTest`.
 *
 * `service_category` binds `service_category_id` (DB applies-to CHECK); the other two
 * keep it NULL.
 */
enum CommissionAppliesTo: string
{
    case AllServices = 'all_services';
    case SelectedServices = 'selected_services';
    case ServiceCategory = 'service_category';

    /**
     * All backing values, canonical order — authoritative for the DB CHECK and parity.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }

    /** This applicability binds a service category (DB CHECK). */
    public function requiresServiceCategory(): bool
    {
        return $this === self::ServiceCategory;
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::AllServices => 'All services',
            self::SelectedServices => 'Selected services',
            self::ServiceCategory => 'Service category',
        };
    }
}
