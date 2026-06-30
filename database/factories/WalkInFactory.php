<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Models\WalkIn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WalkIn>
 *
 * Anchors on a branch and derives a client + service in the SAME branch + merchant
 * so the composite consistency FKs hold (mirrors AppointmentFactory). Defaults to a
 * next-available walk-in with an attached client.
 */
class WalkInFactory extends Factory
{
    protected $model = WalkIn::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            'client_id' => fn (array $attributes) => Client::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'merchant_id' => $attributes['merchant_id'],
            ])->id,
            'service_id' => fn (array $attributes) => Service::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'merchant_id' => $attributes['merchant_id'],
            ])->id,
            'assignment_mode' => QueueAssignmentMode::NextAvailable,
            'preferred_personnel_staff_profile_id' => null,
            'created_by' => null,
        ];
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'assignment_mode' => QueueAssignmentMode::Manual,
        ]);
    }
}
