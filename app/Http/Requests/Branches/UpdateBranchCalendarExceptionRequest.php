<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use App\Domain\Branches\Enums\CalendarExceptionType;
use App\Domain\Branches\Models\BranchCalendarException;
use App\Domain\Branches\Models\MerchantBranch;
use App\Http\Controllers\Api\V1\Branches\BranchCalendarExceptionController;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an update to an existing branch calendar exception (REM-SCR-002B).
 *
 * `date` and `type` are deliberately NOT updatable: together they are the identity that both
 * `UNIQUE(branch_id, date, type)` and AppointmentBranchScheduleValidator key on. Re-pointing them
 * would be an implicit delete-and-create with a different audit meaning, so the operator deletes
 * and re-creates instead. Only the window and the reason change here.
 */
final class UpdateBranchCalendarExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware + BranchCalendarExceptionPolicy::manage are the authorization boundary.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opens_at' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'closes_at' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $exception = $this->resolveException();
            if (! $exception instanceof BranchCalendarException) {
                return; // the controller 404s; nothing to cross-validate
            }

            $isModified = $exception->type === CalendarExceptionType::ModifiedHours;

            $opens = $this->has('opens_at') ? $this->input('opens_at') : $exception->opens_at;
            $closes = $this->has('closes_at') ? $this->input('closes_at') : $exception->closes_at;

            if (! $isModified) {
                // A closure type never has a window; a caller trying to set one is told plainly
                // rather than having the value silently dropped.
                if (($this->has('opens_at') && $this->input('opens_at') !== null)
                    || ($this->has('closes_at') && $this->input('closes_at') !== null)) {
                    $validator->errors()->add('opens_at', 'A closure exception cannot carry opening or closing times.');
                }

                return;
            }

            if ($opens === null || $opens === '' || $closes === null || $closes === '') {
                $validator->errors()->add('opens_at', 'Modified hours require both an opening and a closing time.');

                return;
            }

            if (strtotime((string) $closes) !== false && strtotime((string) $opens) !== false
                && strtotime((string) $closes) <= strtotime((string) $opens)) {
                $validator->errors()->add('closes_at', 'The closing time must be after the opening time.');
            }
        });
    }

    /**
     * The exception addressed by this request, resolved from the route's branch + date.
     *
     * The row has no ULID, so `(branch, date)` is its public identity — see
     * {@see BranchCalendarExceptionController}. The branch
     * binding is already tenant/branch-scoped by EnsureBranchScope, so this cannot reach another
     * tenant's or another branch's row.
     */
    public function resolveException(): ?BranchCalendarException
    {
        $branch = $this->route('branch');
        $date = $this->route('date');

        if (! $branch instanceof MerchantBranch || ! is_string($date)) {
            return null;
        }

        return BranchCalendarException::query()
            ->where('branch_id', $branch->id)
            ->whereDate('date', $date)
            ->first();
    }
}
