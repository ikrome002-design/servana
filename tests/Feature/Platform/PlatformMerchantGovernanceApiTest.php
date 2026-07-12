<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'phase20b', 'phase20b-api', 'platform-governance');

/*
 | Phase 20B — Platform merchant governance API (Plan §22, §24.1). Super-Admin platform scope (no
 | merchant tenant context): registration monitoring, merchant list/detail, and operational suspend /
 | reactivate / deactivate. Proves the role boundary, mandatory reason, fresh step-up, operational vs
 | billing status independence, tenant non-enumeration, exactly-once redacted audit, and the absence
 | of any merchant-create / impersonation / payment / billing-recovery route.
 */

function p20bGovAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);

    return $user;
}

// --- Reads ---------------------------------------------------------------

it('serves registration monitoring and the merchant list to a super admin', function (): void {
    Merchant::factory()->create(['status' => MerchantStatus::PendingSetup]);
    Merchant::factory()->active()->create();

    $monitor = test()->statefulMfa(now()->getTimestamp())->actingAs(p20bGovAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/registration-monitor')
        ->assertOk()->assertJsonCount(2, 'data');
    expect(collect($monitor->json('data'))->pluck('pending_setup'))->toContain(true)->toContain(false);

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20bGovAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/merchants')
        ->assertOk()->assertJsonCount(2, 'data');
});

it('shows a merchant governance record with operational + billing status separated + a can map', function (): void {
    $merchant = Merchant::factory()->active()->create(['billing_status' => MerchantBillingStatus::ReadOnlyGrace]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(p20bGovAdmin(), 'sanctum')
        ->getJson("/api/v1/platform/merchants/{$merchant->ulid}")
        ->assertOk();

    expect($response->json('data.operational_status'))->toBe('active')
        ->and($response->json('data.billing_status'))->toBe('read_only_grace')
        ->and($response->json('data.can.suspend'))->toBeTrue()
        ->and($response->json('data.can.reactivate'))->toBeFalse() // active can't be reactivated
        ->and(strlen((string) $response->json('data.id')))->toBe(26)
        ->and($response->json('data'))->not->toHaveKey('created_by');
});

// --- Suspend / reactivate / deactivate -----------------------------------

it('suspends a merchant with a mandatory reason + fresh step-up and emits exactly one redacted audit', function (): void {
    $merchant = Merchant::factory()->active()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20bGovAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/merchants/{$merchant->ulid}/suspend", ['reason' => 'Fraud investigation opened.'])
        ->assertOk()->assertJsonPath('data.operational_status', 'suspended');

    expect($merchant->fresh()->status)->toBe(MerchantStatus::Suspended);

    $rows = AuditLog::query()->where('action', 'merchant.suspended')->get();
    expect($rows)->toHaveCount(1);
    $context = $rows->first()->context;
    expect($rows->first()->merchant_id)->toBeNull() // platform/governance chain
        ->and($context['merchant_id'])->toBe($merchant->ulid) // ULID, never the internal id
        ->and($context['to_status'])->toBe('suspended')
        ->and($context['reason'])->toBe('Fraud investigation opened.');
});

it('requires a governance reason (422) on every mutation', function (): void {
    $merchant = Merchant::factory()->active()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20bGovAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/merchants/{$merchant->ulid}/suspend", [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.fields.reason.0', fn ($m) => is_string($m));
});

it('denies a governance mutation without a FRESH step-up but allows the read', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $stale = now()->subHours(2)->getTimestamp();

    test()->statefulMfa($stale)->actingAs(p20bGovAdmin(), 'sanctum')
        ->getJson("/api/v1/platform/merchants/{$merchant->ulid}")->assertOk();

    test()->statefulMfa($stale)->actingAs(p20bGovAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/merchants/{$merchant->ulid}/suspend", ['reason' => 'x reason'])
        ->assertForbidden();
});

it('deactivates a merchant (critical) from active or suspended and is terminal', function (): void {
    $merchant = Merchant::factory()->active()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20bGovAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/merchants/{$merchant->ulid}/deactivate", ['reason' => 'Account closed by request.'])
        ->assertOk()->assertJsonPath('data.operational_status', 'deactivated');

    expect(AuditLog::query()->where('action', 'merchant.deactivated')->where('severity', 'critical')->count())->toBe(1);

    // Terminal: a further reactivate is an invalid transition (422).
    test()->statefulMfa(now()->getTimestamp())->actingAs(p20bGovAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/merchants/{$merchant->ulid}/reactivate", ['reason' => 'retry'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');
});

it('rejects suspending an already-suspended merchant (422 invalid_state_transition)', function (): void {
    $merchant = Merchant::factory()->active()->create();
    // status is not mass-assignable (Merchant::$fillable = ['name']); set it directly.
    $merchant->status = MerchantStatus::Suspended;
    $merchant->save();

    test()->statefulMfa(now()->getTimestamp())->actingAs(p20bGovAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/merchants/{$merchant->ulid}/suspend", ['reason' => 'again'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');
});

// --- Operational vs billing status independence --------------------------

it('reactivation restores operational status WITHOUT clearing a billing suspension', function (): void {
    $merchant = Merchant::factory()->active()->create(['billing_status' => MerchantBillingStatus::SuspendedBilling]);
    $admin = p20bGovAdmin();

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/merchants/{$merchant->ulid}/suspend", ['reason' => 'ops hold'])->assertOk();

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/merchants/{$merchant->ulid}/reactivate", ['reason' => 'ops cleared'])
        ->assertOk()->assertJsonPath('data.operational_status', 'active')
        // Billing suspension is untouched — operational reactivation is NOT billing recovery.
        ->assertJsonPath('data.billing_status', 'suspended_billing');

    expect($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::SuspendedBilling);
});

// --- Boundary + non-enumeration ------------------------------------------

it('denies every governance route to a merchant admin (no platform authority)', function (): void {
    [$admin, $merchant] = activeAdmin();

    test()->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/merchants')->assertForbidden();
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/merchants/{$merchant->ulid}/suspend", ['reason' => 'nope'])->assertForbidden();

    expect($merchant->fresh()->status)->toBe(MerchantStatus::Active);
});

it('404s an unknown merchant ULID without leaking existence (non-enumeration)', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(p20bGovAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/merchants/01JQFAKEULIDVALUE0000000000')
        ->assertNotFound();
});

it('exposes no merchant-create, first-admin, impersonation, payment, or billing-recovery route', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $admin = p20bGovAdmin();

    // Creation / first-admin: the /platform/merchants collection is read-only (GET only), so a
    // POST is 405 (method not allowed) — there is no create action, by design.
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/merchants', ['name' => 'X'])->assertStatus(405);

    foreach ([
        "/api/v1/platform/merchants/{$merchant->ulid}/impersonate",
        "/api/v1/platform/merchants/{$merchant->ulid}/admins",
        "/api/v1/platform/merchants/{$merchant->ulid}/payments",
        "/api/v1/platform/merchants/{$merchant->ulid}/billing-recovery",
    ] as $guess) {
        test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')->postJson($guess)->assertNotFound();
    }
});
