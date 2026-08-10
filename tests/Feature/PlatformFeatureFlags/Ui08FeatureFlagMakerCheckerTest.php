<?php

declare(strict_types=1);

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagChangeRequestStatus;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagChangeRequest;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('platform-feature-flags', 'ui08', 'ui08-feature-flags');

/*
 | COR-UI08-001 §12.3 — maker/checker is a DATABASE CONSTRAINT, not a convention.
 |
 | The claim worth proving is not "the service refuses self-approval" but "a self-approved change
 | cannot exist as a row", so the decisive cases here bypass the service entirely.
 */

function ui08FlagAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);

    return $user;
}

/** Run a statement expected to violate a database guard, inside its own savepoint. */
function ui08FlagGuardViolation(Closure $statement): void
{
    expect(static fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

it('cannot represent a self-approved change as a row', function (): void {
    $requester = ui08FlagAdmin();
    $request = PlatformFeatureFlagChangeRequest::factory()->create([
        'requested_by_user_id' => $requester->id,
    ]);

    // Straight to the database, bypassing policy, controller and service.
    ui08FlagGuardViolation(fn () => DB::table('platform_feature_flag_change_requests')
        ->where('id', $request->id)
        ->update([
            'status' => 'approved',
            'approved_by_user_id' => $requester->id,
            'decided_at' => now(),
        ]));

    expect($request->refresh()->status)->toBe(PlatformFeatureFlagChangeRequestStatus::Pending);
});

it('refuses self-approval at the service too, with a specific code', function (): void {
    $requester = ui08FlagAdmin();
    $flag = PlatformFeatureFlag::factory()->create();
    $request = PlatformFeatureFlagChangeRequest::factory()->for($flag, 'flag')->create([
        'requested_by_user_id' => $requester->id,
    ]);

    test()->statefulMfa(now()->getTimestamp())->actingAs($requester, 'sanctum')
        ->postJson("/api/v1/platform/feature-flag-change-requests/{$request->ulid}/approve", [],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'feature_flag.self_approval_forbidden');

    expect($request->refresh()->status)->toBe(PlatformFeatureFlagChangeRequestStatus::Pending);
});

it('applies an approved change from a different administrator, in one transaction', function (): void {
    $requester = ui08FlagAdmin();
    $approver = ui08FlagAdmin();

    $flag = PlatformFeatureFlag::factory()->create(['state' => PlatformFeatureFlagState::Inactive]);
    $request = PlatformFeatureFlagChangeRequest::factory()->for($flag, 'flag')->create([
        'requested_by_user_id' => $requester->id,
    ]);

    test()->statefulMfa(now()->getTimestamp())->actingAs($approver, 'sanctum')
        ->postJson("/api/v1/platform/feature-flag-change-requests/{$request->ulid}/approve", [],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk()
        ->assertJsonPath('data.status', 'applied');

    $flag->refresh();

    expect($flag->state)->toBe(PlatformFeatureFlagState::Active)
        ->and($flag->version)->toBe(2)
        // What is live is exactly what was approved, answerable by comparison rather than inference.
        ->and($flag->approved_configuration_hash)->toBe($request->refresh()->proposed_configuration_hash)
        ->and($flag->applied_change_request_id)->toBe($request->id);

    // Both the decision and the application are in the append-only history.
    expect(PlatformFeatureFlagHistory::query()->where('action', 'approved')->exists())->toBeTrue()
        ->and(PlatformFeatureFlagHistory::query()->where('action', 'applied')->exists())->toBeTrue();
});

it('requires an impact statement, a rollback plan and a health criterion', function (): void {
    $admin = ui08FlagAdmin();
    // The whole array is replaced: a flag key contains dots, and config()->set() treats a dot as
    // nesting, so `flags.governed.flag` would create `flags['governed']['flag']`.
    config()->set('platform-feature-flags.flags', [
        'governed.flag' => [
            'owner' => 'platform', 'description' => 'A governed flag.', 'risk_class' => 'low',
            'environments' => ['testing'], 'target_types' => ['merchant'],
            'dependencies' => [], 'affected_screen_keys' => [], 'affected_operation_ids' => [],
            'health_metric_key' => null, 'rollback_criterion' => 'Pause.', 'external_gate' => null,
        ],
    ]);

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/feature-flags/governed.flag/change-requests', [
            'state' => 'active',
            'rollout_basis_points' => 10000,
            'reason' => 'Missing the governance fields.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect(PlatformFeatureFlagChangeRequest::query()->count())->toBe(0);
});

it('makes those governance fields NOT NULL at the database too', function (): void {
    $flag = PlatformFeatureFlag::factory()->create();
    $admin = ui08FlagAdmin();

    ui08FlagGuardViolation(fn () => DB::table('platform_feature_flag_change_requests')->insert([
        'ulid' => (string) Str::ulid(),
        'feature_flag_id' => $flag->id,
        'status' => 'pending',
        'proposed_configuration' => json_encode(['state' => 'active']),
        'proposed_configuration_hash' => str_repeat('a', 64),
        'impact_statement' => null,
        'rollback_plan' => null,
        'health_criterion' => null,
        'reason' => 'No governance.',
        'requested_by_user_id' => $admin->id,
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

it('permits at most one pending request per flag', function (): void {
    $flag = PlatformFeatureFlag::factory()->create();
    PlatformFeatureFlagChangeRequest::factory()->for($flag, 'flag')->create();

    ui08FlagGuardViolation(fn () => PlatformFeatureFlagChangeRequest::factory()->for($flag, 'flag')->create());
});

it('rejects with a mandatory decision note and leaves the flag untouched', function (): void {
    $requester = ui08FlagAdmin();
    $approver = ui08FlagAdmin();
    $flag = PlatformFeatureFlag::factory()->create(['state' => PlatformFeatureFlagState::Inactive]);
    $request = PlatformFeatureFlagChangeRequest::factory()->for($flag, 'flag')->create([
        'requested_by_user_id' => $requester->id,
    ]);

    test()->statefulMfa(now()->getTimestamp())->actingAs($approver, 'sanctum')
        ->postJson("/api/v1/platform/feature-flag-change-requests/{$request->ulid}/reject", [],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422);

    test()->statefulMfa(now()->getTimestamp())->actingAs($approver, 'sanctum')
        ->postJson("/api/v1/platform/feature-flag-change-requests/{$request->ulid}/reject",
            ['reason' => 'The rollback plan is not credible.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect($flag->refresh()->state)->toBe(PlatformFeatureFlagState::Inactive);
});

it('lets only the requester cancel their own proposal', function (): void {
    $requester = ui08FlagAdmin();
    $other = ui08FlagAdmin();
    $flag = PlatformFeatureFlag::factory()->create();
    $request = PlatformFeatureFlagChangeRequest::factory()->for($flag, 'flag')->create([
        'requested_by_user_id' => $requester->id,
    ]);

    test()->statefulMfa(now()->getTimestamp())->actingAs($other, 'sanctum')
        ->postJson("/api/v1/platform/feature-flag-change-requests/{$request->ulid}/cancel", [],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'feature_flag.requester_only');

    test()->statefulMfa(now()->getTimestamp())->actingAs($requester, 'sanctum')
        ->postJson("/api/v1/platform/feature-flag-change-requests/{$request->ulid}/cancel", [],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('keeps the governance history append-only', function (): void {
    $flag = PlatformFeatureFlag::factory()->create();
    $row = PlatformFeatureFlagHistory::query()->create([
        'feature_flag_id' => $flag->id,
        'action' => 'created',
        'actor_user_id' => ui08FlagAdmin()->id,
        'reason' => 'Initial state.',
    ]);

    ui08FlagGuardViolation(fn () => DB::table('platform_feature_flag_history')
        ->where('id', $row->id)
        ->update(['reason' => 'Rewriting history.']));

    ui08FlagGuardViolation(fn () => DB::table('platform_feature_flag_history')->where('id', $row->id)->delete());

    expect($row->refresh()->reason)->toBe('Initial state.');
});

it('decides a request only once', function (): void {
    $requester = ui08FlagAdmin();
    $approver = ui08FlagAdmin();
    $flag = PlatformFeatureFlag::factory()->create();
    $request = PlatformFeatureFlagChangeRequest::factory()->for($flag, 'flag')->create([
        'requested_by_user_id' => $requester->id,
    ]);

    test()->statefulMfa(now()->getTimestamp())->actingAs($approver, 'sanctum')
        ->postJson("/api/v1/platform/feature-flag-change-requests/{$request->ulid}/approve", [],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk();

    // A second decision on a terminal request is refused.
    test()->statefulMfa(now()->getTimestamp())->actingAs($approver, 'sanctum')
        ->postJson("/api/v1/platform/feature-flag-change-requests/{$request->ulid}/approve", [],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');
});
