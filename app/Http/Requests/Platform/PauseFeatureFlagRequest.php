<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate an emergency pause (COR-UI08-001 section 12; Phase UI-08).
 *
 * Pause is the one single-actor path, permitted because it moves a flag towards deny. It still
 * carries a MANDATORY reason: "why was this rollout stopped?" is precisely the question the next
 * operator will ask, and an unexplained pause is indistinguishable from an accident.
 */
final class PauseFeatureFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ];
    }
}
