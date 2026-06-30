<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Branches\Services\BranchClosureGuard;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'queue', 'queue-closure');

it('blocks branch archival and day close while an active queue entry exists', function (string $status): void {
    $scn = queueScenario();
    QueueEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'status' => $status, 'position' => 1,
        'assigned_at' => in_array($status, ['assigned', 'called', 'in_service'], true) ? now() : null,
        'called_at' => in_array($status, ['called', 'in_service'], true) ? now() : null,
        'started_at' => $status === 'in_service' ? now() : null,
        'transferred_at' => $status === 'transferred' ? now() : null,
        'transfer_reason' => $status === 'transferred' ? 'swap' : null,
    ]);

    $guard = app(BranchClosureGuard::class);
    $branch = $scn['branch'];

    expect($guard->blockers($branch))->toContain('active_queue_entries')
        ->and($guard->dayCloseBlockers($branch, now('Africa/Nairobi')->toDateString()))->toContain('active_queue_entries');
})->with(['waiting', 'assigned', 'called', 'in_service', 'transferred']);

it('does not block while only terminal queue entries exist', function (string $status): void {
    $scn = queueScenario();
    QueueEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id,
        'status' => $status, 'position' => 1,
        'completed_at' => $status === 'completed' ? now() : null,
        'assigned_at' => $status === 'completed' ? now() : null,
        'called_at' => $status === 'completed' ? now() : null,
        'started_at' => $status === 'completed' ? now() : null,
        'cancelled_at' => $status === 'cancelled' ? now() : null,
        'cancellation_reason' => $status === 'cancelled' ? 'left' : null,
        'no_show_at' => $status === 'no_show' ? now() : null,
    ]);

    $guard = app(BranchClosureGuard::class);

    expect($guard->blockers($scn['branch']))->not->toContain('active_queue_entries')
        ->and($guard->dayCloseBlockers($scn['branch'], now('Africa/Nairobi')->toDateString()))->not->toContain('active_queue_entries');
})->with(['completed', 'cancelled', 'no_show']);

it('does not let another branch or tenant queue block this branch', function (): void {
    $scn = queueScenario();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);

    // Active entry in a DIFFERENT branch of the same merchant.
    QueueEntry::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $otherBranch->id,
        'status' => QueueEntryStatus::Waiting, 'position' => 1,
    ]);
    // Active entry in a DIFFERENT merchant entirely.
    QueueEntry::factory()->create(['status' => QueueEntryStatus::Waiting, 'position' => 1]);

    expect(app(BranchClosureGuard::class)->blockers($scn['branch']))->not->toContain('active_queue_entries');
});
