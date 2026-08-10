<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Platform\Queries\PlatformDashboardProjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('platform', 'ui08', 'ui08-dashboard');

/*
 | Phase UI-08 §5.4.1 — the Super Administrator governance dashboard.
 |
 | The ONE operation UI-08 adds. These cases prove it aggregates SERVER-SIDE across every merchant,
 | carries a definition and time range for every figure, separates operational from billing status,
 | reports a gate-blocked series as unavailable rather than zero, adds no permission key, and is
 | refused to anyone who is not a platform user.
 */

function ui08DashboardAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);

    return $user;
}

it('aggregates across every merchant rather than over one page', function (): void {
    // More merchants than any page size the platform list uses, so a browser aggregating page 1
    // would under-report. This is the exact defect the endpoint exists to prevent.
    Merchant::factory()->count(30)->create(['status' => MerchantStatus::Active]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08DashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/dashboard')
        ->assertOk();

    expect($response->json('data.merchant_lifecycle.total_merchants'))->toBe(30)
        ->and($response->json('data.merchant_lifecycle.by_operational_status.active'))->toBe(30);
});

it('counts operational status and billing status separately, never conflating them', function (): void {
    // Suspended for BILLING but operationally active: the two axes must not be merged.
    Merchant::factory()->create([
        'status' => MerchantStatus::Active,
        'billing_status' => MerchantBillingStatus::SuspendedBilling,
    ]);
    // Suspended by POLICY while billing is fine.
    Merchant::factory()->create([
        'status' => MerchantStatus::Suspended,
        'billing_status' => MerchantBillingStatus::Active,
    ]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08DashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/dashboard')
        ->assertOk();

    expect($response->json('data.merchant_lifecycle.by_operational_status.suspended'))->toBe(1)
        ->and($response->json('data.merchant_lifecycle.billing_suspended'))->toBe(1)
        ->and($response->json('data.governance_tasks.merchants_suspended_by_policy'))->toBe(1)
        ->and($response->json('data.governance_tasks.merchants_suspended_for_billing'))->toBe(1);
});

it('sums the outstanding balance from the stored invoice snapshot in minor units', function (): void {
    $merchant = Merchant::factory()->create();

    SubscriptionInvoice::factory()->forMerchant($merchant)->create([
        'status' => SubscriptionInvoiceStatus::Overdue,
        'subtotal_minor' => 500_00,
        'discount_minor' => 0,
        'total_minor' => 500_00,
        'balance_minor' => 500_00,
    ]);
    SubscriptionInvoice::factory()->forMerchant($merchant)->create([
        'status' => SubscriptionInvoiceStatus::Paid,
        'subtotal_minor' => 300_00,
        'discount_minor' => 0,
        'total_minor' => 300_00,
        'balance_minor' => 0,
    ]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08DashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/dashboard')
        ->assertOk();

    // Integer minor units, and the PAID invoice contributes nothing.
    expect($response->json('data.commercial.open_invoice_balance_minor'))->toBe(50000)
        ->and($response->json('data.governance_tasks.overdue_invoices'))->toBe(1);
});

it('reports every figure with a definition, a time range and a drill-through', function (): void {
    Merchant::factory()->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08DashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/dashboard')
        ->assertOk();

    foreach (['merchant_lifecycle', 'commercial', 'registration_monitoring', 'governance_tasks', 'audit_alerts'] as $section) {
        expect($response->json("data.{$section}.definitions"))->toBeArray()
            ->and($response->json("data.{$section}.time_range"))->toBeString()
            ->and($response->json("data.{$section}.drill_through"))->toBeString();
    }

    expect($response->json('meta.read_only'))->toBeTrue()
        ->and($response->json('meta.authorization_authority'))->toContain('platform.merchant.view');
});

/**
 * The decisive Gate-W property. A zero here would tell the platform owner that a system they
 * cannot even reach is healthy.
 */
it('reports the gate-blocked integration series as unavailable, never as zero or healthy', function (): void {
    Merchant::factory()->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08DashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/dashboard')
        ->assertOk();

    expect($response->json('data.integrations.availability'))->toBe('disabled_by_gate')
        ->and($response->json('data.integrations.gate'))->toBe(PlatformDashboardProjection::EXTERNAL_GATE_W)
        ->and($response->json('data.integrations.gate_statement'))->toContain('External Gate W')
        ->and($response->json('data.integrations.wallet'))->toBeNull()
        ->and($response->json('data.integrations.reconciliation_exceptions'))->toBeNull()
        ->and($response->json('data.integrations.refer_and_earn'))->toBeNull();

    // Explicitly NOT zero, and explicitly not described as healthy anywhere in the payload.
    expect($response->json('data.integrations.wallet'))->not->toBe(0);
    expect(json_encode($response->json('data.integrations')))->not->toContain('healthy');
});

it('marks every available section as available and names no gate', function (): void {
    Merchant::factory()->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08DashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/dashboard')
        ->assertOk();

    foreach (['merchant_lifecycle', 'commercial', 'registration_monitoring', 'governance_tasks', 'audit_alerts'] as $section) {
        expect($response->json("data.{$section}.availability"))->toBe('available')
            ->and($response->json("data.{$section}.gate"))->toBeNull();
    }
});

it('counts registrations over rolling windows without inventing a risk score', function (): void {
    // Status is set EXPLICITLY on every fixture: the Merchant factory defaults to `pending_setup`,
    // so leaving it out silently made all four merchants count as awaiting setup.
    Merchant::factory()->create(['status' => MerchantStatus::Active, 'created_at' => now()->subDays(2)]);
    Merchant::factory()->create(['status' => MerchantStatus::Active, 'created_at' => now()->subDays(20)]);
    Merchant::factory()->create(['status' => MerchantStatus::Active, 'created_at' => now()->subDays(90)]);
    Merchant::factory()->create(['status' => MerchantStatus::PendingSetup, 'created_at' => now()->subDay()]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08DashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/dashboard')
        ->assertOk();

    expect($response->json('data.registration_monitoring.registered_last_7_days'))->toBe(2)
        ->and($response->json('data.registration_monitoring.registered_last_30_days'))->toBe(3)
        ->and($response->json('data.registration_monitoring.awaiting_setup_completion'))->toBe(1);

    // Servana records no fraud signal, so no confidence/risk score may be published.
    $payload = json_encode($response->json('data.registration_monitoring'));
    expect($payload)->not->toContain('risk_score')
        ->and($payload)->not->toContain('fraud_score');
});

it('refuses a merchant user without enumerating anything', function (): void {
    $merchantUser = User::factory()->create(['is_platform_staff' => false]);
    confirmedTotp($merchantUser);

    test()->statefulMfa(now()->getTimestamp())->actingAs($merchantUser, 'sanctum')
        ->getJson('/api/v1/platform/dashboard')
        ->assertForbidden();
});

it('refuses an unauthenticated request', function (): void {
    test()->getJson('/api/v1/platform/dashboard')->assertUnauthorized();
});

it('adds no mutation route alongside the dashboard read', function (): void {
    $methods = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => $route->uri() === 'api/v1/platform/dashboard')
        ->flatMap(static fn ($route): array => $route->methods())
        ->unique()
        ->values()
        ->all();

    sort($methods);

    expect($methods)->toBe(['GET', 'HEAD']);
});
