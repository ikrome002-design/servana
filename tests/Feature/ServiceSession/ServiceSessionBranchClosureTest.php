<?php

declare(strict_types=1);

use App\Domain\Branches\Services\BranchClosureGuard;
use App\Domain\Scheduling\Enums\PersonnelAvailabilityState;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Scheduling\Services\PersonnelStateProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scheduling', 'service-session', 'service-session-closure');

it('blocks branch archival and day close while an active session exists', function (string $status): void {
    $scn = queueScenario();
    $factory = ServiceSession::factory();
    $factory = $status === 'in_progress' ? $factory->inProgress() : $factory;
    $factory->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'service_id' => $scn['service']->id,
        'staff_profile_id' => $scn['staff']->id,
        'queue_entry_id' => null,
        'status' => $status,
        'started_at' => $status === 'in_progress' ? now() : null,
    ]);

    $guard = app(BranchClosureGuard::class);
    expect($guard->blockers($scn['branch']->fresh()))->toContain('in_progress_sessions')
        ->and($guard->dayCloseBlockers($scn['branch']->fresh(), now('Africa/Nairobi')->toDateString()))
        ->toContain('in_progress_sessions');
})->with(['pending', 'in_progress']);

it('does not block while only terminal sessions exist', function (string $state): void {
    $scn = queueScenario();
    ServiceSession::factory()->{$state}()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'service_id' => $scn['service']->id,
        'staff_profile_id' => $scn['staff']->id,
        'queue_entry_id' => null,
    ]);

    $guard = app(BranchClosureGuard::class);
    expect($guard->blockers($scn['branch']->fresh()))->not->toContain('in_progress_sessions');
})->with(['completed', 'cancelled']);

it('does not let another branch or tenant session block this branch', function (): void {
    $scn = queueScenario();
    ServiceSession::factory()->inProgress()->create(); // different merchant/branch

    $guard = app(BranchClosureGuard::class);
    expect($guard->blockers($scn['branch']->fresh()))->not->toContain('in_progress_sessions');
});

it('projects a personnel member with an in-progress session as busy', function (): void {
    $scn = queueScenario();
    startQueueSession($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid]);

    $projector = app(PersonnelStateProjector::class);
    expect($projector->currentState($scn['staff']->fresh()))->toBe(PersonnelAvailabilityState::Busy)
        ->and($projector->isBusy($scn['staff']->fresh()))->toBeTrue();
});

it('clears busy when the session completes', function (): void {
    $scn = queueScenario();
    $ulid = startQueueSession($scn, ['assignment_mode' => 'manual', 'personnel' => $scn['staff']->ulid])['ulid'];

    test()->actingAs($scn['frontOffice'], 'sanctum')->postJson("/api/v1/queue-entries/{$ulid}/complete")->assertOk();

    $projector = app(PersonnelStateProjector::class);
    expect($projector->isBusy($scn['staff']->fresh()))->toBeFalse()
        ->and($projector->currentState($scn['staff']->fresh()))->not->toBe(PersonnelAvailabilityState::Busy);
});
