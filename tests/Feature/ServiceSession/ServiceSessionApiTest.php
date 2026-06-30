<?php

declare(strict_types=1);

use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'service-session', 'service-session-api');

/** A pending session owned by the scenario's branch + a given staff member. */
function pendingSession(array $scn, $staff): ServiceSession
{
    return ServiceSession::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'service_id' => $scn['service']->id,
        'staff_profile_id' => $staff->id,
        'queue_entry_id' => null,
        'status' => 'pending',
    ]);
}

it('lets Front Office list and view service sessions (masked client)', function (): void {
    $scn = queueScenario();
    startQueueSession($scn);

    test()->actingAs($scn['frontOffice'], 'sanctum')->getJson('/api/v1/service-sessions')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'in_progress')
        ->assertJsonStructure(['data' => [['id', 'status', 'client' => ['phone_masked']]]]);

    $session = ServiceSession::query()->firstOrFail();
    $detail = test()->actingAs($scn['frontOffice'], 'sanctum')->getJson("/api/v1/service-sessions/{$session->ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $session->ulid);

    expect($detail->json('data.client.phone_masked'))->toContain('•')   // masked, not the full number
        ->and($detail->json('data.client'))->not->toHaveKey('phone')
        ->and($detail->json('data.client'))->not->toHaveKey('phone_encrypted')
        ->and($detail->json('data'))->not->toHaveKey('staff_profile_id'); // no bigint id
});

it('lets Front Office cancel a pending session with a required reason', function (): void {
    $scn = queueScenario();
    $session = pendingSession($scn, $scn['staff']);

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/service-sessions/{$session->ulid}/cancel", ['reason' => 'Client left.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($session->fresh()->cancellation_reason)->toBe('Client left.');
});

it('rejects cancellation without a reason', function (): void {
    $scn = queueScenario();
    $session = pendingSession($scn, $scn['staff']);

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/service-sessions/{$session->ulid}/cancel", ['reason' => ''])
        ->assertStatus(422);
});

it('refuses to cancel an in-progress queue-linked session (Gate C deferral)', function (): void {
    $scn = queueScenario();
    startQueueSession($scn);
    $session = ServiceSession::query()->firstOrFail();

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/service-sessions/{$session->ulid}/cancel", ['reason' => 'Abort'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'service_session_in_progress');

    expect($session->fresh()->status->value)->toBe('in_progress');
});

it('lets Front Office edit service notes on a non-terminal session', function (): void {
    $scn = queueScenario();
    startQueueSession($scn);
    $session = ServiceSession::query()->firstOrFail();

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->patchJson("/api/v1/service-sessions/{$session->ulid}/notes", ['notes' => 'Used product X.'])
        ->assertOk()
        ->assertJsonPath('data.notes', 'Used product X.');
});

it('denies all service-session access to the Branch Manager', function (): void {
    $scn = queueScenario();
    $session = pendingSession($scn, $scn['staff']);
    $bm = $scn['branchManager'];

    test()->actingAs($bm, 'sanctum')->getJson('/api/v1/service-sessions')->assertStatus(403);
    test()->actingAs($bm, 'sanctum')->getJson("/api/v1/service-sessions/{$session->ulid}")->assertStatus(403);
    test()->actingAs($bm, 'sanctum')
        ->postJson("/api/v1/service-sessions/{$session->ulid}/cancel", ['reason' => 'x'])->assertStatus(403);
});

it('lets Personnel see only their own sessions and denies mutation', function (): void {
    $scn = queueScenario();
    // One session for staff, one for staff2.
    startQueueSession($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid]);
    $ownSession = ServiceSession::query()->firstOrFail();
    $othersSession = pendingSession($scn, $scn['staff2']);

    test()->actingAs($scn['staffUser'], 'sanctum')->getJson('/api/v1/personnel/me/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownSession->ulid);

    // Personnel cannot use the Front Office detail route (no service_session.view).
    test()->actingAs($scn['staffUser'], 'sanctum')
        ->getJson("/api/v1/service-sessions/{$othersSession->ulid}")->assertStatus(403);
    test()->actingAs($scn['staffUser'], 'sanctum')
        ->postJson("/api/v1/service-sessions/{$ownSession->ulid}/cancel", ['reason' => 'x'])->assertStatus(403);
});

it('does not expose another personnel member’s session to Personnel my-sessions', function (): void {
    $scn = queueScenario();
    pendingSession($scn, $scn['staff2']); // belongs to staff2

    test()->actingAs($scn['staffUser'], 'sanctum')->getJson('/api/v1/personnel/me/sessions')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns 404 for a foreign-tenant session binding', function (): void {
    $scn = queueScenario();
    $foreign = ServiceSession::factory()->create(); // different merchant/branch

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->getJson("/api/v1/service-sessions/{$foreign->ulid}")->assertStatus(404);
});
