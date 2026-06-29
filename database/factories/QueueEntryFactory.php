<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\WalkIn;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QueueEntry>
 *
 * Anchors on a branch and derives a client + service in the SAME branch + merchant
 * so the composite consistency FKs hold. Defaults to a `waiting`, walk-in-origin
 * entry at position 1 (the source XOR + partial-unique active position mean tests
 * creating multiple active entries in one branch should set distinct positions or
 * use the CreateWalkInAndQueueEntry action). State helpers set coherent timestamps
 * to satisfy the status↔timestamp CHECK constraints.
 */
class QueueEntryFactory extends Factory
{
    protected $model = QueueEntry::class;

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
            // Default origin: a walk-in in the same branch/merchant/client/service.
            'walk_in_id' => fn (array $attributes) => WalkIn::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'merchant_id' => $attributes['merchant_id'],
                'client_id' => $attributes['client_id'],
                'service_id' => $attributes['service_id'],
            ])->id,
            'appointment_id' => null,
            'staff_profile_id' => null,
            'preferred_personnel_staff_profile_id' => null,
            'assignment_mode' => QueueAssignmentMode::NextAvailable,
            'status' => QueueEntryStatus::Waiting,
            'position' => 1,
            'queued_at' => CarbonImmutable::now(),
            'assigned_at' => null,
            'called_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'no_show_at' => null,
            'transferred_at' => null,
            'transferred_from_staff_profile_id' => null,
            'transferred_to_staff_profile_id' => null,
            'transfer_reason' => null,
            'cancellation_reason' => null,
            'preferred_personnel_override_reason' => null,
            'estimated_wait_minutes' => 0,
            'estimated_wait_override_minutes' => null,
            'estimated_wait_override_reason' => null,
            'estimated_wait_overridden_by' => null,
            'created_by' => null,
        ];
    }

    /** Origin is a checked-in appointment (clears the walk-in origin). */
    public function forAppointment(?Appointment $appointment = null): static
    {
        return $this->state(function (array $attributes) use ($appointment): array {
            if ($appointment !== null) {
                return [
                    'walk_in_id' => null,
                    'appointment_id' => $appointment->id,
                    'branch_id' => $appointment->branch_id,
                    'merchant_id' => $appointment->merchant_id,
                    'client_id' => $appointment->client_id,
                    'service_id' => $appointment->service_id,
                ];
            }

            return [
                'walk_in_id' => null,
                'appointment_id' => fn (array $attrs) => Appointment::factory()->checkedIn()->create([
                    'branch_id' => $attrs['branch_id'],
                    'merchant_id' => $attrs['merchant_id'],
                    'client_id' => $attrs['client_id'],
                    'service_id' => $attrs['service_id'],
                ])->id,
            ];
        });
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn (array $attributes): array => ['position' => $position]);
    }

    public function assigned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QueueEntryStatus::Assigned,
            'assignment_mode' => QueueAssignmentMode::Manual,
            'assigned_at' => CarbonImmutable::now(),
        ]);
    }

    public function called(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QueueEntryStatus::Called,
            'assigned_at' => CarbonImmutable::now()->subMinutes(5),
            'called_at' => CarbonImmutable::now(),
        ]);
    }

    public function inService(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QueueEntryStatus::InService,
            'assigned_at' => CarbonImmutable::now()->subMinutes(10),
            'called_at' => CarbonImmutable::now()->subMinutes(5),
            'started_at' => CarbonImmutable::now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QueueEntryStatus::Completed,
            'assigned_at' => CarbonImmutable::now()->subMinutes(30),
            'called_at' => CarbonImmutable::now()->subMinutes(25),
            'started_at' => CarbonImmutable::now()->subMinutes(20),
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    public function cancelled(string $reason = 'Client left the branch.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QueueEntryStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
            'cancellation_reason' => $reason,
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QueueEntryStatus::NoShow,
            'no_show_at' => CarbonImmutable::now(),
        ]);
    }
}
