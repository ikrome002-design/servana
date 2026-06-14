<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a Magic Link verify submission (Plan §9.1). Public endpoint.
 *
 * Only shape is validated here; whether the token is genuine/unexpired/unused is
 * decided by the atomic consume, never by validation (so timing and messages
 * stay uniform — no enumeration).
 */
final class VerifyMagicLinkRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:512'],
        ];
    }

    public function token(): string
    {
        return (string) $this->validated()['token'];
    }
}
