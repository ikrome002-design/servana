<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FreePeriodOffer>
 */
class FreePeriodOfferFactory extends Factory
{
    protected $model = FreePeriodOffer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'name' => 'Free period '.fake()->unique()->numberBetween(1, 999999),
            'free_period_days' => 30,
            'target_scope' => PromotionTargetScope::AllNewMerchants,
            'effective_from' => today(),
            'effective_to' => null,
            'status' => FreePeriodOfferStatus::Draft,
            'created_by' => User::factory(),
            'approved_by' => null,
            'approved_at' => null,
            'change_reason' => null,
        ];
    }

    public function days(int $days): static
    {
        return $this->state(fn (array $attributes): array => ['free_period_days' => $days]);
    }

    public function scope(PromotionTargetScope $scope): static
    {
        return $this->state(fn (array $attributes): array => ['target_scope' => $scope]);
    }

    public function status(FreePeriodOfferStatus $status): static
    {
        return $this->state(function (array $attributes) use ($status): array {
            $state = ['status' => $status];

            if ($status->requiresApproval()) {
                $state['approved_by'] = $attributes['approved_by'] ?? User::factory();
                $state['approved_at'] = $attributes['approved_at'] ?? now();
            }

            return $state;
        });
    }

    public function draft(): static
    {
        return $this->status(FreePeriodOfferStatus::Draft);
    }

    public function scheduled(): static
    {
        return $this->status(FreePeriodOfferStatus::Scheduled)
            ->state(fn (array $attributes): array => [
                'effective_from' => today()->addDays(7),
            ]);
    }

    public function active(): static
    {
        return $this->status(FreePeriodOfferStatus::Active);
    }

    public function paused(): static
    {
        return $this->status(FreePeriodOfferStatus::Paused);
    }

    public function expired(): static
    {
        return $this->status(FreePeriodOfferStatus::Expired)
            ->state(fn (array $attributes): array => [
                'effective_from' => today()->subDays(30),
                'effective_to' => today()->subDay(),
            ]);
    }

    public function cancelled(): static
    {
        return $this->status(FreePeriodOfferStatus::Cancelled);
    }
}
