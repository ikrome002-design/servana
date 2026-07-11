<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformBillingSettings>
 */
class PlatformBillingSettingsFactory extends Factory
{
    protected $model = PlatformBillingSettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'billing_mode' => BillingMode::FixedAmount,
            'default_trial_days' => 14,
            'grace_days' => 7,
            'currency' => 'KES',
            'updated_by' => User::factory(),
            // Distinct instants so multiple versions never collide on UNIQUE(effective_from).
            'effective_from' => fn (): string => now()->subSeconds(fake()->unique()->numberBetween(0, 1_000_000))->toDateTimeString(),
            'settings' => [],
        ];
    }

    public function mode(BillingMode $mode): static
    {
        return $this->state(fn (array $attributes): array => ['billing_mode' => $mode]);
    }
}
