<?php

declare(strict_types=1);

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'queue', 'queue-estimate');

/** N waiting entries ahead (positions 1..N) on the scenario's 30-min service. */
function aheadEntries(array $scn, int $count): void
{
    foreach (range(1, $count) as $p) {
        $client = Client::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
        QueueEntry::factory()->atPosition($p)->create([
            'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
            'service_id' => $scn['service']->id, 'client_id' => $client->id,
            'status' => QueueEntryStatus::Waiting,
        ]);
    }
}

function targetEntry(array $scn, int $position): QueueEntry
{
    return QueueEntry::factory()->atPosition($position)->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id, 'client_id' => $scn['client']->id,
        'status' => QueueEntryStatus::Waiting,
    ]);
}

it('divides queued work by the count of available eligible personnel', function (): void {
    $scn = queueScenario(); // 2 available staff, 30-min service
    aheadEntries($scn, 3);   // 90 minutes of work ahead
    $target = targetEntry($scn, 4);

    // ceil(90 / 2) = 45.
    expect(app(QueueWaitEstimator::class)->estimateFor($target))->toBe(45);
});

it('grows the estimate when fewer personnel are available', function (): void {
    $scn = queueScenario();
    ServicePersonnelEligibility::query()
        ->where('service_id', $scn['service']->id)
        ->where('staff_profile_id', $scn['staff2']->id)
        ->delete(); // only one eligible now
    aheadEntries($scn, 3);
    $target = targetEntry($scn, 4);

    // ceil(90 / 1) = 90.
    expect(app(QueueWaitEstimator::class)->estimateFor($target))->toBe(90);
});

it('grows the estimate with queue length / order ahead', function (): void {
    $scn = queueScenario();
    aheadEntries($scn, 2);
    $target = targetEntry($scn, 3);

    // ceil(60 / 2) = 30 (vs 45 for three ahead).
    expect(app(QueueWaitEstimator::class)->estimateFor($target))->toBe(30);
});

it('returns a safe finite estimate when zero personnel are eligible (no division by zero)', function (): void {
    $scn = queueScenario();
    ServicePersonnelEligibility::query()->where('service_id', $scn['service']->id)->delete();
    aheadEntries($scn, 2);
    $target = targetEntry($scn, 3);

    // capacity = max(1, 0) = 1 → ceil(60 / 1) = 60 (finite, no error).
    expect(app(QueueWaitEstimator::class)->estimateFor($target))->toBe(60);
});

it('recalculates and persists estimates across the active branch queue', function (): void {
    $scn = queueScenario();
    aheadEntries($scn, 2);
    $target = targetEntry($scn, 3);

    app(QueueWaitEstimator::class)->recalculateBranch($scn['branch']->id);

    expect($target->refresh()->estimated_wait_minutes)->toBe(30)
        ->and($target->effectiveWaitMinutes())->toBe(30);
});
