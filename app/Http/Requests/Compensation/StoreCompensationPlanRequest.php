<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\SalaryPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a DRAFT compensation plan (Plan §59; Scope §12.2-§12.9; Phase 20F).
 *
 * Public ULIDs only for references; the controller re-resolves them INSIDE tenant+branch scope, so
 * a foreign ULID is a 404, never a cross-tenant write. Money is integer minor units — a float
 * salary is rejected here and by the DB.
 *
 * **Server-owned fields are never accepted**: merchant_id, branch_id, status, is_backdated,
 * supersedes_plan_id, created_by/submitted_by/approved_by/rejected_by and their timestamps, ulid,
 * id and timestamps are all set by the action. `is_backdated` in particular is COMPUTED at
 * submission from the Africa/Nairobi business date (F8) — a caller can never assert it.
 */
class StoreCompensationPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staff_profile_id' => ['required', 'string', 'size:26'],
            'compensation_model' => ['required', Rule::enum(CompensationModel::class)],
            'commission_rule_id' => ['nullable', 'string', 'size:26'],
            // Integer minor units only (ADR-005) — `integer` rejects "50.25" and 50.25.
            'salary_amount_minor' => ['nullable', 'integer', 'min:1'],
            'salary_currency' => ['nullable', 'string', 'size:3', 'uppercase', 'alpha'],
            'salary_period' => ['nullable', Rule::enum(SalaryPeriod::class)],
            'salary_payout_day' => ['nullable', 'integer', 'between:1,31'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'change_reason' => ['required', 'string', 'min:2', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * F1 model shape at the request boundary. The DB CHECK remains authoritative; this only turns a
     * constraint violation into a friendly field error.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $model = CompensationModel::tryFrom((string) $this->input('compensation_model'));

            if (! $model instanceof CompensationModel) {
                return; // the enum rule already reported it
            }

            $hasSalary = $this->filled('salary_amount_minor');
            $hasRule = $this->filled('commission_rule_id');

            if ($model->requiresSalary() && ! $hasSalary) {
                $validator->errors()->add('salary_amount_minor', "A {$model->label()} plan requires a salary amount.");
            }

            if ($model->requiresSalary() && ! $this->filled('salary_currency')) {
                $validator->errors()->add('salary_currency', "A {$model->label()} plan requires a salary currency.");
            }

            if ($model->requiresSalary() && ! $this->filled('salary_period')) {
                $validator->errors()->add('salary_period', "A {$model->label()} plan requires a salary period.");
            }

            if (! $model->requiresSalary() && ($hasSalary || $this->filled('salary_currency') || $this->filled('salary_period'))) {
                $validator->errors()->add('salary_amount_minor', "A {$model->label()} plan cannot carry salary terms.");
            }

            if ($model->requiresCommissionRule() && ! $hasRule) {
                $validator->errors()->add('commission_rule_id', "A {$model->label()} plan requires a commission rule.");
            }

            if (! $model->requiresCommissionRule() && $hasRule) {
                // Plan §80 named invariant; Scope §12.5 — salary-only never earns commission.
                $validator->errors()->add('commission_rule_id', 'A salary only plan cannot reference a commission rule.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('change_reason'))) {
            $this->merge(['change_reason' => trim($this->input('change_reason'))]);
        }
    }
}
