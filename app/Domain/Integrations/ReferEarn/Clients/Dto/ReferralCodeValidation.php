<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Clients\Dto;

/**
 * R&E's answer to "is this a usable referral code?" (Plan §58A.2; Phase 21R-A).
 *
 * Three outcomes, deliberately: R&E's own result-code vocabulary is NOT pinned in this repository
 * (see docs/integrations/refer-earn/contract-pins.md), so the client maps whatever it receives onto
 * a bounded tri-state and stores the raw `resultCode` verbatim as evidence. When the vocabulary is
 * pinned, that becomes a mapping-table change with the historical codes already on record.
 *
 * `retryable` is NOT a verdict — it means "R&E did not answer", and the snapshot stays `validating`.
 */
final readonly class ReferralCodeValidation
{
    private function __construct(
        public bool $valid,
        public bool $retryable,
        public ?string $resultCode,
    ) {}

    public static function valid(?string $resultCode = 'VALID'): self
    {
        return new self(true, false, $resultCode);
    }

    public static function invalid(?string $resultCode): self
    {
        return new self(false, false, $resultCode);
    }

    /** R&E unreachable, rate-limited, or 5xx — no verdict; retry with backoff. */
    public static function retryable(?string $resultCode = null): self
    {
        return new self(false, true, $resultCode);
    }
}
