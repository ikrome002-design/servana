<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use App\Domain\Branches\Enums\CalendarExceptionType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new branch calendar exception (REM-SCR-002B).
 *
 * Every rule is derived from the shipped schema and the shipped runtime consumer, not invented:
 *   - `type` mirrors the `branch_calendar_exceptions_type_check` DB CHECK via the enum;
 *   - `opens_at`/`closes_at` are `time(0)` columns → `H:i` / `H:i:s`;
 *   - `modified_hours` REQUIRES both times, because
 *     AppointmentBranchScheduleValidator::windowFromException() treats a modified-hours row with
 *     a null window as fully closed — which would silently contradict the operator's intent;
 *   - a closure type must NOT carry times, for the mirror reason (they would be ignored);
 *   - `reason` stays optional: the column is nullable and no active source requires it.
 *
 * Authorization is `EnsurePermission:branch.calendar.manage` + `BranchCalendarExceptionPolicy`.
 */
final class StoreBranchCalendarExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware (EnsureBranchScope + EnsurePermission + EnsureBillingMutable) and the
        // controller's policy call are the authorization boundary. Both genuinely run.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', 'string', Rule::in(array_column(CalendarExceptionType::cases(), 'value'))],
            'opens_at' => ['nullable', 'date_format:H:i,H:i:s'],
            'closes_at' => ['nullable', 'date_format:H:i,H:i:s'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = (string) $this->input('type');
            $opens = $this->input('opens_at');
            $closes = $this->input('closes_at');

            if ($type === CalendarExceptionType::ModifiedHours->value) {
                if ($opens === null || $opens === '') {
                    $validator->errors()->add('opens_at', 'Modified hours require an opening time.');
                }
                if ($closes === null || $closes === '') {
                    $validator->errors()->add('closes_at', 'Modified hours require a closing time.');
                }
                if (is_string($opens) && is_string($closes) && $opens !== '' && $closes !== ''
                    && strtotime($closes) !== false && strtotime($opens) !== false
                    && strtotime($closes) <= strtotime($opens)) {
                    $validator->errors()->add('closes_at', 'The closing time must be after the opening time.');
                }

                return;
            }

            // A closure type has no open window; accepting times would persist values the
            // scheduling gate ignores, which reads to the operator as "hours were saved".
            if (($opens !== null && $opens !== '') || ($closes !== null && $closes !== '')) {
                $validator->errors()->add('type', 'A closure exception cannot carry opening or closing times.');
            }
        });
    }
}
