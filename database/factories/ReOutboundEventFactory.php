<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Integrations\ReferEarn\Support\CanonicalJson;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReOutboundEvent>
 *
 * Default: a `pending` `merchant.registration_started` event for a new merchant, with a payload
 * whose `content_sha256` is genuinely computed over its canonical JSON (so delivery tests sign real
 * bytes rather than a placeholder hash).
 */
class ReOutboundEventFactory extends Factory
{
    protected $model = ReOutboundEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $eventId = (string) Str::ulid();
        $occurredAt = now();

        return [
            'ulid' => (string) Str::ulid(),
            'event_id' => $eventId,
            'event_type' => ReOutboundEventType::MerchantRegistrationStarted,
            'event_version' => '1',
            'merchant_id' => Merchant::factory(),
            'merchant_public_id' => fn (array $a): ?string => Merchant::query()->whereKey($a['merchant_id'])->value('ulid'),
            'sequence_no' => 1,
            'payload' => fn (array $a): array => $this->payloadFor($a, $eventId, $occurredAt->toIso8601ZuluString()),
            'content_sha256' => fn (array $a): string => CanonicalJson::sha256($a['payload']),
            'occurred_at' => $occurredAt,
            'delivery_status' => ReDeliveryStatus::Pending,
            'delivered_at' => null,
            'attempt_count' => 0,
            'next_attempt_at' => $occurredAt,
            'last_response_status' => null,
            'last_error_code' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function payloadFor(array $attributes, string $eventId, string $occurredAt): array
    {
        $merchantPublicId = $attributes['merchant_public_id'];

        return [
            'environment' => 'testing',
            'event_id' => $eventId,
            'merchant_public_id' => is_string($merchantPublicId) ? $merchantPublicId : '',
            'merchant_status' => 'pending_setup',
            'occurred_at' => $occurredAt,
            'product_code' => 'SRV',
            'schema_version' => '1',
            'sequence_no' => is_int($attributes['sequence_no'] ?? null) ? $attributes['sequence_no'] : 1,
        ];
    }

    public function delivering(): self
    {
        return $this->state(fn (): array => ['delivery_status' => ReDeliveryStatus::Delivering]);
    }

    public function delivered(): self
    {
        return $this->state(fn (): array => [
            'delivery_status' => ReDeliveryStatus::Delivered,
            'delivered_at' => now(),
            'attempt_count' => 1,
            'next_attempt_at' => null,
            'last_response_status' => 202,
        ]);
    }

    public function deadLetter(): self
    {
        return $this->state(fn (): array => [
            'delivery_status' => ReDeliveryStatus::DeadLetter,
            'attempt_count' => 1,
            'next_attempt_at' => null,
        ]);
    }
}
