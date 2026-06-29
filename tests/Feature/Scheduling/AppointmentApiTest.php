<?php

declare(strict_types=1);

use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('scheduling', 'appointments', 'appointments-api');

/** Create an appointment over the API as the Front Office actor; return the JSON response. */
function createAppointment(array $scn, array $overrides = []): TestResponse
{
    return test()->actingAs($scn['frontOffice'], 'sanctum')->postJson('/api/v1/appointments', array_merge([
        'client' => $scn['client']->ulid,
        'service' => $scn['service']->ulid,
        'starts_at' => $scn['start']->toIso8601String(),
    ], $overrides));
}

it('lets Front Office create an unassigned scheduled appointment', function (): void {
    $scn = appointmentScenario();

    $response = createAppointment($scn)->assertStatus(201);

    $response->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.client.id', $scn['client']->ulid)
        ->assertJsonPath('data.service.id', $scn['service']->ulid);

    // ULID public id, never the sequential id; contact masked.
    expect(strlen((string) $response->json('data.id')))->toBe(26)
        ->and($response->json('data.client.phone_masked'))->toContain('•');

    $response->assertJsonMissingPath('data.client.phone_index')
        ->assertJsonMissingPath('data.merchant_id');
});

it('lets Front Office create a confirmed appointment when assigning eligible personnel', function (): void {
    $scn = appointmentScenario();

    createAppointment($scn, ['assigned_personnel' => $scn['staff']->ulid])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.assigned_personnel.id', $scn['staff']->ulid);
});

it('derives the end time from the service duration snapshot (client cannot supply it)', function (): void {
    $scn = appointmentScenario(); // 60-minute service, 10:00 start

    $response = createAppointment($scn, ['ends_at' => $scn['start']->addHours(5)->toIso8601String()])
        ->assertStatus(201);

    // Same instant as start + the 60-minute service duration (compare instants, not
    // the timezone representation — the API serializes in UTC).
    expect(CarbonImmutable::parse($response->json('data.ends_at'))->equalTo($scn['start']->addMinutes(60)))
        ->toBeTrue();
});

it('ignores body-supplied ownership and status identifiers', function (): void {
    $scn = appointmentScenario();

    $response = createAppointment($scn, [
        'merchant_id' => 999999,
        'branch_id' => null,
        'status' => 'confirmed',
        'created_by' => 1,
    ])->assertStatus(201);

    // Status is server-derived (scheduled, not the body's "confirmed"); ownership is the actor's.
    $response->assertJsonPath('data.status', 'scheduled');
    $appointment = Appointment::query()->where('ulid', $response->json('data.id'))->firstOrFail();
    expect($appointment->merchant_id)->toBe($scn['merchant']->id)
        ->and($appointment->branch_id)->toBe($scn['branch']->id);
});

it('lets Front Office assign, then reschedule, transfer, check in and run the workflow', function (): void {
    $scn = appointmentScenario();
    $fo = $scn['frontOffice'];

    // create unassigned → assign (confirms).
    $ulid = createAppointment($scn)->json('data.id');
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/assign", ['personnel' => $scn['staff']->ulid])
        ->assertOk()->assertJsonPath('data.status', 'confirmed');

    // reschedule to 14:00 (still inside hours + availability).
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/reschedule", [
        'starts_at' => $scn['start']->setTime(14, 0)->toIso8601String(),
    ])->assertOk()->assertJsonPath('data.status', 'confirmed');

    // transfer to another eligible personnel.
    [, , $other] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    ServicePersonnelEligibility::query()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id, 'staff_profile_id' => $other->id, 'active' => true,
    ]);
    PersonnelAvailability::query()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $other->id,
        'type' => 'recurring', 'weekday' => $scn['weekday'], 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true,
    ]);
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/transfer", ['personnel' => $other->ulid, 'reason' => 'Staff swap'])
        ->assertOk()->assertJsonPath('data.assigned_personnel.id', $other->ulid);

    // open the branch day → check in.
    BranchDayRecord::factory()->open()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'business_date' => now('Africa/Nairobi')->toDateString(),
    ]);
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/check-in")
        ->assertOk()->assertJsonPath('data.status', 'checked_in');
});

it('requires the branch day to be open to check a client in', function (): void {
    $scn = appointmentScenario();
    $ulid = createAppointment($scn, ['assigned_personnel' => $scn['staff']->ulid])->json('data.id');

    // No open branch day for today.
    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/check-in")
        ->assertStatus(409)->assertJsonPath('error.code', 'branch_day_not_open');
});

it('lets Front Office cancel and mark no-show', function (): void {
    $scn = appointmentScenario();
    $fo = $scn['frontOffice'];

    $a = createAppointment($scn)->json('data.id');
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/appointments/{$a}/cancel", ['reason' => 'Client called off'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    $b = createAppointment($scn, ['assigned_personnel' => $scn['staff']->ulid])->json('data.id');
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/appointments/{$b}/no-show")
        ->assertOk()->assertJsonPath('data.status', 'no_show');
});

it('rejects an invalid state transition with 422 invalid_state_transition', function (): void {
    $scn = appointmentScenario();
    $ulid = createAppointment($scn)->json('data.id'); // scheduled, unassigned

    // scheduled cannot be checked in.
    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/check-in")
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');
});

it('rejects an overlapping assigned appointment with 409 appointment_schedule_conflict', function (): void {
    $scn = appointmentScenario();

    createAppointment($scn, ['assigned_personnel' => $scn['staff']->ulid])->assertStatus(201);

    createAppointment($scn, [
        'assigned_personnel' => $scn['staff']->ulid,
        'starts_at' => $scn['start']->addMinutes(30)->toIso8601String(),
    ])->assertStatus(409)->assertJsonPath('error.code', 'appointment_schedule_conflict');
});

it('lists same-branch appointments for Front Office (masked contact)', function (): void {
    $scn = appointmentScenario();
    createAppointment($scn)->assertStatus(201);

    $this->actingAs($scn['frontOffice'], 'sanctum')->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.client.phone_last_four', $scn['client']->phone_last_four);
});

it('lets Branch Manager read appointments but denies every mutation', function (): void {
    $scn = appointmentScenario();
    [$bm] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::BranchManager);
    $ulid = createAppointment($scn, ['assigned_personnel' => $scn['staff']->ulid])->json('data.id');

    // read OK (branch.dashboard.view).
    $this->actingAs($bm, 'sanctum')->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($bm, 'sanctum')->getJson("/api/v1/appointments/{$ulid}")->assertOk();

    // every mutation forbidden.
    $this->actingAs($bm, 'sanctum')->postJson('/api/v1/appointments', [
        'client' => $scn['client']->ulid, 'service' => $scn['service']->ulid, 'starts_at' => $scn['start']->toIso8601String(),
    ])->assertForbidden();
    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/transfer", ['personnel' => $scn['staff']->ulid])->assertForbidden();
    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/reschedule", ['starts_at' => $scn['start']->setTime(15, 0)->toIso8601String()])->assertForbidden();
    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/cancel")->assertForbidden();
    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/check-in")->assertForbidden();
    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/no-show")->assertForbidden();
});

it('denies appointment operations to HR, Finance and Audit', function (): void {
    $scn = appointmentScenario();
    $payload = ['client' => $scn['client']->ulid, 'service' => $scn['service']->ulid, 'starts_at' => $scn['start']->toIso8601String()];

    foreach ([MerchantUserRole::Hr, MerchantUserRole::Finance, MerchantUserRole::Audit] as $role) {
        [$user] = branchStaff($scn['merchant'], $scn['branch'], $role);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', $payload)->assertForbidden();
    }
});

it('gives the Super Administrator no merchant-operation appointment route', function (): void {
    $platform = User::factory()->platformStaff()->create();

    $this->actingAs($platform, 'sanctum')->getJson('/api/v1/appointments')->assertForbidden();
});

it('lets Personnel see only their own assigned appointments', function (): void {
    $scn = appointmentScenario();

    // one assigned to the scenario staff (the Personnel actor), one to another.
    $mine = createAppointment($scn, ['assigned_personnel' => $scn['staff']->ulid])->json('data.id');

    [, , $other] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    ServicePersonnelEligibility::query()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id, 'staff_profile_id' => $other->id, 'active' => true,
    ]);
    PersonnelAvailability::query()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $other->id,
        'type' => 'recurring', 'weekday' => $scn['weekday'], 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true,
    ]);
    createAppointment($scn, ['assigned_personnel' => $other->ulid, 'starts_at' => $scn['start']->setTime(15, 0)->toIso8601String()])->assertStatus(201);

    $response = $this->actingAs($scn['staffUser'], 'sanctum')->getJson('/api/v1/personnel/me/appointments')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($mine);
});

it('denies Personnel any appointment mutation route', function (): void {
    $scn = appointmentScenario();
    $ulid = createAppointment($scn, ['assigned_personnel' => $scn['staff']->ulid])->json('data.id');

    $this->actingAs($scn['staffUser'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/cancel")->assertForbidden();
    $this->actingAs($scn['staffUser'], 'sanctum')->postJson('/api/v1/appointments', [
        'client' => $scn['client']->ulid, 'service' => $scn['service']->ulid, 'starts_at' => $scn['start']->toIso8601String(),
    ])->assertForbidden();
});

it('returns 404 for a foreign-tenant appointment binding (no existence leak)', function (): void {
    $scn = appointmentScenario();
    $ulid = createAppointment($scn)->json('data.id');

    // A Front Office user in a DIFFERENT merchant.
    $otherScn = appointmentScenario();
    $this->actingAs($otherScn['frontOffice'], 'sanctum')->getJson("/api/v1/appointments/{$ulid}")->assertNotFound();
});
