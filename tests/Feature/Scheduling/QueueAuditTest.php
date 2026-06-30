<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Scheduling\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class)->group('scheduling', 'queue', 'queue-audit');

function queueEvents(string $action): Collection
{
    return AuditLog::query()->where('action', $action)->get();
}

it('writes linked walk_in.created and queue_entry.created with safe context', function (): void {
    $scn = queueScenario();
    $ulid = createWalkIn($scn)->json('data.id');

    expect(queueEvents('walk_in.created'))->toHaveCount(1)
        ->and(queueEvents('queue_entry.created'))->toHaveCount(1);

    $created = queueEvents('queue_entry.created')->first();
    expect($created->context['queue_entry_id'])->toBe($ulid)
        ->and($created->context['client_id'])->toBe($scn['client']->ulid)
        ->and(strlen((string) $created->context['client_id']))->toBe(26);

    // No full phone/email or blind index appears.
    $json = (string) json_encode($created->context);
    expect($json)->not->toContain('phone_index')
        ->and($json)->not->toContain($scn['client']->phone_encrypted);
});

it('audits the appointment conversion as appointment.queued + queue_entry.created', function (): void {
    $scn = queueScenario();
    $appointment = Appointment::factory()->checkedIn()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id, 'service_id' => $scn['service']->id,
    ]);

    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$appointment->ulid}/queue")->assertStatus(201);

    expect(queueEvents('appointment.queued'))->toHaveCount(1)
        ->and(queueEvents('queue_entry.created'))->toHaveCount(1);
    expect(queueEvents('appointment.queued')->first()->context['new_state'])->toBe('queued');
});

it('records transfer with old and new personnel and a sanitised reason', function (): void {
    $scn = queueScenario();
    $ulid = createWalkIn($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid])->json('data.id');

    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/transfer", [
        'personnel' => $scn['staff2']->ulid, 'reason' => 'Staff swap',
    ])->assertOk();

    $event = queueEvents('queue_entry.transferred')->first();
    expect($event->context['previous_personnel_id'])->toBe($scn['staff']->ulid)
        ->and($event->context['new_personnel_id'])->toBe($scn['staff2']->ulid)
        ->and($event->context['reason'])->toBe('Staff swap');
});

it('emits one coherent event per lifecycle action and none for a failed transition', function (): void {
    $scn = queueScenario();
    $fo = $scn['frontOffice'];
    $ulid = createWalkIn($scn)->json('data.id'); // assigned

    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/call")->assertOk();
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/start")->assertOk();
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/complete")->assertOk();

    expect(queueEvents('queue_entry.called'))->toHaveCount(1)
        ->and(queueEvents('queue_entry.started'))->toHaveCount(1)
        ->and(queueEvents('queue_entry.completed'))->toHaveCount(1);

    // A failed (invalid) transition writes no success event.
    $before = queueEvents('queue_entry.completed')->count();
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/complete")->assertStatus(422);
    expect(queueEvents('queue_entry.completed')->count())->toBe($before);
});

it('audits the queue configuration update', function (): void {
    $scn = queueScenario();

    $this->actingAs($scn['branchManager'], 'sanctum')->putJson('/api/v1/queue/configuration', ['queue_capacity' => 10])->assertOk();

    expect(queueEvents('queue.configuration.updated'))->toHaveCount(1);
});
