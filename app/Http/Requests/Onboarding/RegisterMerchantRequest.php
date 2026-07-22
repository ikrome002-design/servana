<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Domain\Integrations\ReferEarn\Data\ReferralCaptureData;
use App\Domain\Integrations\ReferEarn\Enums\ReferralCaptureChannel;
use App\Domain\Integrations\ReferEarn\Support\LandingMetadataAllowlist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates Merchant Administrator self-registration (Scope §3.2).
 *
 * Public endpoint (no auth). Deliberately minimal + safe: owner name, email,
 * business name. No KYC / compliance fields exist (Scope §3.1 exclusion). Email
 * uniqueness is NOT asserted here — duplicate emails are handled uniformly in
 * the action so this endpoint cannot enumerate existing accounts.
 */
final class RegisterMerchantRequest extends FormRequest
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
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'business_name' => ['required', 'string', 'min:2', 'max:160'],

            // Phase 21R-A (Plan §58A.1, §12.1 item 5). Every referral field is OPTIONAL and
            // deliberately permissive: a malformed code must NEVER block registration — it is
            // stored as `invalid_format` evidence and never sent to R&E. Only `max` bounds are
            // enforced here; shape is decided by ReferralCodeNormalizer, not by validation.
            'referral_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'referral_channel' => ['sometimes', 'nullable', 'string', Rule::in(ReferralCaptureChannel::values())],
            'referral_landing_metadata' => ['sometimes', 'nullable', 'array'],
            // The allowlist is the real control (LandingMetadataAllowlist drops unknown keys);
            // this rule just keeps the payload flat and bounded before it gets there.
            'referral_landing_metadata.*' => ['nullable', 'string', 'max:256'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * The referral intent carried by this registration, or null when there is none.
     *
     * Channel precedence is explicit and documented (Plan §58A.1 accepts `?ref=`, central-redirect
     * equivalents and manual entry): the SPA states which channel the code came from, and an
     * unstated channel defaults to `manual_entry` — the most conservative reading, since it claims
     * no provenance the request did not prove.
     */
    public function referralCapture(): ?ReferralCaptureData
    {
        $validated = $this->validated();

        $code = $validated['referral_code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $channel = ReferralCaptureChannel::tryFrom((string) ($validated['referral_channel'] ?? ''))
            ?? ReferralCaptureChannel::ManualEntry;

        $metadata = $validated['referral_landing_metadata'] ?? [];

        return new ReferralCaptureData(
            submittedCode: $code,
            channel: $channel,
            landingMetadata: app(LandingMetadataAllowlist::class)->filter(is_array($metadata) ? $metadata : []),
        );
    }

    public function ownerName(): string
    {
        return (string) $this->validated()['owner_name'];
    }

    public function email(): string
    {
        return (string) $this->validated()['email'];
    }

    public function businessName(): string
    {
        return (string) $this->validated()['business_name'];
    }
}
