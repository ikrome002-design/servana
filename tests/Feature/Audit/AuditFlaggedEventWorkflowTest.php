<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit');

/*
 | Phase 19 — flagged-event review workflow over the HTTP boundary. The Audit role flags a
 | branch-scoped audit row and works the review lifecycle; only review metadata changes and
 | the source audit_logs row stays immutable. Tenant/branch scoping + permission gating are
 | the security boundary.
 */

/** @return array{merchant: Merchant, branch: MerchantBranch, audit: User, log: AuditLog} */
function auditScenario(): array
{
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);
    $log = AuditLog::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    return ['merchant' => $merchant, 'branch' => $branch, 'audit' => $audit, 'log' => $log];
}

function flag(User $actor, string $auditUlid): string
{
    return (string) test()->actingAs($actor, 'sanctum')
        ->postJson('/api/v1/audit-flagged-events', ['audit_log' => $auditUlid])
        ->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
}

it('runs the full flag → review → resolve → reopen → dismiss → reopen lifecycle', function (): void {
    $scn = auditScenario();
    $audit = $scn['audit'];
    $id = flag($audit, $scn['log']->ulid);

    test()->actingAs($audit, 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/start-review")
        ->assertOk()->assertJsonPath('data.status', 'under_review')
        ->assertJsonPath('data.assigned_to', fn ($v) => is_string($v) && str_contains($v, '***'));

    test()->actingAs($audit, 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/resolve", ['review_notes' => 'Confirmed benign config change.'])
        ->assertOk()->assertJsonPath('data.status', 'resolved');

    test()->actingAs($audit, 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/reopen")
        ->assertOk()->assertJsonPath('data.status', 'reopened');

    test()->actingAs($audit, 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/start-review")
        ->assertOk()->assertJsonPath('data.status', 'under_review');

    test()->actingAs($audit, 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/dismiss", ['review_notes' => 'Not actionable.'])
        ->assertOk()->assertJsonPath('data.status', 'dismissed');

    test()->actingAs($audit, 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/reopen")
        ->assertOk()->assertJsonPath('data.status', 'reopened');
});

it('rejects an invalid transition with 422 invalid_state_transition', function (): void {
    $scn = auditScenario();
    $id = flag($scn['audit'], $scn['log']->ulid);

    // resolve is only valid from under_review.
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/resolve", ['review_notes' => 'too early'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');
});

it('guards concurrent/duplicate transitions via the locked state check', function (): void {
    $scn = auditScenario();
    $id = flag($scn['audit'], $scn['log']->ulid);
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/start-review")->assertOk();

    // A second start-review is no longer valid from under_review.
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/start-review")
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');
});

it('requires a review note to resolve or dismiss', function (): void {
    $scn = auditScenario();
    $id = flag($scn['audit'], $scn['log']->ulid);
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/start-review")->assertOk();

    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/resolve", ['review_notes' => ''])
        ->assertStatus(422);
});

it('keeps the source audit_logs row immutable across the whole workflow', function (): void {
    $scn = auditScenario();
    $log = $scn['log'];
    $before = ['action' => $log->action, 'hash' => $log->hash, 'severity' => $log->severity->value, 'context' => $log->context];

    $id = flag($scn['audit'], $log->ulid);
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/start-review")->assertOk();
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/resolve", ['review_notes' => 'done'])->assertOk();

    $after = AuditLog::query()->whereKey($log->id)->firstOrFail();
    expect($after->action)->toBe($before['action'])
        ->and($after->hash)->toBe($before['hash'])
        ->and($after->severity->value)->toBe($before['severity'])
        ->and($after->context)->toEqual($before['context']);
});

it('masks the linked audit row and never exposes internal ids or hashes', function (): void {
    $scn = auditScenario();
    $id = flag($scn['audit'], $scn['log']->ulid);

    $json = test()->actingAs($scn['audit'], 'sanctum')->getJson("/api/v1/audit-flagged-events/{$id}")
        ->assertOk()->json('data');

    expect($json['id'])->toBe($id)
        ->and($json['audit_event']['id'])->toBe($scn['log']->ulid)
        ->and($json)->not->toHaveKey('audit_log_id')
        ->and(json_encode($json, JSON_THROW_ON_ERROR))->not->toContain('"audit_log_id"')
        ->and(json_encode($json, JSON_THROW_ON_ERROR))->not->toContain($scn['log']->hash);
});

it('records a typed, masked audit event for each transition', function (): void {
    $scn = auditScenario();
    $id = flag($scn['audit'], $scn['log']->ulid);

    $flagged = AuditLog::query()->where('action', 'audit.flagged_event.created')->latest('id')->firstOrFail();
    expect($flagged->severity->value)->toBe('warning')
        ->and($flagged->branch_id)->toBe($scn['branch']->id)
        ->and($flagged->context['flagged_event_id'])->toBe($id);

    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-flagged-events/{$id}/start-review")->assertOk();
    expect(AuditLog::query()->where('action', 'audit.flagged_event.review_started')->exists())->toBeTrue();
});

it('lists only the acting Audit user assigned-branch flagged events', function (): void {
    $scn = auditScenario();
    flag($scn['audit'], $scn['log']->ulid);

    // A flag in another merchant must never appear.
    $otherMerchant = Merchant::factory()->active()->create();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $otherMerchant->id]);
    $otherLog = AuditLog::factory()->create(['merchant_id' => $otherMerchant->id, 'branch_id' => $otherBranch->id]);
    AuditFlaggedEvent::factory()->create([
        'merchant_id' => $otherMerchant->id, 'branch_id' => $otherBranch->id,
        'audit_log_id' => $otherLog->id, 'created_by' => User::factory(),
    ]);

    $data = test()->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-flagged-events')
        ->assertOk()->json('data');

    expect($data)->toHaveCount(1);
});
