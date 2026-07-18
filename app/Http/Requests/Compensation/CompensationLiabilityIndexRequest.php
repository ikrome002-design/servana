<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use App\Domain\Compensation\Enums\CommissionLedgerEntryType;
use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Enums\SalaryLedgerEntryType;
use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 20G compensation-liability listing/summary filters (Plan §61/§80). Read-only, merchant-scoped
 * under `compensation.liability.view`; the policy + `EnsurePermission` are the boundary. Every filter is
 * validated; unknown filters are rejected. Tenant/branch scope is server-authoritative — a
 * client-supplied `merchant_id`/`branch_id` is never honoured (branch filtering uses the public
 * `branch_ulid`). Dates are Africa/Nairobi business dates.
 */
final class CompensationLiabilityIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $entryTypes = array_values(array_unique(array_merge(
            array_map(static fn (SalaryLedgerEntryType $t): string => $t->value, SalaryLedgerEntryType::cases()),
            array_map(static fn (CommissionLedgerEntryType $t): string => $t->value, CommissionLedgerEntryType::cases()),
        )));
        $statuses = array_values(array_unique(array_merge(
            array_map(static fn (SalaryLedgerStatus $s): string => $s->value, SalaryLedgerStatus::cases()),
            array_map(static fn (CommissionLedgerStatus $s): string => $s->value, CommissionLedgerStatus::cases()),
        )));

        return [
            'liability_type' => ['sometimes', 'string', Rule::in(['salary', 'commission'])],
            'staff_profile_ulid' => ['sometimes', 'string', 'size:26'],
            'branch_ulid' => ['sometimes', 'string', 'size:26'],
            'entry_type' => ['sometimes', 'string', Rule::in($entryTypes)],
            'status' => ['sometimes', 'string', Rule::in($statuses)],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // Server-owned scope fields are never accepted from the client.
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
        ];
    }
}
