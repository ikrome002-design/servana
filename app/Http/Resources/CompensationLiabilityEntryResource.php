<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Compensation\Services\CompensationLiabilityReadModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 20G normalized, masked compensation-liability ledger entry (salary or commission; Plan §61/§80).
 * Backed by the server-authoritative {@see CompensationLiabilityReadModel}
 * projection — every field is already safe (public ULIDs, integer minor units, staff display name). NEVER
 * exposes internal ids, private contact data, raw audit context, or idempotency keys.
 *
 * @property array<string, mixed> $resource
 */
final class CompensationLiabilityEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $r */
        $r = $this->resource;

        return [
            'id' => (string) $r['ledger_ulid'],
            'liability_type' => (string) $r['liability_type'],
            'entry_type' => (string) $r['entry_type'],
            'status' => (string) $r['status'],
            'amount_minor' => (int) $r['amount_minor'],
            'currency' => (string) $r['currency'],
            'business_date' => $r['business_date'] === null ? null : (string) $r['business_date'],
            'staff_profile_id' => $r['staff_profile_ulid'] === null ? null : (string) $r['staff_profile_ulid'],
            'staff_display_name' => $r['staff_display_name'] === null ? null : (string) $r['staff_display_name'],
            'branch_id' => $r['branch_ulid'] === null ? null : (string) $r['branch_ulid'],
            'compensation_plan_id' => $r['compensation_plan_ulid'] === null ? null : (string) $r['compensation_plan_ulid'],
            'commission_rule_id' => $r['commission_rule_ulid'] === null ? null : (string) $r['commission_rule_ulid'],
            'pay_period_start' => $r['pay_period_start'] === null ? null : (string) $r['pay_period_start'],
            'pay_period_end' => $r['pay_period_end'] === null ? null : (string) $r['pay_period_end'],
            'invoice_reference' => $r['invoice_reference'] === null ? null : (string) $r['invoice_reference'],
            'source_entry_id' => $r['source_entry_ulid'] === null ? null : (string) $r['source_entry_ulid'],
            'created_at' => $r['created_at'] === null ? null : (string) $r['created_at'],
        ];
    }
}
