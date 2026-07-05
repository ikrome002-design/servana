<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit');

/*
 | Phase 19: the merchant branch-events audit read API is paginated, server-masked,
 | allowlist-filtered, branch-scoped, and read-only (Plan §19.2/§19.3, §70, §74).
 | The canonical reader is the branch-scoped Audit role (`audit.branch_events.view`);
 | the legacy catch-all `audit.view_full` is retired. No write/delete routes exist.
 */

/**
 * Active merchant + branch + an Audit user assigned to that branch, with a
 * masked, branch-scoped general audit row recorded by the merchant admin.
 *
 * @return array{0: User, 1: MerchantBranch}
 */
function auditReaderScenario(array $context = ['email' => 'invitee@salon.co.ke', 'role' => 'front_office']): array
{
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    app(AuditRecorder::class)->record(
        AuditEvent::InvitationCreated, $admin, $merchant->id, $branch->id, $branch, $context,
    );

    return [$audit, $branch];
}

it('paginates and server-masks branch-events reads for the Audit role', function (): void {
    [$audit] = auditReaderScenario();

    $response = $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'action', 'severity', 'context', 'created_at']], 'meta', 'links']);

    $body = $response->getContent();
    expect($body)->toContain('***')
        ->and($body)->not->toContain('invitee@salon.co.ke');
});

it('masks the actor email in the read payload', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);
    app(AuditRecorder::class)->record(AuditEvent::BranchCreated, $admin, $merchant->id, $branch->id, $branch);

    $row = $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertStatus(200)->json('data.0.actor');

    expect($row)->toContain('***')->and($row)->not->toBe($admin->email);
});

it('allowlists filters — a valid action filter narrows, an invalid one 422s', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);
    app(AuditRecorder::class)->record(AuditEvent::BranchCreated, $admin, $merchant->id, $branch->id, $branch);
    app(AuditRecorder::class)->record(AuditEvent::BranchArchived, $admin, $merchant->id, $branch->id, $branch);

    $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs?action=branch.created')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'branch.created');

    $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs?action=not_a_real_action')
        ->assertStatus(422);
});

it('denies a role without any audit-read permission', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$frontOfficeUser] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    // Front Office holds no canonical audit read key → 403 at the permission middleware.
    $this->actingAs($frontOfficeUser, 'sanctum')->getJson('/api/v1/audit-logs')->assertStatus(403);
});

it('404s a foreign-merchant audit row without leaking it', function (): void {
    [$audit] = auditReaderScenario();
    [$otherAdmin, $other] = activeAdmin();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $other->id]);
    $foreignRow = app(AuditRecorder::class)->record(
        AuditEvent::BranchCreated, $otherAdmin, $other->id, $otherBranch->id, $otherBranch,
    );

    $this->actingAs($audit, 'sanctum')->getJson("/api/v1/audit-logs/{$foreignRow->ulid}")->assertStatus(404);
});
