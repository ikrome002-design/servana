<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Compensation\Models\PersonnelPayoutItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 20H payout-item masked read (Plan §62). Exposes the item ULID, public staff reference, currency,
 * the bucketed signed integer amounts (salary/commission/adjustment) and gross, the status, and a
 * boolean statement-availability flag with the statement file's public ULID (own-scope download via the
 * 10F file endpoints). The raw `source_ledger_refs` (internal ledger row ids) are NEVER exposed — only
 * per-bucket counts. Money is integer minor units (ADR-005).
 *
 * @mixin PersonnelPayoutItem
 */
final class PersonnelPayoutItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $refs = $this->source_ledger_refs;

        return [
            'id' => $this->ulid,
            'staff_profile_id' => $this->staffProfile === null ? null : $this->staffProfile->ulid,
            'staff_display_name' => $this->staffProfile === null ? null : $this->staffProfile->display_name,
            'payout_run_id' => $this->payoutRun === null ? null : $this->payoutRun->ulid,
            'currency' => $this->currency,
            'salary_amount_minor' => (int) $this->salary_amount_minor,
            'commission_amount_minor' => (int) $this->commission_amount_minor,
            'adjustment_amount_minor' => (int) $this->adjustment_amount_minor,
            'gross_amount_minor' => (int) $this->gross_amount_minor,
            'status' => $this->status->value,
            // Counts only — never the internal ledger row ids.
            'source_counts' => [
                'salary' => count($refs['salary'] ?? []),
                'commission' => count($refs['commission'] ?? []),
                'adjustment' => count($refs['adjustment'] ?? []),
            ],
            'has_statement' => (bool) ($this->earnings_statement_file_id !== null),
            'statement_file_id' => $this->earningsStatementFile === null ? null : $this->earningsStatementFile->ulid,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
