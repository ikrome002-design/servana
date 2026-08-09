<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validate a platform-access invitation (COR-UI08-001 §11.6; Phase UI-08).
 *
 * The address is normalized to lowercase before validation so a duplicate differing only in case
 * cannot slip past the partial unique index. NO ROLE FIELD IS ACCEPTED: `super_admin` is the only
 * launch platform role, so accepting a role from the request would create a choice the system does
 * not actually offer.
 */
final class InvitePlatformAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
