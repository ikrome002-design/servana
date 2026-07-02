<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Duplicate-reference check payload (Plan §13.15, §41; Phase 18A). Exposes the ULID,
 * method, result, a MASKED reference suffix (of the record under check), the matched
 * record's ULID where present, and — for an override — a safe boolean plus the
 * sanitized reason. The normalized reference, encrypted values, and sequential ids
 * are NEVER serialized.
 *
 * @mixin PaymentReferenceCheck
 */
final class PaymentReferenceCheckResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'method' => $this->method->value,
            'result' => $this->result->value,
            'reference_masked' => $this->whenLoaded('record', function (): ?string {
                /** @var PaymentRecord $record */
                $record = $this->record;

                return $record->maskedReference();
            }),
            'matched_payment_id' => $this->whenLoaded('matchedRecord', fn (): ?string => $this->matchedRecord?->ulid),
            'is_override' => $this->override_by !== null,
            'override_reason' => $this->override_reason,
            'checked_at' => $this->checked_at->toIso8601String(),
        ];
    }
}
