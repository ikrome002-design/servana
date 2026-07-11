<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validate a general platform-settings update (Plan §19.3 `PlatformSettingsPolicy`; Phase 20A).
 * `settings` is a JSON object of documented keys only. Authorization + MFA + fresh step-up are
 * enforced by the route middleware.
 */
final class UpdatePlatformSettingsRequest extends FormRequest
{
    /** Documented general platform-settings keys (Phase 20A). Undocumented keys are rejected. */
    private const ALLOWED_KEYS = [
        'support_email',
        'support_url',
        'maintenance_message',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<string,mixed> $settings */
            $settings = (array) $this->input('settings', []);
            foreach (array_keys($settings) as $key) {
                if (! in_array($key, self::ALLOWED_KEYS, true)) {
                    $validator->errors()->add("settings.{$key}", "The setting key '{$key}' is not a documented platform setting.");
                }
            }
        });
    }
}
