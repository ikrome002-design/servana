<?php

declare(strict_types=1);

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('performance', 'queue', 'queue-estimate');

/*
|--------------------------------------------------------------------------
| Phase 24 - queue wait estimator query budget (Plan §72; PH24-QUEUE-001)
|--------------------------------------------------------------------------
|
| §72 prohibits N+1 queries and requires them to be TESTED. These are structural
| assertions, not latency assertions: they compare query COUNTS, so they are
| deterministic on any hardware and safe to gate in ordinary CI (benchmark
| profile §3.1).
|
| The strongest available formulation is used - the same workload is measured at
| two different sizes and the query count must NOT grow with the queue length or
| the eligible-personnel count. An implementation that re-resolves availability
| per entry fails this by construction, which is exactly how PH24-QUEUE-001 was
| proven before it was fixed.
|
*/

/**
 * Extend the shared queue scenario with extra eligible + available personnel, so the eligible set
 * is large enough for per-staff query amplification to be visible.
 *
 * @param  array<string, mixed>  $scn
 * @return list<StaffProfile>
 */
function extraEligiblePersonnel(array $scn, int $count): array
{
    $weekday = now(config('servana.scheduling.business_timezone'))->dayOfWeek;
    $added = [];

    for ($i = 0; $i < $count; $i++) {
        $profile = StaffProfile::factory()->create([
            'merchant_id' => $scn['merchant']->id,
            'primary_branch_id' => $scn['branch']->id,
        ]);

        ServicePersonnelEligibility::query()->create([
            'merchant_id' => $scn['merchant']->id,
            'branch_id' => $scn['branch']->id,
            'service_id' => $scn['service']->id,
            'staff_profile_id' => $profile->id,
            'active' => true,
        ]);

        PersonnelAvailability::query()->create([
            'merchant_id' => $scn['merchant']->id,
            'branch_id' => $scn['branch']->id,
            'staff_profile_id' => $profile->id,
            'type' => 'recurring',
            'weekday' => $weekday,
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'available' => true,
        ]);

        $added[] = $profile;
    }

    return $added;
}

/**
 * Fill the branch queue with `$count` waiting entries at positions 1..N.
 *
 * @param  array<string, mixed>  $scn
 */
function waitingQueueOfSize(array $scn, int $count): void
{
    for ($p = 1; $p <= $count; $p++) {
        $client = Client::factory()->create([
            'merchant_id' => $scn['merchant']->id,
            'branch_id' => $scn['branch']->id,
        ]);

        QueueEntry::factory()->atPosition($p)->create([
            'merchant_id' => $scn['merchant']->id,
            'branch_id' => $scn['branch']->id,
            'service_id' => $scn['service']->id,
            'client_id' => $client->id,
            'status' => QueueEntryStatus::Waiting,
        ]);
    }
}

/**
 * Put a personnel member into a live (`in_progress`) service session.
 *
 * The session's SOURCE queue entry is created explicitly at `$position`, which the caller places
 * BEHIND the entry under test. `ServiceSessionFactory` otherwise derives its own `in_service` queue
 * entry at position 1, which would silently add that service's duration to the "work ahead" of the
 * target and change the expected estimate for a reason that has nothing to do with capacity.
 *
 * @param  array<string, mixed>  $scn
 */
function personnelInLiveSession(array $scn, StaffProfile $member, int $position): ServiceSession
{
    $client = Client::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
    ]);

    $sourceEntry = QueueEntry::factory()->atPosition($position)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id,
        'client_id' => $client->id,
        'staff_profile_id' => $member->id,
        'status' => QueueEntryStatus::InService,
        'assigned_at' => now()->subMinutes(20),
        'called_at' => now()->subMinutes(15),
        'started_at' => now()->subMinutes(10),
    ]);

    return ServiceSession::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id,
        'client_id' => $client->id,
        'staff_profile_id' => $member->id,
        'queue_entry_id' => $sourceEntry->id,
        'status' => ServiceSessionStatus::InProgress,
        'started_at' => now()->subMinutes(10),
    ]);
}

/** Count the SELECT/INSERT/UPDATE statements a callable issues. */
function queriesDuring(Closure $callback): int
{
    $count = 0;
    DB::listen(static function () use (&$count): void {
        $count++;
    });

    $callback();

    // Pest resolves a fresh app per test, so the listener does not leak between tests.
    return $count;
}

it('does not grow queries with the number of eligible personnel (PH24-QUEUE-001)', function (): void {
    $small = queueScenario();
    waitingQueueOfSize($small, 6);
    $smallCount = queriesDuring(fn () => app(QueueWaitEstimator::class)->recalculateBranch($small['branch']->id));

    $large = queueScenario();
    extraEligiblePersonnel($large, 10); // 2 -> 12 eligible personnel
    waitingQueueOfSize($large, 6);
    $largeCount = queriesDuring(fn () => app(QueueWaitEstimator::class)->recalculateBranch($large['branch']->id));

    expect($largeCount)->toBe(
        $smallCount,
        sprintf(
            'Recalculating an identical 6-entry queue issued %d queries with 2 eligible personnel and '
            .'%d with 12. Availability must be resolved once for the branch, not once per personnel '
            .'per entry.',
            $smallCount,
            $largeCount,
        ),
    );
});

it('costs at most one query per additional queue entry (PH24-QUEUE-001, PH24-QUEUE-003)', function (): void {
    $short = queueScenario();
    extraEligiblePersonnel($short, 6);
    waitingQueueOfSize($short, 4);
    $shortCount = queriesDuring(fn () => app(QueueWaitEstimator::class)->recalculateBranch($short['branch']->id));

    $long = queueScenario();
    extraEligiblePersonnel($long, 6);
    waitingQueueOfSize($long, 16);
    $longCount = queriesDuring(fn () => app(QueueWaitEstimator::class)->recalculateBranch($long['branch']->id));

    // The ONLY legitimate per-entry cost is persisting that entry's own recalculated estimate, so
    // 12 extra entries may cost at most 12 extra queries. Everything else — the entry load, the
    // capacity resolution and the search re-index — is constant per recalculation. This fails if
    // availability is re-resolved per entry (the original defect) or if the search index is synced
    // per save (PH24-QUEUE-003), because either makes the marginal cost several queries per entry.
    $marginal = $longCount - $shortCount;

    expect($marginal)->toBeLessThanOrEqual(
        12,
        sprintf(
            'A 4-entry queue cost %d queries and a 16-entry queue cost %d (marginal %d for 12 extra '
            .'entries). Only the per-entry estimate write may scale with queue length; capacity '
            .'resolution and search indexing must be resolved once per recalculation.',
            $shortCount,
            $longCount,
            $marginal,
        ),
    );
});

it('syncs the search index once per recalculation, not once per saved entry (PH24-QUEUE-003)', function (): void {
    $scn = queueScenario();
    extraEligiblePersonnel($scn, 4);
    waitingQueueOfSize($scn, 8);

    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    app(QueueWaitEstimator::class)->recalculateBranch($scn['branch']->id);

    // Scout eager-loads each document's index relations before building it. If indexing runs per
    // save, the relation loads repeat per entry; batched, they appear exactly once.
    $clientLoads = count(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, 'from "clients" where "clients"."id" in'),
    ));

    expect($clientLoads)->toBeLessThanOrEqual(
        1,
        "Scout loaded the index relations {$clientLoads} times for one recalculation; it must batch.",
    );
});

it('keeps a single estimate resolution to a small constant query budget', function (): void {
    $scn = queueScenario();
    extraEligiblePersonnel($scn, 8);
    waitingQueueOfSize($scn, 5);

    $target = QueueEntry::query()
        ->where('branch_id', $scn['branch']->id)
        ->orderByDesc('position')
        ->firstOrFail();

    $count = queriesDuring(fn () => app(QueueWaitEstimator::class)->estimateFor($target));

    // entries-ahead + eager-loaded services + eligibility + staff + one availability fetch.
    expect($count)->toBeLessThanOrEqual(
        6,
        "estimateFor() issued {$count} queries; it must not query availability once per personnel.",
    );
});

/*
|--------------------------------------------------------------------------
| PH24-QUEUE-002 - busy personnel must not count as capacity
|--------------------------------------------------------------------------
|
| Behavioural, not performance: `active_capacity` is the divisor of the
| deterministic estimate, so counting a personnel member who is mid-session
| under-states the wait shown to a client. The authoritative busy projection is
| PersonnelStateProjector (an `in_progress` ServiceSession ⇒ `busy`), already
| used by the availability read.
|
*/

it('excludes personnel with an in-progress session from active capacity (PH24-QUEUE-002)', function (): void {
    $scn = queueScenario(); // 2 eligible + available personnel, 30-min service
    waitingQueueOfSize($scn, 3); // 90 minutes of work ahead
    $client = Client::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
    ]);
    $target = QueueEntry::factory()->atPosition(4)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id,
        'client_id' => $client->id,
        'status' => QueueEntryStatus::Waiting,
    ]);

    // Both free: ceil(90 / 2) = 45.
    expect(app(QueueWaitEstimator::class)->estimateFor($target))->toBe(45);

    // Put one of the two into a live session BEHIND the target, so the only thing that changes is
    // capacity — capacity must drop to 1: ceil(90 / 1) = 90.
    $session = personnelInLiveSession($scn, $scn['staff'], position: 10);

    expect(app(QueueWaitEstimator::class)->estimateFor($target->fresh()))->toBe(90);

    // Completing the session returns the personnel member to capacity — `busy` is derived from live
    // sessions and never stored, so nothing has to be un-set.
    $session->update([
        'status' => ServiceSessionStatus::Completed,
        'completed_at' => now(),
    ]);

    expect(app(QueueWaitEstimator::class)->estimateFor($target->fresh()))->toBe(45);
});

it('still returns a safe finite estimate when every eligible personnel is busy', function (): void {
    $scn = queueScenario();
    waitingQueueOfSize($scn, 2); // 60 minutes ahead
    $client = Client::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
    ]);
    $target = QueueEntry::factory()->atPosition(3)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id,
        'client_id' => $client->id,
        'status' => QueueEntryStatus::Waiting,
    ]);

    $position = 10;
    foreach ([$scn['staff'], $scn['staff2']] as $member) {
        personnelInLiveSession($scn, $member, position: $position++);
    }

    // capacity = max(1, 0) = 1 → ceil(60 / 1) = 60. Finite, no division by zero.
    expect(app(QueueWaitEstimator::class)->estimateFor($target->fresh()))->toBe(60);
});
