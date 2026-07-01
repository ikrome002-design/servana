<?php

declare(strict_types=1);

namespace App\Domain\Payments\ValueObjects;

use App\Domain\Payments\Models\PaymentReferenceCheck;

/**
 * The result of a durable duplicate-reference check for one component (Plan §41,
 * Gate C; Phase 18A). Either a clean `unique` reservation, or a durable
 * `duplicate_suspected` hold that carries the masked matched-reference suffix for a
 * safe conflict response — never the full/normalized reference.
 */
final readonly class DuplicateCheckOutcome
{
    private function __construct(
        public bool $isDuplicate,
        public PaymentReferenceCheck $check,
        public ?int $matchedRecordId,
        public ?string $maskedReference,
    ) {}

    public static function unique(PaymentReferenceCheck $check): self
    {
        return new self(false, $check, null, null);
    }

    public static function duplicate(PaymentReferenceCheck $check, int $matchedRecordId, ?string $maskedReference): self
    {
        return new self(true, $check, $matchedRecordId, $maskedReference);
    }
}
