<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use App\Domain\Compensation\Enums\EarningsQueryStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Finance respond to an earnings query — resolve or reject (Plan §63; Phase 20H, §H12; D-H12-1). Carries
 * the decision (`resolved`|`rejected`) + a mandatory resolution note, and an OPTIONAL monetary
 * `correction` that is valid only on `resolved`. A correction is created ONLY as an additive
 * `compensation_adjustment` (never a ledger edit) by the domain action; the browser supplies the amount/
 * currency/reason but never a ledger id. Status/actor/adjustment linkage are server-owned. Authorization
 * (`earnings_query.respond`), MFA (group-level), and Idempotency-Key are enforced at the route.
 */
final class RespondToEarningsQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in([EarningsQueryStatus::Resolved->value, EarningsQueryStatus::Rejected->value])],
            'resolution_note' => ['required', 'string', 'min:3', 'max:2000'],
            'correction' => ['sometimes', 'array'],
            'correction.amount_minor' => ['required_with:correction', 'integer', Rule::notIn([0])],
            'correction.currency' => ['required_with:correction', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'correction.reason' => ['required_with:correction', 'string', 'min:3', 'max:2000'],
            // Server-owned fields are never accepted from the client.
            'status' => ['prohibited'],
            'responded_by' => ['prohibited'],
            'responded_at' => ['prohibited'],
            'resolved_adjustment_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A monetary correction is only meaningful on a RESOLVE — never on a rejection.
            if (is_array($this->input('correction')) && $this->input('decision') !== EarningsQueryStatus::Resolved->value) {
                $validator->errors()->add('correction', 'A monetary correction may only accompany a resolved decision.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('resolution_note'))) {
            $this->merge(['resolution_note' => trim($this->input('resolution_note'))]);
        }
    }
}
