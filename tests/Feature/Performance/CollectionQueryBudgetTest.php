<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('performance', 'query-budget');

/*
|--------------------------------------------------------------------------
| Phase 24 - collection endpoint N+1 guards (Plan §72)
|--------------------------------------------------------------------------
|
| §72: "N+1 queries are prohibited and tested." These assert the STRUCTURAL
| invariant that a collection endpoint's query count does not grow with the
| number of rows returned. That is deterministic on any hardware, so it is safe
| to gate in ordinary CI (benchmark profile §3.1) - unlike wall-clock latency,
| which is verified separately in the Phase 24 benchmark runs.
|
| The assertion is equality, not a magic budget: framework/auth overhead is
| whatever it is, and is identical between the two measurements. Any growth is
| therefore per-row work, i.e. an N+1. This survives framework upgrades that
| change the constant overhead, which a hard-coded total would not.
|
*/

/** Mutable counter shared with the DB listener. */
function queryCounter(): object
{
    return new class
    {
        public int $n = 0;
    };
}

/**
 * Assert that a collection endpoint costs the same number of queries when it returns many rows as
 * when it returns few.
 *
 * `$seed` is invoked to add more rows between the two measurements; only the request itself is
 * counted, so fixture writes never pollute the measurement.
 */
function expectFlatQueryCount(User $actor, string $uri, Closure $seed): void
{
    $counter = queryCounter();
    DB::listen(static function () use ($counter): void {
        $counter->n++;
    });

    // Warm: resolve permission registry / config caches so the first request's one-off lookups do
    // not masquerade as per-row growth.
    test()->actingAs($actor, 'sanctum')->getJson($uri)->assertOk();

    $counter->n = 0;
    test()->actingAs($actor, 'sanctum')->getJson($uri)->assertOk();
    $few = $counter->n;

    $seed();

    $counter->n = 0;
    $response = test()->actingAs($actor, 'sanctum')->getJson($uri);
    $response->assertOk();
    $many = $counter->n;

    $returned = count((array) ($response->json('data') ?? []));

    expect($many)->toBe($few, sprintf(
        '%s issued %d queries for a short page and %d for a page of %d rows. A collection endpoint '
        .'must not query per row - eager-load the relations its Resource serialises.',
        $uri,
        $few,
        $many,
        $returned,
    ));
}

it('serves clients without querying per row', function (): void {
    $scn = queueScenario();
    $actor = $scn['frontOffice'];

    foreach (range(1, 3) as $i) {
        Client::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
    }

    expectFlatQueryCount($actor, '/api/v1/clients', function () use ($scn): void {
        foreach (range(1, 20) as $i) {
            Client::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
        }
    });
});

it('serves the queue without querying per entry', function (): void {
    $scn = queueScenario();
    $actor = $scn['frontOffice'];

    $position = 0;
    $addEntries = function (int $count) use ($scn, &$position): void {
        for ($i = 0; $i < $count; $i++) {
            $position++;
            $client = Client::factory()->create([
                'merchant_id' => $scn['merchant']->id,
                'branch_id' => $scn['branch']->id,
            ]);
            QueueEntry::factory()->atPosition($position)->create([
                'merchant_id' => $scn['merchant']->id,
                'branch_id' => $scn['branch']->id,
                'service_id' => $scn['service']->id,
                'client_id' => $client->id,
                'status' => QueueEntryStatus::Waiting,
            ]);
        }
    };

    $addEntries(3);

    expectFlatQueryCount($actor, '/api/v1/queue-entries', fn () => $addEntries(15));
});

it('serves service sessions without querying per session', function (): void {
    $scn = queueScenario();
    $actor = $scn['frontOffice'];

    $seq = 0;
    $addSessions = function (int $count) use ($scn, &$seq): void {
        for ($i = 0; $i < $count; $i++) {
            $seq++;
            $client = Client::factory()->create([
                'merchant_id' => $scn['merchant']->id,
                'branch_id' => $scn['branch']->id,
            ]);
            $entry = QueueEntry::factory()->atPosition(1000 + $seq)->create([
                'merchant_id' => $scn['merchant']->id,
                'branch_id' => $scn['branch']->id,
                'service_id' => $scn['service']->id,
                'client_id' => $client->id,
                'staff_profile_id' => $scn['staff']->id,
                'status' => QueueEntryStatus::Completed,
                'assigned_at' => now()->subMinutes(40),
                'called_at' => now()->subMinutes(35),
                'started_at' => now()->subMinutes(30),
                'completed_at' => now()->subMinutes(5),
            ]);
            ServiceSession::factory()->create([
                'merchant_id' => $scn['merchant']->id,
                'branch_id' => $scn['branch']->id,
                'service_id' => $scn['service']->id,
                'client_id' => $client->id,
                'staff_profile_id' => $scn['staff']->id,
                'queue_entry_id' => $entry->id,
                'status' => ServiceSessionStatus::Completed,
                'started_at' => now()->subMinutes(30),
                'completed_at' => now()->subMinutes(5),
            ]);
        }
    };

    $addSessions(3);

    expectFlatQueryCount($actor, '/api/v1/service-sessions', fn () => $addSessions(15));
});

it('serves appointments without querying per appointment', function (): void {
    $scn = appointmentScenario();
    $actor = $scn['frontOffice'];

    $addAppointments = function (int $count) use ($scn): void {
        for ($i = 0; $i < $count; $i++) {
            $client = Client::factory()->create([
                'merchant_id' => $scn['merchant']->id,
                'branch_id' => $scn['branch']->id,
            ]);
            Appointment::factory()->create([
                'merchant_id' => $scn['merchant']->id,
                'branch_id' => $scn['branch']->id,
                'service_id' => $scn['service']->id,
                'client_id' => $client->id,
            ]);
        }
    };

    $addAppointments(3);

    expectFlatQueryCount($actor, '/api/v1/appointments', fn () => $addAppointments(15));
});
