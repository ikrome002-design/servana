<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Masked Finance duplicate-reference review row. The normalized/full reference,
 * sequential ids, encrypted values and foreign-tenant context never leave the API.
 *
 * @mixin PaymentReferenceCheck
 */
final class FinanceDuplicateReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PaymentRecord $record */
        $record = $this->record;
        $group = $record->group;
        if ($group === null || $group->invoice === null || $group->maker === null) {
            throw new LogicException('A duplicate review must retain its payment group, invoice and maker.');
        }
        $invoice = $group->invoice;
        $maker = $group->maker;
        $matched = $this->matchedRecord;
        $matchedGroup = $matched?->group;
        if ($matched !== null && ($matchedGroup === null || $matchedGroup->invoice === null)) {
            throw new LogicException('A matched duplicate record must retain its payment group and invoice.');
        }
        $currency = Currency::from($record->currency);

        return [
            'id' => $this->ulid,
            'method' => $this->method->value,
            'result' => $this->result->value,
            'match_type' => 'exact_normalized_reference',
            'risk' => 'high',
            'reference_masked' => $record->maskedReference(),
            'amount' => Money::ofMinor($record->amount_minor, $currency)->toArray(),
            'checked_at' => $this->checked_at->toIso8601String(),
            'current' => [
                'group_id' => $group->ulid,
                'group_status' => $group->status->value,
                'invoice_id' => $invoice->ulid,
                'invoice_number' => $invoice->invoice_number,
                'recorded_by' => $maker->name,
                'recorded_at' => $group->recorded_at?->toIso8601String(),
            ],
            'conflict' => $matched === null ? null : [
                'payment_id' => $matched->ulid,
                'group_id' => $matchedGroup->ulid,
                'group_status' => $matchedGroup->status->value,
                'invoice_id' => $matchedGroup->invoice->ulid,
                'invoice_number' => $matchedGroup->invoice->invoice_number,
                'amount' => Money::ofMinor(
                    $matched->amount_minor,
                    Currency::from($matched->currency),
                )->toArray(),
                'paid_at' => $matched->paid_at->toIso8601String(),
            ],
            'can_override' => $this->override_by === null,
        ];
    }
}
