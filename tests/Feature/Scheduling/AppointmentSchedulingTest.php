<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\BranchStatus;
use App\Domain\Branches\Models\BranchCalendarException;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('scheduling', 'appointments', 'appointments-scheduling');

function bookAs(array $scn, array $overrides = []): TestResponse
{
    return test()->actingAs($scn['frontOffice'], 'sanctum')->postJson('/api/v1/appointments', array_merge([
        'client' => $scn['client']->ulid,
        'service' => $scn['service']->ulid,
        'starts_at' => $scn['start']->toIso8601String(),
    ], $overrides));
}

// ── Branch operating-hours / calendar gate ───────────────────────────────────

it('rejects an interval outside branch operating hours', function (): void {
    $scn = appointmentScenario(); // Monday 08:00–18:00

    bookAs($scn, ['starts_at' => $scn['start']->setTime(19, 0)->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.code', 'outside_branch_hours');
});

it('rejects an appointment on a closed weekday (no operating hours)', function (): void {
    $scn = appointmentScenario();

    // Tuesday — the scenario only defines Monday hours.
    bookAs($scn, ['starts_at' => $scn['start']->addDay()->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.code', 'branch_closed');
});

it('rejects an appointment on a public-holiday / special-closure exception date', function (): void {
    $scn = appointmentScenario();
    BranchCalendarException::query()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'date' => $scn['start']->toDateString(),
        'type' => 'public_holiday',
        'reason' => 'Holiday',
    ]);

    bookAs($scn)->assertStatus(422)->assertJsonPath('error.code', 'branch_closed');
});

it('rejects an appointment that crosses a closed period (operating-hours break)', function (): void {
    $scn = appointmentScenario();
    BranchOperatingHour::query()
        ->where('branch_id', $scn['branch']->id)
        ->where('weekday', $scn['weekday'])
        ->update(['break_start' => '12:00:00', 'break_end' => '13:00:00']);

    // 11:30–12:30 overlaps the 12:00–13:00 break.
    bookAs($scn, ['starts_at' => $scn['start']->setTime(11, 30)->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.code', 'crosses_closed_period');
});

it('rejects scheduling at an inactive branch', function (): void {
    $scn = appointmentScenario();
    // `status` is not mass-fillable on MerchantBranch (lifecycle goes through actions).
    $scn['branch']->status = BranchStatus::Suspended;
    $scn['branch']->save();

    bookAs($scn)->assertStatus(422)->assertJsonPath('error.code', 'branch_inactive');
});

it('validates the appointment date, not the current day, for a future booking', function (): void {
    $scn = appointmentScenario();

    // No branch day is open today; a future, in-hours appointment still books.
    bookAs($scn)->assertStatus(201)->assertJsonPath('data.status', 'scheduled');
});

// ── Shared PersonnelSchedulingValidator integration (no duplication) ──────────

it('invokes the scheduling validator on create-with-assignment (ineligible personnel fails)', function (): void {
    $scn = appointmentScenario();
    ServicePersonnelEligibility::query()->where('staff_profile_id', $scn['staff']->id)->update(['active' => false]);

    bookAs($scn, ['assigned_personnel' => $scn['staff']->ulid])
        ->assertStatus(422)->assertJsonPath('error.code', 'personnel_not_eligible');
});

it('invokes the scheduling validator on create-with-assignment (unavailable interval fails)', function (): void {
    $scn = appointmentScenario();
    PersonnelAvailability::query()->where('staff_profile_id', $scn['staff']->id)->delete(); // no availability

    bookAs($scn, ['assigned_personnel' => $scn['staff']->ulid])
        ->assertStatus(422)->assertJsonPath('error.code', 'personnel_unavailable');
});

it('invokes the scheduling validator on assign (ineligible personnel fails)', function (): void {
    $scn = appointmentScenario();
    $ulid = bookAs($scn)->json('data.id');
    ServicePersonnelEligibility::query()->where('staff_profile_id', $scn['staff']->id)->update(['active' => false]);

    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/assign", ['personnel' => $scn['staff']->ulid])
        ->assertStatus(422)->assertJsonPath('error.code', 'personnel_not_eligible');
});

it('invokes the scheduling validator on transfer (ineligible target fails)', function (): void {
    $scn = appointmentScenario();
    $ulid = bookAs($scn, ['assigned_personnel' => $scn['staff']->ulid])->json('data.id');

    // An eligible-less, availability-less target.
    [, , $other] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);

    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/transfer", ['personnel' => $other->ulid])
        ->assertStatus(422)->assertJsonPath('error.code', 'personnel_not_eligible');
});

it('invokes the scheduling validator on assigned reschedule (unavailable new interval fails)', function (): void {
    $scn = appointmentScenario();
    $ulid = bookAs($scn, ['assigned_personnel' => $scn['staff']->ulid])->json('data.id');

    // 08:00–09:00 is inside branch hours (08–18) but before personnel availability (09–17).
    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/reschedule", [
        'starts_at' => $scn['start']->setTime(8, 0)->toIso8601String(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'personnel_unavailable');
});
