<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\PromotionalDiscountType;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromotionalDiscount>
 */
class PromotionalDiscountFactory extends Factory
{
    protected $model = PromotionalDiscount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'name' => 'Promo '.fake()->unique()->numberBetween(1, 999999),
            'type' => PromotionalDiscountType::Percentage,
            'value' => 1000, // 10.00% in basis points
            'currency' => null,
            'target_scope' => PromotionTargetScope::AllNewMerchants,
            'effective_from' => today(),
            'effective_to' => null,
            'status' => PromotionStatus::Draft,
            'created_by' => User::factory(),
            'approved_by' => null,
            'approved_at' => null,
            'change_reason' => null,
        ];
    }

    public function percentage(int $basisPoints = 1000): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => PromotionalDiscountType::Percentage,
            'value' => $basisPoints,
            'currency' => null,
        ]);
    }

    public function fixed(int $minor = 50000, string $currency = 'KES'): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => PromotionalDiscountType::FixedAmount,
            'value' => $minor,
            'currency' => strtoupper($currency),
        ]);
    }

    public function scope(PromotionTargetScope $scope): static
    {
        return $this->state(fn (array $attributes): array => ['target_scope' => $scope]);
    }

    public function status(PromotionStatus $status): static
    {
        return $this->state(function (array $attributes) use ($status): array {
            $state = ['status' => $status];

            // Approval/status coherence: scheduled/active/paused/expired require approval.
            if ($status->requiresApproval()) {
                $state['approved_by'] = $attributes['approved_by'] ?? User::factory();
                $state['approved_at'] = $attributes['approved_at'] ?? now();
            }

            return $state;
        });
    }

    public function draft(): static
    {
        return $this->status(PromotionStatus::Draft);
    }

    public function scheduled(): static
    {
        return $this->status(PromotionStatus::Scheduled)
            ->state(fn (array $attributes): array => [
                'effective_from' => today()->addDays(7),
            ]);
    }

    public function active(): static
    {
        return $this->status(PromotionStatus::Active);
    }

    public function paused(): static
    {
        return $this->status(PromotionStatus::Paused);
    }

    public function expired(): static
    {
        return $this->status(PromotionStatus::Expired)
            ->state(fn (array $attributes): array => [
                'effective_from' => today()->subDays(30),
                'effective_to' => today()->subDay(),
            ]);
    }

    public function cancelled(): static
    {
        return $this->status(PromotionStatus::Cancelled);
    }
}
