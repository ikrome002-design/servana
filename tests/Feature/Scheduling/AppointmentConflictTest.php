<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'appointments', 'appointments-conflict');

/**
 * Create a raw appointment row in the scenario (bypassing the domain action's
 * application-level validation) so the DB exclusion constraint is exercised
 * directly — it is the final concurrency authority.
 */
function scenarioAppointment(array $scn, CarbonImmutable $start, int $minutes, ?int $staffId, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
{
    $attributes = [
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'service_id' => $scn['service']->id,
        'assigned_personnel_staff_profile_id' => $staffId,
        'starts_at' => $start,
        'ends_at' => $start->addMinutes($minutes),
        'status' => $status,
    ];

    // Coherent timestamps for terminal/checked-in states (CHECK constraints).
    $attributes += match ($status) {
        AppointmentStatus::CheckedIn => ['checked_in_at' => CarbonImmutable::now()],
        AppointmentStatus::Cancelled => ['cancelled_at' => CarbonImmutable::now()],
        AppointmentStatus::NoShow => ['no_show_at' => CarbonImmutable::now()],
        default => [],
    };

    return Appointment::factory()->create($attributes);
}

it('rejects two overlapping active appointments for the same personnel member', function (): void {
    $scn = appointmentScenario();
    $start = $scn['start'];

    scenarioAppointment($scn, $start, 60, $scn['staff']->id);

    expect(fn () => scenarioAppointment($scn, $start->addMinutes(30), 60, $scn['staff']->id))
        ->toThrow(QueryException::class);
});

it('allows back-to-back appointments for the same personnel (half-open)', function (): void {
    $scn = appointmentScenario();
    $start = $scn['start'];

    scenarioAppointment($scn, $start, 60, $scn['staff']->id);          // 10:00–11:00
    $second = scenarioAppointment($scn, $start->addMinutes(60), 60, $scn['staff']->id); // 11:00–12:00

    expect($second->exists)->toBeTrue();
});

it('allows the same interval for different personnel', function (): void {
    $scn = appointmentScenario();
    [$otherUser, , $otherStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    $start = $scn['start'];

    scenarioAppointment($scn, $start, 60, $scn['staff']->id);
    $second = scenarioAppointment($scn, $start, 60, $otherStaff->id);

    expect($second->exists)->toBeTrue();
});

it('does not trigger a personnel conflict for unassigned appointments', function (): void {
    $scn = appointmentScenario();
    $start = $scn['start'];

    scenarioAppointment($scn, $start, 60, null, AppointmentStatus::Scheduled);
    $second = scenarioAppointment($scn, $start, 60, null, AppointmentStatus::Scheduled);

    expect($second->exists)->toBeTrue();
});

it('frees the interval after cancellation', function (): void {
    $scn = appointmentScenario();
    $start = $scn['start'];

    scenarioAppointment($scn, $start, 60, $scn['staff']->id, AppointmentStatus::Cancelled);
    $second = scenarioAppointment($scn, $start, 60, $scn['staff']->id);

    expect($second->exists)->toBeTrue();
});

it('frees the interval after a no-show', function (): void {
    $scn = appointmentScenario();
    $start = $scn['start'];

    scenarioAppointment($scn, $start, 60, $scn['staff']->id, AppointmentStatus::NoShow);
    $second = scenarioAppointment($scn, $start, 60, $scn['staff']->id);

    expect($second->exists)->toBeTrue();
});

it('keeps the database exclusion authoritative even when application validation is bypassed', function (): void {
    $scn = appointmentScenario();
    $start = $scn['start'];

    scenarioAppointment($scn, $start, 60, $scn['staff']->id, AppointmentStatus::CheckedIn);

    // A raw overlapping confirmed row for the same personnel — no app validation
    // ran, yet the DB still rejects it.
    expect(fn () => scenarioAppointment($scn, $start->addMinutes(15), 60, $scn['staff']->id))
        ->toThrow(QueryException::class);
});
