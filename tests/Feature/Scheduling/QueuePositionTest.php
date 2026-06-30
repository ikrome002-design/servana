<?php

declare(strict_types=1);

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Actions\CancelQueueEntry;
use App\Domain\Scheduling\Actions\CreateWalkInAndQueueEntry;
use App\Domain\Scheduling\Actions\ReorderQueueEntries;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Exceptions\QueueConflictException;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Services\NextAvailablePersonnelSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('scheduling', 'queue', 'queue-position');

/** Three waiting entries in the scenario branch at positions 1..3. */
function waitingTrio(array $scn): array
{
    return collect([1, 2, 3])->map(fn (int $p): QueueEntry => QueueEntry::factory()->atPosition($p)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'status' => QueueEntryStatus::Waiting,
    ]))->all();
}

it('assigns contiguous unique positions to sequential creates', function (): void {
    $scn = queueScenario();
    // Remove eligibility so walk-ins stay waiting with deterministic positions.
    ServicePersonnelEligibility::query()->where('service_id', $scn['service']->id)->delete();

    $action = app(CreateWalkInAndQueueEntry::class);
    $positions = [];
    foreach (range(1, 4) as $i) {
        $client = Client::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
        $entry = $action->handle($scn['branch'], $scn['frontOffice'], $client, null, $scn['service'], QueueAssignmentMode::NextAvailable);
        $positions[] = $entry->position;
        expect($entry->status)->toBe(QueueEntryStatus::Waiting);
    }

    expect($positions)->toBe([1, 2, 3, 4]);
});

it('reorders waiting entries into the requested order', function (): void {
    $scn = queueScenario();
    [$a, $b, $c] = waitingTrio($scn);

    app(ReorderQueueEntries::class)->handle($scn['branch'], $scn['frontOffice'], [$c->ulid, $a->ulid, $b->ulid]);

    expect($c->refresh()->position)->toBe(1)
        ->and($a->refresh()->position)->toBe(2)
        ->and($b->refresh()->position)->toBe(3);
});

it('rejects a reorder with duplicates, omissions, foreign or stale entries (409)', function (): void {
    $scn = queueScenario();
    [$a, $b, $c] = waitingTrio($scn);
    $action = app(ReorderQueueEntries::class);

    // Duplicate.
    expect(fn () => $action->handle($scn['branch'], $scn['frontOffice'], [$a->ulid, $a->ulid, $b->ulid]))
        ->toThrow(QueueConflictException::class);
    // Omission.
    expect(fn () => $action->handle($scn['branch'], $scn['frontOffice'], [$a->ulid, $b->ulid]))
        ->toThrow(QueueConflictException::class);
    // Foreign / unknown ULID.
    expect(fn () => $action->handle($scn['branch'], $scn['frontOffice'], [$a->ulid, $b->ulid, (string) Str::ulid()]))
        ->toThrow(QueueConflictException::class);
});

it('compacts the waiting sequence after a cancellation', function (): void {
    $scn = queueScenario();
    [$a, $b, $c] = waitingTrio($scn);

    app(CancelQueueEntry::class)->handle($a, $scn['frontOffice'], 'Left the branch');

    // The remaining waiting entries are compacted to a contiguous 1..N.
    $remaining = QueueEntry::query()->where('branch_id', $scn['branch']->id)
        ->orderedActive()->orderBy('position')->pluck('position')->all();

    expect($remaining)->toBe([1, 2]);
});

it('keeps active positions unique and contiguous after many mixed operations', function (): void {
    $scn = queueScenario();
    [$a, $b, $c] = waitingTrio($scn);

    app(CancelQueueEntry::class)->handle($b, $scn['frontOffice'], 'No longer needed');
    app(ReorderQueueEntries::class)->handle($scn['branch'], $scn['frontOffice'], [$c->ulid, $a->ulid]);

    $positions = QueueEntry::query()->where('branch_id', $scn['branch']->id)
        ->orderedActive()->orderBy('position')->pluck('position')->all();

    expect($positions)->toBe([1, 2]) // contiguous
        ->and(count($positions))->toBe(count(array_unique($positions))); // unique
});

it('next-available selection is deterministic by load then last-assignment then ULID', function (): void {
    $scn = queueScenario();
    $selector = app(NextAvailablePersonnelSelector::class);

    $entry = QueueEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id, 'client_id' => $scn['client']->id,
        'status' => QueueEntryStatus::Waiting,
    ]);

    // Both staff have zero load + never assigned → stable tie-break is the lower ULID.
    $expected = collect([$scn['staff'], $scn['staff2']])->sortBy('ulid')->first();
    expect($selector->select($entry)?->id)->toBe($expected->id);

    // Give the chosen one active load → the other becomes preferred.
    QueueEntry::factory()->assigned()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'service_id' => $scn['service']->id, 'client_id' => $scn['client']->id,
        'staff_profile_id' => $expected->id, 'position' => 50,
    ]);

    $other = $expected->id === $scn['staff']->id ? $scn['staff2'] : $scn['staff'];
    expect($selector->select($entry)?->id)->toBe($other->id);
});
