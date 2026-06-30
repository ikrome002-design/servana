<?php

declare(strict_types=1);

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('scheduling', 'service-session', 'service-session-coupling');

it('creates and starts exactly one session on queue start; queue becomes in_service', function (): void {
    $scn = queueScenario();

    $session = startQueueSession($scn)['start']
        ->assertOk()
        ->assertJsonPath('data.status', 'in_service')
        ->assertJsonPath('data.service_session.status', 'in_progress');

    $entry = QueueEntry::query()->where('ulid', $session->json('data.id'))->firstOrFail();

    expect(ServiceSession::query()->count())->toBe(1);
    $row = ServiceSession::query()->firstOrFail();
    expect($row->queue_entry_id)->toBe($entry->id)
        ->and($row->client_id)->toBe($entry->client_id)   // derived from the source
        ->and($row->service_id)->toBe($entry->service_id) // derived from the source (Gate B)
        ->and($row->staff_profile_id)->toBe($entry->staff_profile_id)
        ->and($row->started_at)->not->toBeNull();
});

it('does not create a second session on a repeated start (already in_service)', function (): void {
    $scn = queueScenario();
    $ulid = startQueueSession($scn)['ulid'];

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/queue-entries/{$ulid}/start")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');

    expect(ServiceSession::query()->count())->toBe(1);
});

it('denies assigning a second client to an already-busy personnel and creates no second session', function (): void {
    $scn = queueScenario();

    // First walk-in pinned to staff → started (staff now serving an in_progress session).
    startQueueSession($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid])['start']->assertOk();

    // A second manual assignment to the SAME busy staff is rejected at creation (the
    // queue conflict gate denies a busy member — the app-layer duplicate-active guard).
    createWalkIn($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid])
        ->assertStatus(422);

    expect(ServiceSession::query()->count())->toBe(1);
});

it('denies start when the assigned personnel is no longer service-eligible', function (): void {
    $scn = queueScenario();

    $ulid = createWalkIn($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid])->json('data.id');
    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/call")->assertOk();

    // Revoke eligibility AFTER call, before start.
    ServicePersonnelEligibility::query()
        ->where('service_id', $scn['service']->id)
        ->where('staff_profile_id', $scn['staff']->id)
        ->update(['active' => false]);

    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/start")
        ->assertStatus(422);

    expect(ServiceSession::query()->count())->toBe(0);
    expect(QueueEntry::query()->where('ulid', $ulid)->firstOrFail()->status->value)->toBe('called');
});

it('records the preferred-personnel execution flag as honoured when the preferred member serves', function (): void {
    $scn = queueScenario();

    $ulid = createWalkIn($scn, [
        'assignment_mode' => 'preferred_personnel',
        'preferred_personnel' => $scn['staff']->ulid,
    ])->json('data.id');

    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/call")->assertOk();
    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/start")
        ->assertOk()
        ->assertJsonPath('data.service_session.preferred_personnel_honored', true);
});

it('completes both aggregates and returns a non-payable preview; no invoice or commission ledger is created', function (): void {
    $scn = queueScenario();
    $ulid = startQueueSession($scn)['ulid'];

    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.service_session.status', 'completed')
        ->assertJsonPath('data.service_session.commission_preview.preview_status', 'not_configured')
        ->assertJsonPath('data.service_session.commission_preview.earned', false)
        ->assertJsonPath('data.service_session.commission_preview.payable', false)
        ->assertJsonPath('data.service_session.commission_preview.amount_minor', null);

    expect(ServiceSession::query()->where('status', 'completed')->count())->toBe(1);
    // Phase 16C creates no financial/compensation tables.
    expect(Schema::hasTable('invoices'))->toBeFalse()
        ->and(Schema::hasTable('commission_ledger'))->toBeFalse();
});

it('does not allow a non-called queue entry to start a session', function (): void {
    $scn = queueScenario();

    // Fresh assigned walk-in (not yet called).
    $ulid = createWalkIn($scn)->json('data.id');

    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/start")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');

    expect(ServiceSession::query()->count())->toBe(0);
});
