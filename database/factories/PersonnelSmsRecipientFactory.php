<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Models\Client;
use App\Domain\Messaging\Sms\Actions\CreateSmsCampaign;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsConsentSnapshotStatus;
use App\Domain\Messaging\Sms\Enums\SmsRecipientExclusionReason;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonnelSmsRecipient>
 *
 * Default: a `pending`, opted-in recipient carrying its encrypted delivery snapshot, anchored on
 * the client's own branch so every composite FK agrees on the merchant. The phone snapshot is
 * copied from the client exactly as {@see CreateSmsCampaign}
 * does, so a factory row can never disagree with production.
 */
class PersonnelSmsRecipientFactory extends Factory
{
    protected $model = PersonnelSmsRecipient::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'campaign_id' => PersonnelSmsCampaign::factory(),
            'client_id' => fn (array $a) => Client::factory()->create([
                'merchant_id' => PersonnelSmsCampaign::query()->whereKey($a['campaign_id'])->value('merchant_id'),
                'branch_id' => PersonnelSmsCampaign::query()->whereKey($a['campaign_id'])->value('branch_id'),
            ])->id,
            'merchant_id' => fn (array $a) => Client::query()->whereKey($a['client_id'])->value('merchant_id'),
            'branch_id' => fn (array $a) => Client::query()->whereKey($a['client_id'])->value('branch_id'),
            'service_session_id' => null,
            'phone_encrypted' => fn (array $a) => Client::query()->whereKey($a['client_id'])->value('phone_encrypted'),
            'phone_last_four' => fn (array $a) => Client::query()->whereKey($a['client_id'])->value('phone_last_four'),
            'eligibility_snapshot_json' => ['served' => true, 'consent' => SmsConsentSnapshotStatus::OptedIn->value],
            'consent_status_snapshot' => SmsConsentSnapshotStatus::OptedIn,
            'delivery_status' => PersonnelSmsRecipientDeliveryStatus::Pending,
            'provider_message_id' => null,
            'cost_minor' => null,
        ];
    }

    /**
     * A recipient excluded at composition: NO delivery snapshot is taken at all (Plan §74 data
     * minimization) — only the masked last-four is retained.
     */
    public function suppressed(SmsRecipientExclusionReason $reason = SmsRecipientExclusionReason::ConsentMissing): static
    {
        return $this->state(fn (array $a): array => [
            'phone_encrypted' => null,
            'delivery_status' => $reason->recipientStatus(),
            'consent_status_snapshot' => $reason === SmsRecipientExclusionReason::ConsentOptedOut
                ? SmsConsentSnapshotStatus::OptedOut
                : SmsConsentSnapshotStatus::Missing,
            'eligibility_snapshot_json' => ['served' => true, 'exclusion_reason' => $reason->value],
        ]);
    }

    public function sent(string $providerMessageId = 'FAKE-TEST-0001'): static
    {
        return $this->state(fn (array $a): array => [
            'delivery_status' => PersonnelSmsRecipientDeliveryStatus::Sent,
            'provider_message_id' => $providerMessageId,
        ]);
    }
}
