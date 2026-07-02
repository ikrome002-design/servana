<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Enums\Currency;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payment recording group payload (Plan §13.15, §41; guardrail §6.4; Phase 18A).
 * Exposes the group ULID, status, integer minor-unit total, currency, the maker
 * identity (ULID + name), timestamps, the invoice public reference, the component
 * records (masked), and a capability map. It NEVER exposes a sequential id, a
 * full/normalized reference, an encrypted value, client contact, or any validated/
 * paid amount (Phase 18B). A recording is never "paid" and carries no receipt.
 *
 * @mixin PaymentRecordingGroup
 */
final class PaymentRecordingGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = Currency::from($this->currency);

        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'is_pending_validation' => $this->status === PaymentRecordingGroupStatus::PendingValidation,
            'currency' => $this->currency,
            'total' => Money::ofMinor($this->total_amount_minor, $currency)->toArray(),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'submitted_for_validation_at' => $this->submitted_for_validation_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'maker' => $this->whenLoaded('maker', function (): array {
                /** @var User $maker */
                $maker = $this->maker;

                return ['id' => $maker->ulid, 'name' => $maker->name];
            }),
            'invoice' => $this->whenLoaded('invoice', function (): array {
                /** @var Invoice $invoice */
                $invoice = $this->invoice;

                return ['id' => $invoice->ulid, 'invoice_number' => $invoice->invoice_number];
            }),
            'components' => PaymentRecordResource::collection($this->whenLoaded('records')),
            // Held duplicate-reference checks awaiting a Finance override (masked). Only
            // present when records + their reference checks are eager-loaded (detail view).
            'duplicate_checks' => $this->when(
                $this->relationLoaded('records'),
                fn (): array => $this->records
                    ->flatMap(fn ($record): array => $record->relationLoaded('referenceChecks')
                        ? $record->referenceChecks
                            ->where('result', PaymentReferenceCheckResult::DuplicateSuspected)
                            ->map(fn ($check): array => [
                                'id' => $check->ulid,
                                'method' => $check->method->value,
                                'reference_masked' => $record->maskedReference(),
                            ])->all()
                        : [])
                    ->values()
                    ->all(),
            ),
        ];
    }
}
