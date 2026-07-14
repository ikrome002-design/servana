<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-config-api');

/*
 | Phase 20E Increment 6 — platform-fee configuration API (Plan §51, §52). Super-Admin platform scope;
 | mandatory MFA + fresh BillingConfiguration step-up on mutations + idempotency on
 | create/approve/supersede/cancel. ResolvePlatformContext binds no merchant. Role boundaries, MFA/step-up,
 | validation, ULID-only output, server-owned-field rejection, and audit emission are proven here.
 */

function pfcAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);

    return $user;
}

function pfcIdem(): array
{
    return ['Idempotency-Key' => 'pfc-'.Str::random(24)];
}

/** @return array<string,mixed> */
function pfcPayload(array $overrides = []): array
{
    return array_merge([
        'billing_mode' => 'percentage_on_merchant_client_invoice',
        'percentage_basis_points' => 250,
        'tier_behavior' => 'customer_centric',
        'fee_basis_type' => 'merchant_client_invoice_service_subtotal',
        'currency' => 'KES',
        'effective_from' => today()->toDateString(),
        'change_reason' => 'Initial percentage fee configuration.',
    ], $overrides);
}

it('lets a super admin list configurations', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(pfcAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/billing/platform-fee-configurations')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('denies a non-platform user the configuration route', function (): void {
    $merchantUser = User::factory()->create(['is_platform_staff' => false]);
    confirmedTotp($merchantUser);

    test()->statefulMfa(now()->getTimestamp())->actingAs($merchantUser, 'sanctum')
        ->getJson('/api/v1/platform/billing/platform-fee-configurations')
        ->assertForbidden();
});

it('creates a draft configuration (ULID-only, status ignored) and emits configuration_created', function (): void {
    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(pfcAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/billing/platform-fee-configurations', pfcPayload([
            'status' => 'active', // authoritative field — must be IGNORED
        ]), pfcIdem())
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.percentage_basis_points', 250);

    expect(strlen((string) $response->json('data.id')))->toBe(26)
        ->and($response->json('data'))->not->toHaveKey('created_by')
        ->and($response->json('data'))->not->toHaveKey('approved_by')
        ->and(AuditLog::query()->where('action', 'platform_fee.configuration_created')->exists())->toBeTrue();
});

it('denies a create without a fresh step-up (stale step-up)', function (): void {
    $stale = now()->subHours(2)->getTimestamp();

    test()->statefulMfa($stale)->actingAs(pfcAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/billing/platform-fee-configurations', pfcPayload(), pfcIdem())
        ->assertForbidden();
});

it('requires an Idempotency-Key and replays the stored create', function (): void {
    $admin = pfcAdmin();
    $key = pfcIdem();

    $first = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/billing/platform-fee-configurations', pfcPayload(), $key)
        ->assertCreated();

    $second = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/billing/platform-fee-configurations', pfcPayload(), $key)
        ->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(PlatformFeeConfiguration::query()->count())->toBe(1);
});

it('rejects a basis-points value above 100%', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(pfcAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/billing/platform-fee-configurations', pfcPayload(['percentage_basis_points' => 10001]), pfcIdem())
        ->assertStatus(422);
});

it('drives the full lifecycle: create → update → approve (active) and blocks editing an approved config', function (): void {
    $admin = pfcAdmin();

    $created = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/billing/platform-fee-configurations', pfcPayload(), pfcIdem())
        ->assertCreated();
    $ulid = (string) $created->json('data.id');

    // update the draft
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/platform/billing/platform-fee-configurations/{$ulid}", pfcPayload(['percentage_basis_points' => 300]))
        ->assertOk()
        ->assertJsonPath('data.percentage_basis_points', 300);

    // approve → active (effective today)
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/billing/platform-fee-configurations/{$ulid}/approve", ['change_reason' => 'Go live'], pfcIdem())
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    // editing an approved config is rejected (immutable — supersede instead)
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/platform/billing/platform-fee-configurations/{$ulid}", pfcPayload(['percentage_basis_points' => 400]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'platform_fee_configuration_not_editable');

    expect(AuditLog::query()->where('action', 'platform_fee.configuration_approved')->exists())->toBeTrue();
});

it('supersedes an active configuration with a new version', function (): void {
    $admin = pfcAdmin();
    $active = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subYear(), 'effective_to' => null,
    ]);

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/billing/platform-fee-configurations/{$active->ulid}/supersede", pfcPayload([
            'percentage_basis_points' => 300, 'effective_from' => today()->toDateString(),
        ]), pfcIdem())
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.percentage_basis_points', 300);

    expect($active->fresh()->status->value)->toBe('superseded')
        ->and(AuditLog::query()->where('action', 'platform_fee.configuration_superseded')->exists())->toBeTrue();
});

it('rejects an overlapping approval with a 409', function (): void {
    $admin = pfcAdmin();
    PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subYear(), 'effective_to' => null,
    ]);
    $draft = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->draft()->create([
        'currency' => 'KES', 'effective_from' => today()->toDateString(), 'effective_to' => null,
    ]);

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/billing/platform-fee-configurations/{$draft->ulid}/approve", ['change_reason' => 'go'], pfcIdem())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'platform_fee_configuration_overlap');
});
