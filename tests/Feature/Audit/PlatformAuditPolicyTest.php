<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit', 'isolation');

/*
 | R2: the platform audit endpoint exposes ONLY the platform chain (merchant_id
 | null) to platform staff with `platform.audit.view`. Platform staff never gain
 | merchant operational audit; merchant users never reach the platform endpoint
 | (Scope §4.8, Plan §70).
 */

it('lets platform staff read platform-chain audit rows', function (): void {
    $platform = User::factory()->create(['is_platform_staff' => true]);
    app(AuditRecorder::class)->record(AuditEvent::LoginSuccess, null, null); // platform row

    $this->actingAs($platform, 'sanctum')->getJson('/api/v1/platform/audit-logs')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'meta', 'links']);
});

it('keeps merchant operational rows off the platform endpoint', function (): void {
    $platform = User::factory()->create(['is_platform_staff' => true]);
    [$admin, $merchant] = activeAdmin();
    $merchantRow = app(AuditRecorder::class)->record(AuditEvent::BranchCreated, $admin, $merchant->id);

    // The merchant row is not listed, and addressing it directly 404s.
    $ids = $this->actingAs($platform, 'sanctum')->getJson('/api/v1/platform/audit-logs')
        ->assertStatus(200)->json('data.*.id');
    expect($ids)->not->toContain($merchantRow->ulid);

    $this->actingAs($platform, 'sanctum')->getJson("/api/v1/platform/audit-logs/{$merchantRow->ulid}")
        ->assertStatus(404);
});

it('denies a merchant admin the platform audit endpoint', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/audit-logs')->assertStatus(403);
});

it('denies platform staff the merchant audit endpoint (no active merchant)', function (): void {
    $platform = User::factory()->create(['is_platform_staff' => true]);

    // The merchant endpoint sits behind EnsureMerchantActive; platform staff have
    // no merchant context, so they cannot reach merchant operational audit.
    $this->actingAs($platform, 'sanctum')->getJson('/api/v1/audit-logs')->assertStatus(403);
});
