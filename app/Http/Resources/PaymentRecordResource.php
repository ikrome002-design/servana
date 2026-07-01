<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Payments\Models\PaymentRecord;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Component payment payload (Plan §13.8, §41; Phase 18A). Exposes the ULID, method,
 * integer minor-unit amount, status, paid_at, and a MASKED reference suffix only.
 * The normalized comparison key, the encrypted display value, sequential ids, and
 * the payer's contact are NEVER serialized.
 *
 * @mixin PaymentRecord
 */
final class PaymentRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = Currency::from($this->currency);

        return [
            'id' => $this->ulid,
            'method' => $this->method->value,
            'amount' => Money::ofMinor($this->amount_minor, $currency)->toArray(),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'reference_masked' => $this->maskedReference(),
            'paid_at' => $this->paid_at->toIso8601String(),
            'allocations' => $this->whenLoaded('allocations', fn (): array => $this->allocations
                ->map(fn ($allocation): array => [
                    'amount' => Money::ofMinor($allocation->amount_minor, $currency)->toArray(),
                ])->all()),
        ];
    }
}
