<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Support;

use App\Domain\Integrations\ReferEarn\Enums\MerchantStatusReasonCategory;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use Illuminate\Support\Carbon;

/**
 * Builds the minimal-fact payload for each Phase 21R-A `merchant.*` event (Plan §58B.2; §9 rule 23;
 * Phase 21R-A).
 *
 * This class is the data-minimization boundary in code. It is written as an explicit ALLOWLIST of
 * facts per event type — it never spreads a model, never serializes a Resource, and never accepts a
 * caller-supplied bag — so a new column on `merchants` or `merchant_profiles` cannot start leaking
 * to a partner by accident. `ReferEarnPayloadDataMinimizationTest` scans this file for forbidden
 * sources and validates every produced payload against the committed JSON Schemas.
 *
 * Permitted facts (§58B.2): public ULIDs, status enums, reason CATEGORIES, hashes/checksums, stable
 * public labels, business dates. Forbidden: client names/phones, staff PII, merchant admin email or
 * phone, invoice line detail, raw payment references, MSISDNs, free-text reasons, internal
 * sequential ids, raw referral codes.
 */
final class MerchantEventPayloadBuilder
{
    /**
     * Envelope fields common to every payload (Plan §58B.2).
     *
     * @return array<string, mixed>
     */
    public function envelope(ReOutboundEventType $type, string $eventId, string $merchantPublicId, int $sequenceNo, Carbon $occurredAt): array
    {
        return [
            'product_code' => (string) config('refer-earn.product_code', 'SRV'),
            'environment' => (string) config('refer-earn.environment', 'local'),
            'merchant_public_id' => $merchantPublicId,
            'event_id' => $eventId,
            'occurred_at' => $occurredAt->utc()->toIso8601ZuluString(),
            'sequence_no' => $sequenceNo,
            'schema_version' => $type->version(),
        ];
    }

    /**
     * Event-specific facts. The envelope is merged in by EnqueueProductEvent.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function facts(ReOutboundEventType $type, Merchant $merchant, array $context = []): array
    {
        return match ($type) {
            // A merchant tenant was created. Status only — the business name, owner name, owner
            // email and contact details all stay inside Servana.
            ReOutboundEventType::MerchantRegistrationStarted => [
                'merchant_status' => $merchant->status->value,
            ],

            // The founding merchant_admin membership exists. Role label only: no name, no email,
            // no user identifier of any kind crosses the boundary.
            ReOutboundEventType::MerchantAdminCreated => [
                'merchant_status' => $merchant->status->value,
                'admin_role' => 'merchant_admin',
            ],

            // First-time setup completed and the merchant went active.
            ReOutboundEventType::MerchantSetupCompleted => [
                'merchant_status' => $merchant->status->value,
                'setup_completed_on' => $this->businessDate($merchant->setup_completed_at),
            ],

            // Plan §58B.1: reason CATEGORY only, never the free-text suspension reason.
            ReOutboundEventType::MerchantStatusChanged => [
                'previous_status' => $this->stringOrNull($context['previous_status'] ?? null),
                'merchant_status' => $merchant->status->value,
                // Anything Servana cannot positively classify is `manual` — never inferred from
                // operator prose, and never omitted.
                'reason_category' => (($context['reason_category'] ?? null) instanceof MerchantStatusReasonCategory
                    ? $context['reason_category']
                    : MerchantStatusReasonCategory::Manual)->value,
            ],

            // Plan §58B.1: "snapshot hash, not raw documents". R&E learns THAT identity changed and
            // can detect churn, without ever receiving a legal name or a registration number.
            ReOutboundEventType::MerchantIdentitySnapshotChanged => [
                'merchant_status' => $merchant->status->value,
                'identity_snapshot_sha256' => $this->stringOrNull($context['identity_snapshot_sha256'] ?? null) ?? '',
                'changed_field_count' => is_int($context['changed_field_count'] ?? null) ? $context['changed_field_count'] : 0,
            ],
        };
    }

    /**
     * Legal/business identity fields whose change constitutes an identity-snapshot change
     * (Plan §58B.1: "merchant legal/business identity profile fields … (name, registration
     * identifiers)").
     *
     * Deliberately narrow. Contact email/phone, address, logo and timezone are operational
     * settings, not identity — and contact details are PII that must never influence a
     * partner-facing event, even as a hash input.
     *
     * @var array<class-string, list<string>>
     */
    public const IDENTITY_FIELDS = [
        Merchant::class => ['name'],
        MerchantProfile::class => ['business_category', 'receipt_display_name'],
    ];

    /**
     * Stable hash of a merchant's legal/business identity (Plan §58B.1).
     *
     * Only the identity fields participate, in a fixed order, so the same identity always hashes the
     * same way and a change is detectable — while the values themselves never leave Servana. R&E
     * learns THAT identity changed, never what it is.
     */
    public function identitySnapshotHash(Merchant $merchant): string
    {
        $profile = $merchant->profile;

        return CanonicalJson::sha256([
            'business_category' => $this->stringOrNull($profile?->business_category) ?? '',
            'name' => $merchant->name,
            'receipt_display_name' => $this->stringOrNull($profile?->receipt_display_name) ?? '',
        ]);
    }

    private function businessDate(?Carbon $at): ?string
    {
        // Business dates are Africa/Nairobi (Plan §1); timestamps stay UTC.
        return $at?->copy()->setTimezone((string) config('scheduling.business_timezone', 'Africa/Nairobi'))->toDateString();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
