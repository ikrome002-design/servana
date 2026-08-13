<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Branch-context payment status summary. Concrete component references and maker/
 * validator identity never cross this boundary.
 *
 * @mixin PaymentRecordingGroup
 */
final class BranchPaymentVisibilityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'invoice' => $this->whenLoaded('invoice', function (): ?array {
                /** @var Invoice|null $invoice */
                $invoice = $this->invoice;

                return $invoice === null ? null : [
                    'id' => $invoice->ulid,
                    'invoice_number' => $invoice->invoice_number,
                ];
            }),
            'status' => $this->status->value,
            'total_amount_minor' => $this->total_amount_minor,
            'currency' => $this->currency,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'submitted_for_validation_at' => $this->submitted_for_validation_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'record' => false,
                'validate' => false,
                'reject' => false,
                'correct_reference' => false,
            ],
        ];
    }
}
