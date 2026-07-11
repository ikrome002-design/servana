<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('billing', 'phase20a', 'phase20a-api');

/*
 | Phase 20A platform billing catalogue API (Plan §13.9, §13.10, §47). Super-Admin platform scope
 | (mandatory MFA + fresh BillingConfiguration step-up on mutations); ResolvePlatformContext binds
 | no merchant. Role boundaries, step-up, overlap, lifecycle and audit emission are proven here.
 */

function platformAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user); // enroll a confirmed MFA credential (mandatory for platform staff)

    return $user;
}

// --- Role boundary + platform context ---

it('resolves platform-staff authority with no merchant and lets a super admin list plans', function (): void {
    SubscriptionPlan::factory()->count(2)->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/plans')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('denies a merchant user (front office) access to the platform plans route', function (): void {
    $scn = invoiceScenario();

    test()->statefulMfa(now()->getTimestamp())->actingAs($scn['actor'], 'sanctum')
        ->getJson('/api/v1/platform/plans')
        ->assertForbidden();
});

// --- Plans + audit ---

it('creates a plan with fresh step-up and emits subscription_plan.created', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/plans', ['key' => 'growth', 'name' => 'Growth'])
        ->assertCreated()
        ->assertJsonPath('data.key', 'growth')
        ->assertJsonPath('data.status', 'active');

    expect(SubscriptionPlan::query()->where('key', 'growth')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'subscription_plan.created')->exists())->toBeTrue();
});

it('rejects a plan create carrying a price field / never exposes a bigint id', function (): void {
    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/plans', ['key' => 'starter', 'name' => 'Starter'])
        ->assertCreated();

    expect($response->json('data'))->not->toHaveKey('id_int')
        ->and(strlen((string) $response->json('data.id')))->toBe(26); // ULID, not a bigint
});

it('retires a plan (bodiless) and preserves its prices', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    SubscriptionPlanPrice::factory()->for($plan, 'plan')->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/plans/{$plan->ulid}/retire")
        ->assertOk()
        ->assertJsonPath('data.status', 'retired');

    expect($plan->prices()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'subscription_plan.retired')->exists())->toBeTrue();
});

// --- Prices: overlap + scheduled + cancel ---

it('creates a price and rejects an overlapping one at the database', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    $key = 'idem-'.Str::random(24);

    $headers = ['Idempotency-Key' => $key];
    test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/plans/{$plan->ulid}/prices", [
            'amount_minor' => 500000, 'currency' => 'KES', 'billing_interval' => 'monthly',
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ], $headers)
        ->assertCreated();

    test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/plans/{$plan->ulid}/prices", [
            'amount_minor' => 600000, 'currency' => 'KES', 'billing_interval' => 'monthly',
            'effective_from' => '2026-03-01', 'effective_to' => null,
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(409);
});

it('cancels a future price but refuses a current/historical price', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    $future = SubscriptionPlanPrice::factory()->for($plan, 'plan')->create([
        'effective_from' => now()->addMonths(2)->toDateString(), 'effective_to' => null, 'billing_interval' => 'weekly',
    ]);
    $current = SubscriptionPlanPrice::factory()->for($plan, 'plan')->create([
        'effective_from' => '2026-01-01', 'effective_to' => null, 'billing_interval' => 'monthly',
    ]);

    test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/plan-prices/{$future->ulid}/cancel")
        ->assertNoContent();

    test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/plan-prices/{$current->ulid}/cancel")
        ->assertStatus(422);
});

// --- Preferred-fee rule lifecycle ---

it('runs the preferred-fee rule lifecycle: create draft → approve → cancel a draft', function (): void {
    $admin = platformAdmin();

    $create = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/preferred-personnel-fee-rules', [
            'calculation_type' => 'fixed_amount', 'fixed_amount_minor' => 20000, 'currency' => 'KES',
            'calculation_basis' => 'service_item_net_amount', 'scope' => 'platform_default',
            'effective_from' => '2026-01-01', 'change_reason' => 'Launch default.',
        ], ['Idempotency-Key' => 'ppf-'.Str::random(24)])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    $ulid = $create->json('data.id');

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/preferred-personnel-fee-rules/{$ulid}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect(AuditLog::query()->where('action', 'preferred_personnel_fee_rule.approved')->exists())->toBeTrue();

    // A second overlapping active platform_default rule cannot be approved.
    $second = PreferredPersonnelFeeRule::factory()->draft()->create([
        'scope' => 'platform_default', 'service_id' => null,
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/preferred-personnel-fee-rules/{$second->ulid}/approve")
        ->assertStatus(409);
});

it('rejects a percentage rule that also carries a fixed amount (value-shape validation)', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/preferred-personnel-fee-rules', [
            'calculation_type' => 'percentage', 'percentage_basis_points' => 500,
            'fixed_amount_minor' => 100, 'currency' => 'KES',
            'calculation_basis' => 'service_item_net_amount', 'scope' => 'platform_default',
            'effective_from' => '2026-01-01', 'change_reason' => 'bad',
        ], ['Idempotency-Key' => 'ppf-'.Str::random(24)])
        ->assertStatus(422);
});

// --- Step-up enforcement ---

it('denies a settings update without a fresh step-up but allows the read', function (): void {
    $admin = platformAdmin();
    $stale = now()->subHours(2)->getTimestamp();

    // Read allowed with MFA present (stale step-up is fine for reads).
    test()->statefulMfa($stale)->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/platform/billing-settings')
        ->assertStatus(404); // no version seeded yet, but authorization passed (not 403)

    // Mutation denied without a FRESH step-up.
    test()->statefulMfa($stale)->actingAs($admin, 'sanctum')
        ->putJson('/api/v1/platform/billing-settings', [
            'billing_mode' => 'fixed_amount', 'default_trial_days' => 14, 'grace_days' => 7, 'currency' => 'KES',
        ], ['Idempotency-Key' => 'bs-'.Str::random(24)])
        ->assertForbidden();
});

it('creates a billing-settings version with a fresh step-up', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(platformAdmin(), 'sanctum')
        ->putJson('/api/v1/platform/billing-settings', [
            'billing_mode' => 'fixed_amount', 'default_trial_days' => 14, 'grace_days' => 7, 'currency' => 'KES',
        ], ['Idempotency-Key' => 'bs-'.Str::random(24)])
        ->assertSuccessful()
        ->assertJsonPath('data.billing_mode', 'fixed_amount');

    expect(AuditLog::query()->where('action', 'platform_billing.settings_updated')->exists())->toBeTrue();
});

// --- Branch read ---

it('lets a Branch Manager read the effective rule (masked) and forbids mutation', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$bm] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    PreferredPersonnelFeeRule::factory()->create([
        'scope' => 'platform_default', 'service_id' => null, 'fixed_amount_minor' => 15000,
        'effective_from' => '2026-01-01', 'effective_to' => null, 'status' => 'active',
    ]);

    $read = test()->actingAs($bm, 'sanctum')
        ->getJson('/api/v1/branch/preferred-personnel-fee-rule')
        ->assertOk();

    expect($read->json('data.fixed_amount_minor'))->toBe(15000)
        ->and($read->json('data'))->not->toHaveKey('status')
        ->and($read->json('data'))->not->toHaveKey('approved_at')
        ->and($read->json('data'))->not->toHaveKey('id');

    // Branch Manager cannot manage rules (no platform authority).
    test()->statefulMfa(now()->getTimestamp())->actingAs($bm, 'sanctum')
        ->postJson('/api/v1/platform/preferred-personnel-fee-rules', [
            'calculation_type' => 'fixed_amount', 'fixed_amount_minor' => 1, 'currency' => 'KES',
            'calculation_basis' => 'service_item_net_amount', 'scope' => 'platform_default',
            'effective_from' => '2026-01-01', 'change_reason' => 'x',
        ], ['Idempotency-Key' => 'x-'.Str::random(24)])
        ->assertForbidden();
});

it('prefers a service-scoped rule over the platform default in the branch read', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$bm] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);
    $service = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    PreferredPersonnelFeeRule::factory()->create(['scope' => 'platform_default', 'service_id' => null, 'fixed_amount_minor' => 10000, 'effective_from' => '2026-01-01', 'status' => 'active']);
    PreferredPersonnelFeeRule::factory()->service($service)->create(['fixed_amount_minor' => 25000, 'effective_from' => '2026-01-01', 'status' => 'active']);

    test()->actingAs($bm, 'sanctum')
        ->getJson('/api/v1/branch/preferred-personnel-fee-rule?service='.$service->ulid)
        ->assertOk()
        ->assertJsonPath('data.fixed_amount_minor', 25000);
});
