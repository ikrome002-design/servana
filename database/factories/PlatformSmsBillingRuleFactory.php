<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Models\PlatformSmsBillingRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformSmsBillingRule>
 */
class PlatformSmsBillingRuleFactory extends Factory
{
    protected $model = PlatformSmsBillingRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'unit_cost_minor' => 100,
            'tax_basis_points' => null,
            'usage_warning_threshold_units' => null,
            'usage_anomaly_threshold_basis_points' => null,
            // Distinct past instants so multiple versions never collide on UNIQUE(effective_from).
            'effective_from' => fn (): string => now()->subSeconds(fake()->unique()->numberBetween(1, 1_000_000))->toDateTimeString(),
            'reason' => 'Factory-scheduled SMS pricing rule.',
            'created_by_user_id' => User::factory()->state(['is_platform_staff' => true]),
        ];
    }

    public function effectiveFrom(CarbonImmutable $instant): static
    {
        return $this->state(fn (array $attributes): array => ['effective_from' => $instant]);
    }

    public function unitCost(int $minorUnits): static
    {
        return $this->state(fn (array $attributes): array => ['unit_cost_minor' => $minorUnits]);
    }

    /** A rule scheduled for the future — the only state that may be cancelled. */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'effective_from' => CarbonImmutable::now()->addDays(7),
        ]);
    }
}
