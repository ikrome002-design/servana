<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServicePersonnelEligibility>
 *
 * Ties the service and staff profile to the SAME branch + merchant so the
 * composite FKs and the same-branch eligibility rule both hold.
 */
class ServicePersonnelEligibilityFactory extends Factory
{
    protected $model = ServicePersonnelEligibility::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'branch_id' => fn (array $attributes) => Service::query()
                ->whereKey($attributes['service_id'])->value('branch_id'),
            'merchant_id' => fn (array $attributes) => Service::query()
                ->whereKey($attributes['service_id'])->value('merchant_id'),
            'staff_profile_id' => fn (array $attributes) => StaffProfile::factory()->create([
                'merchant_id' => $attributes['merchant_id'],
                'primary_branch_id' => $attributes['branch_id'],
            ])->id,
            'active' => true,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
