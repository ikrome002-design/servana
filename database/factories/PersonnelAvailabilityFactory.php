<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\AvailabilityType;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonnelAvailability>
 *
 * Derives merchant_id + branch_id from the staff profile so the composite FKs and
 * the same-branch rule hold. Defaults to a recurring available weekday interval;
 * use ->exception()/->break()/->unavailableException() for the other shapes.
 */
class PersonnelAvailabilityFactory extends Factory
{
    protected $model = PersonnelAvailability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_profile_id' => StaffProfile::factory(),
            'merchant_id' => fn (array $attributes) => StaffProfile::query()
                ->whereKey($attributes['staff_profile_id'])->value('merchant_id'),
            'branch_id' => fn (array $attributes) => StaffProfile::query()
                ->whereKey($attributes['staff_profile_id'])->value('primary_branch_id'),
            'type' => AvailabilityType::Recurring->value,
            'weekday' => 1, // Monday
            'date' => null,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'available' => true,
        ];
    }

    /** A recurring unavailable break (must sit inside an available interval). */
    public function break(string $start = '13:00:00', string $end = '14:00:00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => AvailabilityType::Recurring->value,
            'available' => false,
            'date' => null,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    /** A date-specific exception interval (available by default). */
    public function exception(string $date, string $start = '09:00:00', string $end = '17:00:00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => AvailabilityType::Exception->value,
            'weekday' => null,
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'available' => true,
        ]);
    }

    /** A date-specific unavailable exception (day off / temporary unavailability). */
    public function unavailableException(string $date, string $start = '09:00:00', string $end = '17:00:00'): static
    {
        return $this->exception($date, $start, $end)
            ->state(fn (array $attributes): array => ['available' => false]);
    }
}
