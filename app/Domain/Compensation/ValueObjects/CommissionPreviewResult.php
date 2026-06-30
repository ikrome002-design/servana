<?php

declare(strict_types=1);

namespace App\Domain\Compensation\ValueObjects;

use App\Domain\Compensation\Enums\CommissionPreviewStatus;
use App\Support\Money;

/**
 * Immutable, typed result of a service-session completion commission **preview**
 * (Plan §80 Phase 16C; Gate D). This is a preview ONLY — it is never earned,
 * validated, or payable, and producing it never creates or updates a
 * `commission_ledger`, `commission_rules`, compensation plan, or payout liability.
 *
 * `earned` and `payable` are ALWAYS false in Phase 16C. A calculated `amount` may be
 * present only when an authoritative compensation configuration genuinely exists
 * (none does yet — Phases 20F/20G own that); "not configured" is never represented
 * as a zero amount. Only validated payment in the later payment/compensation
 * workflow may create earned commission.
 */
final class CommissionPreviewResult
{
    private function __construct(
        public readonly CommissionPreviewStatus $previewStatus,
        public readonly ?string $reason,
        public readonly bool $earned,
        public readonly bool $payable,
        public readonly ?Money $amount,
    ) {}

    /** No authoritative compensation configuration exists yet (Phases 20F/20G). */
    public static function notConfigured(): self
    {
        return new self(
            CommissionPreviewStatus::NotConfigured,
            'compensation_not_configured',
            earned: false,
            payable: false,
            amount: null,
        );
    }

    /** The personnel member is salary-only — commission does not apply. */
    public static function notApplicable(): self
    {
        return new self(
            CommissionPreviewStatus::NotApplicable,
            'salary_only',
            earned: false,
            payable: false,
            amount: null,
        );
    }

    /** The preview cannot be produced for another safe, explicit reason. */
    public static function unavailable(string $reason): self
    {
        return new self(
            CommissionPreviewStatus::Unavailable,
            $reason,
            earned: false,
            payable: false,
            amount: null,
        );
    }

    /**
     * A calculated preview amount (only when authoritative config exists). The
     * amount is for display under "Preview — not earned or payable" wording; it is
     * never earned or payable in Phase 16C.
     */
    public static function calculated(Money $amount): self
    {
        return new self(
            CommissionPreviewStatus::Available,
            null,
            earned: false,
            payable: false,
            amount: $amount,
        );
    }

    /** @return array<string, mixed> safe serialisation for API/audit (no ledger semantics). */
    public function toArray(): array
    {
        return [
            'preview_status' => $this->previewStatus->value,
            'reason' => $this->reason,
            'earned' => $this->earned,
            'payable' => $this->payable,
            'amount_minor' => $this->amount?->minorUnits,
            'currency' => $this->amount?->currency->value,
        ];
    }
}
