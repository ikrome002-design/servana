<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Compensation\Models\SalaryLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Own-scope append-only salary fact; the browser never calculates an accrual. */
final class PersonnelSalaryLedgerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SalaryLedgerEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->ulid,
            'entry_type' => $entry->entry_type->value,
            'status' => $entry->status->value,
            'pay_period_start' => $entry->pay_period_start->toDateString(),
            'pay_period_end' => $entry->pay_period_end->toDateString(),
            'amount_minor' => $entry->amount_minor,
            'currency' => $entry->currency,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
