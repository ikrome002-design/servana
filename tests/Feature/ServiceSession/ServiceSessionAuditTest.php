<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'service-session', 'service-session-audit');

function lastSessionAudit(string $action): AuditLog
{
    return AuditLog::query()->where('action', $action)->latest('id')->firstOrFail();
}

it('audits service_session.started with safe public context only', function (): void {
    $scn = queueScenario();
    startQueueSession($scn);

    $event = lastSessionAudit('service_session.started');
    $session = ServiceSession::query()->firstOrFail();

    expect($event->merchant_id)->toBe($scn['merchant']->id)
        ->and($event->branch_id)->toBe($scn['branch']->id)
        ->and($event->severity)->toBe(AuditSeverity::Info)
        ->and($event->context['service_session_id'])->toBe($session->ulid)
        ->and($event->context['new_state'])->toBe('in_progress')
        ->and($event->context['client_id'])->toBe($scn['client']->ulid)
        ->and($event->context['service_id'])->toBe($scn['service']->ulid);

    // No contact / secret / bigint leakage.
    $json = json_encode($event->context);
    expect($json)->not->toContain($scn['client']->phone_encrypted ?? 'phone_encrypted')
        ->and($event->context)->not->toHaveKey('staff_profile_id');
});

it('audits service_session.completed (info) once per completion', function (): void {
    $scn = queueScenario();
    $ulid = startQueueSession($scn)['ulid'];

    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/complete")->assertOk();

    expect(AuditLog::query()->where('action', 'service_session.completed')->count())->toBe(1);
    $event = lastSessionAudit('service_session.completed');
    expect($event->severity)->toBe(AuditSeverity::Info)
        ->and($event->context['new_state'])->toBe('completed');
});

it('audits service_session.cancelled (warning) with the sanitised reason', function (): void {
    $scn = queueScenario();
    $session = ServiceSession::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'service_id' => $scn['service']->id,
        'staff_profile_id' => $scn['staff']->id,
        'queue_entry_id' => null,
        'status' => 'pending',
    ]);

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/service-sessions/{$session->ulid}/cancel", ['reason' => 'Client could not stay.'])
        ->assertOk();

    $event = lastSessionAudit('service_session.cancelled');
    expect($event->severity)->toBe(AuditSeverity::Warning)
        ->and($event->context['reason'])->toBe('Client could not stay.')
        ->and($event->context['new_state'])->toBe('cancelled');
});

it('writes no success audit when a start is denied', function (): void {
    $scn = queueScenario();

    // Start a fresh (assigned, not called) entry → invalid transition, no session.
    $ulid = createWalkIn($scn)->json('data.id');
    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/start")->assertStatus(422);

    expect(AuditLog::query()->where('action', 'service_session.started')->count())->toBe(0)
        ->and(ServiceSession::query()->count())->toBe(0);
});
