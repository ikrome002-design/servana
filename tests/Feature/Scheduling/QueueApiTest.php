<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\WalkIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('scheduling', 'queue', 'queue-api');

it('lets Front Office create a walk-in for an existing client (next-available assigns)', function (): void {
    $scn = queueScenario();

    $response = createWalkIn($scn)->assertStatus(201);

    $response->assertJsonPath('data.status', 'assigned')
        ->assertJsonPath('data.client.id', $scn['client']->ulid)
        ->assertJsonPath('data.service.id', $scn['service']->ulid)
        ->assertJsonPath('data.position', 1)
        ->assertJsonPath('data.estimated_wait.label', 'Estimate');

    expect(strlen((string) $response->json('data.id')))->toBe(26)
        ->and($response->json('data.client.phone_masked'))->toContain('•')
        ->and($response->json('data.assigned_personnel.id'))->not->toBeNull();

    $response->assertJsonMissingPath('data.client.phone_index')
        ->assertJsonMissingPath('data.merchant_id');
});

it('atomically creates a brand-new client with the walk-in', function (): void {
    $scn = queueScenario();

    $response = createWalkIn($scn, [
        'client' => null,
        'new_client' => ['full_name' => 'Walkin Wanjiku', 'phone' => '0712345678'],
    ])->assertStatus(201);

    $response->assertJsonPath('data.client.full_name', 'Walkin Wanjiku');
    $this->assertDatabaseHas('clients', ['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
});

it('rolls back everything when the service is invalid (no client/walk-in/queue rows)', function (): void {
    $scn = queueScenario();

    createWalkIn($scn, [
        'client' => null,
        'new_client' => ['full_name' => 'Ghost Client', 'phone' => '0700000111'],
        'service' => (string) Str::ulid(),
    ])->assertNotFound();

    expect(QueueEntry::query()->count())->toBe(0)
        ->and(WalkIn::query()->count())->toBe(0);
    $this->assertDatabaseMissing('clients', ['full_name' => 'Ghost Client']);
});

it('ignores body-supplied ownership/status/position', function (): void {
    $scn = queueScenario();

    $response = createWalkIn($scn, [
        'merchant_id' => 999999,
        'branch_id' => null,
        'status' => 'completed',
        'position' => 99,
    ])->assertStatus(201);

    $response->assertJsonPath('data.position', 1);
    $entry = QueueEntry::query()->where('ulid', $response->json('data.id'))->firstOrFail();
    expect($entry->merchant_id)->toBe($scn['merchant']->id)->and($entry->branch_id)->toBe($scn['branch']->id);
});

it('runs the full lifecycle assign → call → start → complete', function (): void {
    $scn = queueScenario();
    $fo = $scn['frontOffice'];

    $ulid = createWalkIn($scn)->json('data.id'); // assigned

    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/call")
        ->assertOk()->assertJsonPath('data.status', 'called');
    // Phase 16C: start couples a service session (pending → in_progress) onto the
    // queue called → in_service transition.
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/start")
        ->assertOk()
        ->assertJsonPath('data.status', 'in_service')
        ->assertJsonPath('data.service_session.status', 'in_progress');
    // Complete completes both aggregates and returns a non-payable preview.
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.service_session.status', 'completed')
        ->assertJsonPath('data.service_session.commission_preview.earned', false)
        ->assertJsonPath('data.service_session.commission_preview.payable', false);

    // The queue lifecycle creates NO invoice. The `invoices` table now exists
    // (Phase 17 owns it), so the invariant is asserted at the ROW level — completing
    // a queue entry/session never auto-creates an invoice (invoicing is a separate
    // Front Office action).
    expect(DB::table('invoices')->count())->toBe(0);
});

it('cancels with a required reason and marks a separate entry no-show', function (): void {
    $scn = queueScenario();
    $fo = $scn['frontOffice'];

    $a = createWalkIn($scn)->json('data.id');
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$a}/cancel")
        ->assertStatus(422); // reason required
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$a}/cancel", ['reason' => 'Client left'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    $client2 = Client::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
    $b = createWalkIn($scn, ['client' => $client2->ulid])->json('data.id');
    $this->actingAs($fo, 'sanctum')->postJson("/api/v1/queue-entries/{$b}/no-show")
        ->assertOk()->assertJsonPath('data.status', 'no_show');
});

it('transfers an entry to another eligible personnel with a reason', function (): void {
    $scn = queueScenario();
    $ulid = createWalkIn($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid])->json('data.id');

    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/transfer", [
        'personnel' => $scn['staff2']->ulid,
        'reason' => 'Staff swap',
    ])->assertOk()
        ->assertJsonPath('data.status', 'assigned')
        ->assertJsonPath('data.assigned_personnel.id', $scn['staff2']->ulid);
});

it('rejects an invalid transition with 422 invalid_state_transition', function (): void {
    $scn = queueScenario();
    $ulid = createWalkIn($scn)->json('data.id'); // assigned

    // assigned cannot be completed directly.
    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/complete")
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');
});

it('converts a checked-in appointment to the queue exactly once', function (): void {
    $scn = queueScenario();
    $appointment = Appointment::factory()->checkedIn()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id, 'service_id' => $scn['service']->id,
    ]);

    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$appointment->ulid}/queue")
        ->assertStatus(201)->assertJsonPath('data.source.type', 'appointment');

    expect($appointment->refresh()->status->value)->toBe('queued');

    // Second conversion is a deterministic conflict.
    $this->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/appointments/{$appointment->ulid}/queue")
        ->assertStatus(409)->assertJsonPath('error.code', 'queue_conversion_exists');
});

it('rejects creation when the queue is closed, the day is not open, or capacity is reached', function (): void {
    // Closed queue.
    $closed = queueScenario();
    $closed['day']->update(['queue_is_open' => false]);
    createWalkIn($closed)->assertStatus(409)->assertJsonPath('error.code', 'queue_closed');

    // Day not open.
    $paused = queueScenario();
    $paused['day']->update(['status' => BranchDayStatus::Paused]);
    createWalkIn($paused)->assertStatus(409)->assertJsonPath('error.code', 'branch_day_not_open');

    // Capacity reached.
    $capped = queueScenario(capacity: 1);
    createWalkIn($capped)->assertStatus(201);
    $client2 = Client::factory()->create(['merchant_id' => $capped['merchant']->id, 'branch_id' => $capped['branch']->id]);
    createWalkIn($capped, ['client' => $client2->ulid])->assertStatus(409)->assertJsonPath('error.code', 'queue_capacity_reached');
});

it('lets Branch Manager read the queue but denies every operational mutation', function (): void {
    $scn = queueScenario();
    $ulid = createWalkIn($scn)->json('data.id');
    $bm = $scn['branchManager'];

    $this->actingAs($bm, 'sanctum')->getJson('/api/v1/queue-entries')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($bm, 'sanctum')->getJson("/api/v1/queue-entries/{$ulid}")->assertOk();

    $this->actingAs($bm, 'sanctum')->postJson('/api/v1/walk-ins', ['assignment_mode' => 'next_available', 'service' => $scn['service']->ulid, 'client' => $scn['client']->ulid])->assertForbidden();
    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/assign", ['assignment_mode' => 'next_available'])->assertForbidden();
    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/transfer", ['personnel' => $scn['staff2']->ulid, 'reason' => 'x'])->assertForbidden();
    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/call")->assertForbidden();
    $this->actingAs($bm, 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/cancel", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($bm, 'sanctum')->putJson('/api/v1/queue-entries/reorder', ['order' => [$ulid]])->assertForbidden();
});

it('lets Branch Manager configure the queue but capacity below active is rejected', function (): void {
    $scn = queueScenario();
    createWalkIn($scn)->assertStatus(201); // active = 1

    $this->actingAs($scn['branchManager'], 'sanctum')->getJson('/api/v1/queue/configuration')
        ->assertOk()->assertJsonPath('data.queue_is_open', true);

    $this->actingAs($scn['branchManager'], 'sanctum')->putJson('/api/v1/queue/configuration', ['queue_capacity' => 5, 'queue_default_assignment_mode' => 'manual'])
        ->assertOk()->assertJsonPath('data.queue_capacity', 5);

    // Below the current active count → 422.
    $this->actingAs($scn['branchManager'], 'sanctum')->putJson('/api/v1/queue/configuration', ['queue_capacity' => 0])
        ->assertStatus(422); // min:1 validation
});

it('denies Front Office the queue configuration update', function (): void {
    $scn = queueScenario();

    $this->actingAs($scn['frontOffice'], 'sanctum')->putJson('/api/v1/queue/configuration', ['queue_is_open' => false])
        ->assertForbidden();
});

it('denies queue operations to HR, Finance and Audit', function (): void {
    $scn = queueScenario();
    $payload = ['assignment_mode' => 'next_available', 'service' => $scn['service']->ulid, 'client' => $scn['client']->ulid];

    foreach ([MerchantUserRole::Hr, MerchantUserRole::Finance, MerchantUserRole::Audit] as $role) {
        [$user] = branchStaff($scn['merchant'], $scn['branch'], $role);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/walk-ins', $payload)->assertForbidden();
    }
});

it('gives the Super Administrator no merchant queue route', function (): void {
    $platform = User::factory()->platformStaff()->create();

    $this->actingAs($platform, 'sanctum')->getJson('/api/v1/queue-entries')->assertForbidden();
});

it('lets Personnel see only their own assigned queue', function (): void {
    $scn = queueScenario();

    // Assign one to staff, one to staff2.
    createWalkIn($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid])->assertStatus(201);
    $client2 = Client::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
    createWalkIn($scn, ['client' => $client2->ulid, 'assignment_mode' => 'manual', 'personnel' => $scn['staff2']->ulid])->assertStatus(201);

    $response = $this->actingAs($scn['staffUser'], 'sanctum')->getJson('/api/v1/personnel/me/queue')->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('returns 404 for a foreign-tenant queue entry binding', function (): void {
    $scn = queueScenario();
    $ulid = createWalkIn($scn)->json('data.id');

    $other = queueScenario();
    $this->actingAs($other['frontOffice'], 'sanctum')->getJson("/api/v1/queue-entries/{$ulid}")->assertNotFound();
});
