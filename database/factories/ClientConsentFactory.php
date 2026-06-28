<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Enums\ConsentChannel;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientConsent>
 */
class ClientConsentFactory extends Factory
{
    protected $model = ClientConsent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Client in the SAME branch + merchant (composite FK requires same tenant).
            'client_id' => Client::factory(),
            'branch_id' => fn (array $attributes) => Client::query()
                ->whereKey($attributes['client_id'])->value('branch_id'),
            'merchant_id' => fn (array $attributes) => Client::query()
                ->whereKey($attributes['client_id'])->value('merchant_id'),
            'channel' => ConsentChannel::Sms,
            'state' => ConsentState::OptedIn,
            'source' => 'front_office',
            'changed_at' => now(),
        ];
    }

    public function optedOut(): static
    {
        return $this->state(fn (array $attributes): array => ['state' => ConsentState::OptedOut]);
    }
}
