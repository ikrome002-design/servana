<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryResponseClass;
use App\Domain\Integrations\ReferEarn\Models\ReEventDelivery;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReEventDelivery>
 *
 * Default: one accepted (202) attempt. The stored body is already redacted + bounded, mirroring
 * what the delivery job persists.
 */
class ReEventDeliveryFactory extends Factory
{
    protected $model = ReEventDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            're_outbound_event_id' => ReOutboundEvent::factory(),
            'attempted_at' => now(),
            'duration_ms' => 42,
            'response_status' => 202,
            'response_class' => ReDeliveryResponseClass::Accepted,
            'error_code' => null,
            'response_body_truncated_redacted' => '{"status":"accepted"}',
        ];
    }

    public function serverError(): self
    {
        return $this->state(fn (): array => [
            'response_status' => 503,
            'response_class' => ReDeliveryResponseClass::ServerError,
            'error_code' => 'SERVICE_UNAVAILABLE',
            'response_body_truncated_redacted' => null,
        ]);
    }
}
