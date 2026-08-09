<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a platform-access permission change (COR-UI08-001 §11; Phase UI-08).
 *
 * The request supplies the COMPLETE set of permission keys to DENY; anything absent returns to the
 * role default. There is no `granted` field and there never may be — the override table has no
 * grant effect, so accepting one here would imply a capability the system cannot represent.
 *
 * An empty array is meaningful and allowed: it clears every override.
 */
final class UpdatePlatformAccessPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'denied_permissions' => ['present', 'array', 'max:200'],
            'denied_permissions.*' => ['required', 'string', 'max:100', 'distinct'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ];
    }
}
