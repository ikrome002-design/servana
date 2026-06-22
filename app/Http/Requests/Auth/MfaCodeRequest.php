<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a submitted MFA code — a 6-digit TOTP or a recovery code (Plan §18).
 *
 * Only shape is validated; whether the code is genuine is decided by the TOTP
 * verification / atomic recovery-code consume, never by validation, so timing
 * and messages stay uniform. Authorization is the route's `auth:sanctum` +
 * EnsurePrivilegedMfa; this request is shared by confirm/challenge/recovery.
 */
final class MfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    public function code(): string
    {
        return trim((string) $this->validated()['code']);
    }
}
