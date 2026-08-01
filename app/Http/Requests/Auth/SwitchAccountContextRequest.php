<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an account-context switch request (Phase UI-03; ADR-018; UI/UX plan §10.2).
 *
 * DELIBERATELY MINIMAL. The only meaningful input is an opaque server-issued context id. There is
 * no rule for a role, permission, merchant, branch, host, environment or MFA field, because none
 * of those may be accepted from the browser at all — a rule for them would imply they could ever
 * be honoured. Anything else in the body is simply not read.
 *
 * Whether the id is legitimate is NOT decided here: the controller resolves it against the freshly
 * derived context list for the authenticated user, so a well-formed id for someone else's context
 * still resolves to nothing.
 */
final class SwitchAccountContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership authorization. The route already requires an authenticated, active principal;
        // the context id is then resolved strictly within that user's own contexts.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 32 lowercase hex characters — the exact shape AccountContextIdentifier mints.
            'context_id' => ['required', 'string', 'regex:/^[0-9a-f]{32}$/'],
            // Shape only; AccountHostUrlGenerator::safeRelativePath() decides safety.
            'redirect' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }

    public function contextId(): string
    {
        return (string) $this->validated()['context_id'];
    }

    public function redirectPath(): ?string
    {
        $redirect = $this->validated()['redirect'] ?? null;

        return is_string($redirect) && $redirect !== '' ? $redirect : null;
    }
}
