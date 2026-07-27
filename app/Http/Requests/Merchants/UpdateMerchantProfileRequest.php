<?php

declare(strict_types=1);

namespace App\Http\Requests\Merchants;

use App\Domain\Merchants\Actions\UpdateMerchantProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validates a merchant business-profile update (REM-SCR-002A).
 *
 * Rules mirror `CompleteFirstTimeSetupRequest` exactly, so the field contract is identical
 * whether a value is first supplied at setup or later edited — with one deliberate
 * difference: every field is `sometimes` because this is a PATCH, and `business_category` /
 * `contact_phone` may not be BLANKED once set (they are `required` at setup, so allowing a
 * later update to null would let the profile regress below the setup contract).
 *
 * Authorization is the route's `EnsurePermission:merchant.profile.update` plus
 * `MerchantProfilePolicy::update` in the controller — never this request.
 */
final class UpdateMerchantProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware (EnsurePermission + EnsureBillingMutable) and
        // MerchantProfilePolicy::update are the authorization boundary. Both genuinely run.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required at setup, so present-but-empty is rejected here rather than silently
            // regressing the profile.
            'business_category' => ['sometimes', 'string', 'min:2', 'max:80'],
            'contact_phone' => ['sometimes', 'string', 'min:7', 'max:32'],

            // Optional at setup, so clearable here.
            'contact_email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
            'receipt_display_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'town' => ['sometimes', 'nullable', 'string', 'max:80'],
            'timezone' => ['sometimes', 'string', 'timezone'],
        ];
    }

    /**
     * The validated subset restricted to what {@see UpdateMerchantProfile} may write.
     *
     * A second, explicit narrowing on top of the rules: a future rule addition cannot widen
     * what reaches the model, and no request payload is ever mass-assigned.
     *
     * @return array<string, mixed>
     */
    public function writableAttributes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return array_intersect_key($validated, array_flip(UpdateMerchantProfile::WRITABLE));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('contact_email') && is_string($this->input('contact_email'))) {
            $email = Str::lower(trim((string) $this->input('contact_email')));
            $this->merge(['contact_email' => $email === '' ? null : $email]);
        }
    }
}
