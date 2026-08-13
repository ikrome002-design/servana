<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Branch-context invoice summary. Client identity, snapshots, internal ids and all
 * Finance/Front-Office mutation capabilities are deliberately absent.
 *
 * @mixin Invoice
 */
final class BranchInvoiceVisibilityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status->value,
            'total_minor' => $this->total_minor,
            'validated_paid_minor' => $this->validated_paid_minor,
            'balance_minor' => $this->balanceMinor(),
            'currency' => $this->currency,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'create' => false,
                'update' => false,
                'finalize' => false,
                'void' => false,
                'adjust' => false,
            ],
        ];
    }
}
