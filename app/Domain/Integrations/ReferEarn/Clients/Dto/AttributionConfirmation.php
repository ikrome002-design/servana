<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Clients\Dto;

/**
 * R&E's answer to "is this merchant attributed to this code?" (Plan §58A.2, §58B.5 R-04;
 * Phase 21R-A).
 *
 * A rejection here is the ATTRIBUTION being refused — typically because another referrer is already
 * effective for this merchant — not the code being malformed. Attribution uniqueness is R&E's
 * decision, never Servana's (ADR-013).
 *
 * `attributionPublicId` is R&E's OPAQUE public identifier. It is the only R&E-side identifier
 * Servana ever stores, and it carries no referrer identity (Plan §9 rule 23).
 */
final readonly class AttributionConfirmation
{
    private function __construct(
        public bool $confirmed,
        public bool $retryable,
        public ?string $attributionPublicId,
        public ?string $resultCode,
    ) {}

    public static function confirmed(string $attributionPublicId, ?string $resultCode = 'CONFIRMED'): self
    {
        return new self(true, false, $attributionPublicId, $resultCode);
    }

    public static function rejected(?string $resultCode): self
    {
        return new self(false, false, null, $resultCode);
    }

    /** R&E unreachable or 5xx — no verdict; the snapshot stays `validated` and the job retries. */
    public static function retryable(?string $resultCode = null): self
    {
        return new self(false, true, null, $resultCode);
    }
}
