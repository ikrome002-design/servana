<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('billing', 'phase20c', 'phase20c-api');

/*
 | Phase 20C platform promotion / free-period API (Plan §53). Super-Admin platform scope; mandatory MFA
 | + fresh BillingConfiguration step-up on mutations; ResolvePlatformContext binds no merchant. Role
 | boundaries, MFA/step-up, validation, lifecycle, ULID-only output and audit emission are proven here.
 */

function p20cAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);

    return $user;
}

function p20cIdem(): array
{
    return ['Idempotency-Key' => 'p20c-'.Str::random(24)];
}

// --- Role + MFA boundary ------------------------------------------------------------------------

it('lets a super admin list promotions', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/promotional-discounts')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('denies a non-platform user the platform promotion route', function (): void {
    $merchantUser = User::factory()->create(['is_platform_staff' => false]);
    confirmedTotp($merchantUser);

    test()->statefulMfa(now()->getTimestamp())->actingAs($merchantUser, 'sanctum')
        ->getJson('/api/v1/platform/promotional-discounts')
        ->assertForbidden();
});

// --- Create + validation ------------------------------------------------------------------------

it('creates a draft percentage promotion, ULID-only, and emits promotion.created', function (): void {
    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/promotional-discounts', [
            'name' => 'Launch 10%',
            'type' => 'percentage',
            'value' => 1000,
            'target_scope' => 'all_new_merchants',
            'effective_from' => today()->toDateString(),
            'status' => 'active', // authoritative field — must be IGNORED
        ], p20cIdem())
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.type', 'percentage');

    expect(strlen((string) $response->json('data.id')))->toBe(26)
        ->and($response->json('data'))->not->toHaveKey('created_by')
        ->and(AuditLog::query()->where('action', 'promotion.created')->exists())->toBeTrue();
});

it('creates a promotion with an explicit merchant target (ULID)', function (): void {
    $merchant = Merchant::factory()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/promotional-discounts', [
            'name' => 'VIP fixed',
            'type' => 'fixed_amount',
            'value' => 50000,
            'currency' => 'KES',
            'target_scope' => 'selected_merchants',
            'effective_from' => today()->toDateString(),
            'targets' => [['target_type' => 'merchant', 'merchant_id' => $merchant->ulid]],
        ], p20cIdem())
        ->assertCreated()
        ->assertJsonPath('data.targets.0.target_type', 'merchant')
        ->assertJsonPath('data.targets.0.merchant_id', $merchant->ulid);
});

it('rejects a percentage discount above 100%', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/promotional-discounts', [
            'name' => 'Too big',
            'type' => 'percentage',
            'value' => 10001,
            'target_scope' => 'all_new_merchants',
            'effective_from' => today()->toDateString(),
        ], p20cIdem())
        ->assertStatus(422);
});

it('rejects a global scope carrying targets', function (): void {
    $merchant = Merchant::factory()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/promotional-discounts', [
            'name' => 'Bad global',
            'type' => 'percentage',
            'value' => 1000,
            'target_scope' => 'all_new_merchants',
            'effective_from' => today()->toDateString(),
            'targets' => [['target_type' => 'merchant', 'merchant_id' => $merchant->ulid]],
        ], p20cIdem())
        ->assertStatus(422);
});

// --- Lifecycle + step-up ------------------------------------------------------------------------

it('approves a current-window promotion to active with a reason', function (): void {
    $promo = PromotionalDiscount::factory()->create(['effective_from' => today()->subDay()]);

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/promotional-discounts/{$promo->ulid}/approve", ['change_reason' => 'Approved for launch'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect(AuditLog::query()->where('action', 'promotion.approved')->exists())->toBeTrue();
});

it('requires a reason to approve', function (): void {
    $promo = PromotionalDiscount::factory()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/promotional-discounts/{$promo->ulid}/approve", [])
        ->assertStatus(422);
});

it('rejects a stale step-up on a mutation', function (): void {
    $promo = PromotionalDiscount::factory()->create();

    // MFA session present but the step-up timestamp is stale → RequireFreshMfa rejects.
    test()->statefulMfa(now()->subHours(2)->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/promotional-discounts/{$promo->ulid}/approve", ['change_reason' => 'Stale'])
        ->assertStatus(403);
});

it('rejects cancelling an active promotion (invalid transition)', function (): void {
    $promo = PromotionalDiscount::factory()->active()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/promotional-discounts/{$promo->ulid}/cancel", ['change_reason' => 'Nope'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');
});

it('runs the free-period offer lifecycle: create → approve to scheduled', function (): void {
    $create = test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/free-period-offers', [
            'name' => 'Free 30',
            'free_period_days' => 30,
            'target_scope' => 'all_new_merchants',
            'effective_from' => today()->subDay()->toDateString(),
        ], p20cIdem())
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    $ulid = $create->json('data.id');

    // Approval always lands in scheduled (no draft→active for free-period offers).
    test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/free-period-offers/{$ulid}/approve", ['change_reason' => 'Approved'])
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled');
});

it('rejects free_period_days outside 1..365', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(p20cAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/free-period-offers', [
            'name' => 'Too long',
            'free_period_days' => 400,
            'target_scope' => 'all_new_merchants',
            'effective_from' => today()->toDateString(),
        ], p20cIdem())
        ->assertStatus(422);
});
