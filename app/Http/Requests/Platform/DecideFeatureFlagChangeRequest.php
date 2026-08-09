<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate an approve / reject / cancel decision on a feature-flag change (COR-UI08-001
 * section 12.3; Phase UI-08).
 *
 * `reason` is required on REJECT (it becomes the mandatory `decision_note`) and optional on approve
 * and cancel, where the request's own reason already stands. WHO may decide is not a validation
 * question — the maker/checker rule is enforced by the action and by a database CHECK.
 */
final class DecideFeatureFlagChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rejecting = $this->routeIs('platform.feature-flag-change-requests.reject');

        return [
            'reason' => [$rejecting ? 'required' : 'nullable', 'string', 'min:8', 'max:500'],
        ];
    }
}
