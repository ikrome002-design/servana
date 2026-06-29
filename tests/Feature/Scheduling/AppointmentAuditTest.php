<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('scheduling', 'appointments', 'appointments-audit');

function bookAudit(array $scn, array $overrides = []): TestResponse
{
    return test()->actingAs($scn['frontOffice'], 'sanctum')->postJson('/api/v1/appointments', array_merge([
        'client' => $scn['client']->ulid,
        'service' => $scn['service']->ulid,
        'starts_at' => $scn['start']->toIso8601String(),
    ], $overrides));
}

function appointmentEvents(string $action): Collection
{
    return AuditLog::query()->where('action', $action)->get();
}

it('writes exactly one appointment.created event with safe context (no full contact, no sequential id)', function (): void {
    $scn = appointmentScenario();
    $ulid = bookAudit($scn)->json('data.id');

    $events = appointmentEvents('appointment.created');
    expect($events)->toHaveCount(1);

    $context = $events->first()->context;
    expect($context['appointment_id'])->toBe($ulid)
        ->and($context['client_id'])->toBe($scn['client']->ulid)
        ->and($context['service_id'])->toBe($scn['service']->ulid);

    // Identifiers are ULIDs (26 chars), never the sequential database id.
    expect(strlen((string) $context['client_id']))->toBe(26)
        ->and(strlen((string) $context['appointment_id']))->toBe(26);

    // No full phone/email and no blind index in the serialized event.
    $json = (string) json_encode($context);
    expect($json)->not->toContain('phone_index')
        ->and($json)->not->toContain($scn['client']->phone_encrypted);
});

it('audits assign with previous/new personnel and checked_in / cancelled / no_show distinctly', function (): void {
    $scn = appointmentScenario();
    $fo = $scn['frontOffice'];
    $ulid = bookAudit($scn)->json('data.id');

    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/assign", ['personnel' => $scn['staff']->ulid])->assertOk();
    $assigned = appointmentEvents('appointment.assigned')->first();
    expect($assigned->context['previous_personnel_id'])->toBeNull()
        ->and($assigned->context['new_personnel_id'])->toBe($scn['staff']->ulid);

    BranchDayRecord::factory()->open()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'business_date' => now('Africa/Nairobi')->toDateString(),
    ]);
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/appointments/{$ulid}/check-in")->assertOk();
    expect(appointmentEvents('appointment.checked_in'))->toHaveCount(1);

    // no_show is a distinct event on a separate confirmed appointment.
    $other = bookAudit($scn, ['assigned_personnel' => $scn['staff']->ulid, 'starts_at' => $scn['start']->setTime(14, 0)->toIso8601String()])->json('data.id');
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/appointments/{$other}/no-show")->assertOk();
    expect(appointmentEvents('appointment.no_show'))->toHaveCount(1)
        ->and(appointmentEvents('appointment.cancelled'))->toHaveCount(0);
});

it('audits transfer with the old and new personnel ULIDs', function (): void {
    $scn = appointmentScenario();
    $ulid = bookAudit($scn, ['assigned_personnel' => $scn['staff']->ulid])->json('data.id');

    [, , $other] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    ServicePersonnelEligibility::query()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id, 'staff_profile_id' => $other->id, 'active' => true,
    ]);
    PersonnelAvailability::query()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'staff_profile_id' => $other->id,
        'type' => 'recurring', 'weekday' => $scn['weekday'], 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true,
    ]);

    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/transfer", ['personnel' => $other->ulid])->assertOk();

    $event = appointmentEvents('appointment.transferred')->first();
    expect($event->context['previous_personnel_id'])->toBe($scn['staff']->ulid)
        ->and($event->context['new_personnel_id'])->toBe($other->ulid);
});

it('audits reschedule with the previous and new interval', function (): void {
    $scn = appointmentScenario();
    $ulid = bookAudit($scn, ['assigned_personnel' => $scn['staff']->ulid])->json('data.id');

    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/reschedule", [
        'starts_at' => $scn['start']->setTime(14, 0)->toIso8601String(),
    ])->assertOk();

    $event = appointmentEvents('appointment.rescheduled')->first();
    expect($event->context)->toHaveKeys(['previous_starts_at', 'previous_ends_at', 'new_starts_at', 'new_ends_at']);
});

it('sanitises a cancellation reason in the audit context', function (): void {
    $scn = appointmentScenario();
    $ulid = bookAudit($scn)->json('data.id');

    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/cancel", [
        'reason' => 'Client phoned 0712345678 to cancel',
    ])->assertOk();

    $event = appointmentEvents('appointment.cancelled')->first();
    expect(json_encode($event->context))->not->toContain('0712345678');
});

it('does not write a success event when a transition fails', function (): void {
    $scn = appointmentScenario();
    $ulid = bookAudit($scn)->json('data.id'); // scheduled

    // check-in a scheduled appointment → 422, no audit event written.
    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$ulid}/check-in")->assertStatus(422);

    expect(appointmentEvents('appointment.checked_in'))->toHaveCount(0);
});
