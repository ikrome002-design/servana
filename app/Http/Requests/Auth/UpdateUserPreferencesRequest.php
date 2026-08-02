<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Domain\Auth\Enums\ThemePreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a change to the authenticated user's own display preferences (Phase UI-04; ADR-021).
 *
 * The target is ALWAYS `$request->user()`. This request accepts no user identifier, no merchant,
 * no branch and no role — there is deliberately no shape in which one person can write another
 * person's preference, which is why no permission key is needed (Plan §10.3 is unchanged).
 *
 * The allowed vocabulary is the enum, so `system` / `auto` are rejected at the boundary as well as
 * by the database CHECK (ADR-021 rule 2 forbids OS-derived theme selection).
 */
final class UpdateUserPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is the authorization: `auth:sanctum` on the route proves who the caller is,
        // and the controller writes only to that user.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `present` (not `required`) with `nullable`: clearing the preference back to "no
            // explicit choice" is a legitimate action and must be distinguishable from omitting
            // the field entirely.
            'theme_preference' => ['present', 'nullable', 'string', Rule::enum(ThemePreference::class)],
        ];
    }

    public function themePreference(): ?ThemePreference
    {
        $value = $this->validated()['theme_preference'] ?? null;

        return $value === null ? null : ThemePreference::from((string) $value);
    }
}
