<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The mandatory reason carried by every platform-access lifecycle mutation — suspend, reactivate,
 * deactivate, revoke invitation and revoke sessions (COR-UI08-001 §11; Phase UI-08).
 *
 * The reason is required because these actions remove someone's access to the platform, and an
 * audit row that cannot say WHY is not accountability. Whether the transition is legal at all is a
 * state question answered under a row lock by the action, never here.
 */
final class PlatformAccessLifecycleReasonRequest extends FormRequest
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
