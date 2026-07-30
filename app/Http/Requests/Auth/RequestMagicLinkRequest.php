<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validates a Magic Link request (Plan §9.1). Public endpoint — no auth.
 */
final class RequestMagicLinkRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            // The deep link the user was heading for before being asked to sign in. Shape only:
            // whether it is SAFE is decided by AccountHostUrlGenerator::safeRelativePath() in the
            // action, so there is exactly one place that judges a redirect (UI/UX plan §10.6).
            // 512 matches the bound column, so an over-long value fails validation rather than
            // being silently truncated into a different path.
            'redirect' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }

    /** Normalize the email before validation so storage/lookup are consistent. */
    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function email(): string
    {
        return (string) $this->validated()['email'];
    }

    /** The requested post-auth path, unvalidated for safety — the action decides that. */
    public function redirectPath(): ?string
    {
        $redirect = $this->validated()['redirect'] ?? null;

        return is_string($redirect) && $redirect !== '' ? $redirect : null;
    }
}
