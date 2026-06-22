<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit');

/*
 | R2: the merchant audit read API is paginated, server-masked, allowlist-filtered,
 | and read-only (Plan §70, §74). No write/delete routes exist.
 */

it('paginates and server-masks audit reads for an authorized merchant viewer', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    // A row whose context carries an email — it must come back masked.
    app(AuditRecorder::class)->record(
        AuditEvent::InvitationCreated, $admin, $merchant->id, $branch->id, $branch,
        ['email' => 'invitee@salon.co.ke', 'role' => 'front_office'],
    );

    $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'action', 'severity', 'context', 'created_at']], 'meta', 'links']);

    $body = $response->getContent();
    expect($body)->toContain('***')
        ->and($body)->not->toContain('invitee@salon.co.ke');
});

it('masks the actor email in the read payload', function (): void {
    [$admin, $merchant] = activeAdmin();
    app(AuditRecorder::class)->record(AuditEvent::BranchCreated, $admin, $merchant->id);

    $row = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertStatus(200)->json('data.0.actor');

    expect($row)->toContain('***')->and($row)->not->toBe($admin->email);
});

it('allowlists filters — a valid action filter narrows, an invalid one 422s', function (): void {
    [$admin, $merchant] = activeAdmin();
    app(AuditRecorder::class)->record(AuditEvent::BranchCreated, $admin, $merchant->id);
    app(AuditRecorder::class)->record(AuditEvent::BranchArchived, $admin, $merchant->id);

    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/audit-logs?action=branch.created')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'branch.created');

    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/audit-logs?action=not_a_real_action')
        ->assertStatus(422);
});

it('denies a role without the audit-read permission', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$frontOfficeUser] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    // Front Office has no audit.view_full → 403 at the permission middleware.
    $this->actingAs($frontOfficeUser, 'sanctum')->getJson('/api/v1/audit-logs')->assertStatus(403);
});

it('404s a foreign-merchant audit row without leaking it', function (): void {
    [$admin] = activeAdmin();
    [$otherAdmin, $other] = activeAdmin();
    $foreignRow = app(AuditRecorder::class)->record(AuditEvent::BranchCreated, $otherAdmin, $other->id);

    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/audit-logs/{$foreignRow->ulid}")->assertStatus(404);
});
