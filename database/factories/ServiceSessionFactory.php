<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceSession>
 *
 * Anchors on a branch and derives a client, service, personnel, and source queue
 * entry in the SAME branch + merchant so the composite consistency FKs hold.
 * Defaults to a `pending` session. State helpers set coherent timestamps to satisfy
 * the status↔timestamp CHECK constraints and the active partial-unique index (one
 * active session per personnel — tests creating multiple active sessions must use
 * distinct personnel).
 */
class ServiceSessionFactory extends Factory
{
    protected $model = ServiceSession::class;

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
            'staff_profile_id' => fn (array $attributes) => StaffProfile::factory()->create([
                'merchant_id' => $attributes['merchant_id'],
                'primary_branch_id' => $attributes['branch_id'],
            ])->id,
            'queue_entry_id' => fn (array $attributes) => QueueEntry::factory()->inService()->create([
                'branch_id' => $attributes['branch_id'],
                'merchant_id' => $attributes['merchant_id'],
                'client_id' => $attributes['client_id'],
                'service_id' => $attributes['service_id'],
                'staff_profile_id' => $attributes['staff_profile_id'],
            ])->id,
            'status' => ServiceSessionStatus::Pending,
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'notes' => null,
            'preferred_personnel_honored' => null,
            'created_by' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ServiceSessionStatus::InProgress,
            'started_at' => CarbonImmutable::now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ServiceSessionStatus::Completed,
            'started_at' => CarbonImmutable::now()->subMinutes(20),
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    public function cancelled(string $reason = 'Client could not continue.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ServiceSessionStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
            'cancellation_reason' => $reason,
        ]);
    }
}
