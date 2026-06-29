<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Appointment>
 *
 * Anchors on a branch and derives a client + service in the SAME branch + merchant
 * so the composite consistency FKs hold (mirrors ServiceFactory/ClientFactory).
 * Defaults to a future, single-business-date 30-minute `scheduled` appointment with
 * no assigned personnel. Status states set coherent timestamps to satisfy the
 * timestamp↔status CHECK constraints. Tests that need an assigned personnel member
 * pass `assigned_personnel_staff_profile_id` explicitly (a staff profile in the
 * same branch).
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Tomorrow 10:00–10:30 in Africa/Nairobi (future, single business date).
        $start = CarbonImmutable::now('Africa/Nairobi')->addDay()->setTime(10, 0, 0);

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
            'preferred_personnel_staff_profile_id' => null,
            'assigned_personnel_staff_profile_id' => null,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(30),
            'status' => AppointmentStatus::Scheduled,
            'cancellation_reason' => null,
            'transfer_reason' => null,
            'checked_in_at' => null,
            'cancelled_at' => null,
            'no_show_at' => null,
            'created_by' => null,
        ];
    }

    /** A confirmed (personnel-assigned) appointment. */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AppointmentStatus::Confirmed,
        ]);
    }

    /** A checked-in appointment (same-day arrival recorded). */
    public function checkedIn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AppointmentStatus::CheckedIn,
            'checked_in_at' => CarbonImmutable::now(),
        ]);
    }

    /** A transient rescheduled appointment (no terminal timestamps). */
    public function rescheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AppointmentStatus::Rescheduled,
        ]);
    }

    /** Cancelled before check-in. */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
        ]);
    }

    /** Cancelled after check-in (reason required). */
    public function cancelledWithReason(string $reason = 'Client left before service.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AppointmentStatus::CancelledWithReason,
            'checked_in_at' => CarbonImmutable::now()->subHour(),
            'cancelled_at' => CarbonImmutable::now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /** Marked no-show. */
    public function noShow(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AppointmentStatus::NoShow,
            'no_show_at' => CarbonImmutable::now(),
        ]);
    }

    /** Pin the appointment interval. */
    public function between(CarbonImmutable $start, CarbonImmutable $end): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
    }
}
