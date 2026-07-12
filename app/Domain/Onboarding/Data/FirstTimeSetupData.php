<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Data;

use App\Domain\Merchants\Enums\ServiceFeeTier;
use Illuminate\Support\Str;

/**
 * Validated first-time setup payload (Scope §3.2 steps 1–5).
 *
 * Built from the validated CompleteFirstTimeSetupRequest so the action receives
 * typed values (Larastan level 8) rather than a loose array. Emails are
 * normalized here so persistence and de-duplication are consistent.
 */
final class FirstTimeSetupData
{
    public function __construct(
        public readonly ServiceFeeTier $serviceFeeTier,
        public readonly string $subscriptionPlanUlid,
        public readonly string $subscriptionPlanPriceUlid,
        public readonly string $businessCategory,
        public readonly string $contactPhone,
        public readonly ?string $contactEmail,
        public readonly ?string $receiptDisplayName,
        public readonly ?string $address,
        public readonly ?string $town,
        public readonly string $timezone,
        public readonly string $branchName,
        public readonly string $branchCode,
        public readonly ?string $branchTown,
        public readonly ?string $branchAddress,
        public readonly ?string $branchPhone,
        public readonly ?string $branchEmail,
        public readonly string $branchManagerEmail,
        public readonly string $hrEmail,
    ) {}

    /**
     * @param  array<string, mixed>  $v
     */
    public static function fromArray(array $v): self
    {
        return new self(
            serviceFeeTier: ServiceFeeTier::from((string) $v['service_fee_tier']),
            subscriptionPlanUlid: (string) $v['subscription_plan_ulid'],
            subscriptionPlanPriceUlid: (string) $v['subscription_plan_price_ulid'],
            businessCategory: (string) $v['business_category'],
            contactPhone: (string) $v['contact_phone'],
            contactEmail: self::nullableString($v['contact_email'] ?? null),
            receiptDisplayName: self::nullableString($v['receipt_display_name'] ?? null),
            address: self::nullableString($v['address'] ?? null),
            town: self::nullableString($v['town'] ?? null),
            timezone: (string) ($v['timezone'] ?? 'Africa/Nairobi'),
            branchName: (string) $v['branch']['name'],
            branchCode: Str::upper((string) $v['branch']['code']),
            branchTown: self::nullableString($v['branch']['town'] ?? null),
            branchAddress: self::nullableString($v['branch']['address'] ?? null),
            branchPhone: self::nullableString($v['branch']['phone'] ?? null),
            branchEmail: self::normalizeEmail($v['branch']['email'] ?? null),
            branchManagerEmail: (string) self::normalizeEmail($v['branch_manager_email']),
            hrEmail: (string) self::normalizeEmail($v['hr_email']),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function normalizeEmail(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::lower(trim((string) $value));
    }
}
