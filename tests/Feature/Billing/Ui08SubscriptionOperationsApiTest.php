<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('billing', 'ui08', 'ui08-subscription-operations');

/*
 | COR-UI08-001 §10 — platform subscription operations (navigation map §5.4.13).
 |
 | READ-ONLY over Phase 20B truth. These cases prove the projection reads across merchants (a
 | platform route binds no tenant), paginates, filters on canonical enum values only, sorts from an
 | allowlist, shows the stored invoice snapshot rather than a recalculation, and adds no table.
 */

function ui08SubscriptionAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);

    return $user;
}

it('reads subscriptions across every merchant, because a platform route binds no tenant', function (): void {
    $a = Merchant::factory()->create();
    $b = Merchant::factory()->create();
    MerchantSubscription::factory()->forMerchant($a)->status(MerchantSubscriptionStatus::Active)->create();
    MerchantSubscription::factory()->forMerchant($b)->status(MerchantSubscriptionStatus::Trialing)->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08SubscriptionAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/subscriptions')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);

    // Public ULIDs only — an internal bigint id never crosses the boundary.
    expect(strlen((string) $response->json('data.0.id')))->toBe(26)
        ->and(strlen((string) $response->json('data.0.merchant.id')))->toBe(26);
});

it('summarises by status, cohort and funnel, and says what every figure counts', function (): void {
    $merchant = Merchant::factory()->create();
    MerchantSubscription::factory()->forMerchant($merchant)->status(MerchantSubscriptionStatus::Active)->create();
    MerchantSubscription::factory()->forMerchant(Merchant::factory()->create())->status(MerchantSubscriptionStatus::Trialing)->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08SubscriptionAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/subscription-operations/summary')
        ->assertOk();

    expect($response->json('data.subscriptions_by_status.active'))->toBe(1)
        ->and($response->json('data.subscriptions_by_status.trialing'))->toBe(1)
        ->and($response->json('data.cohorts.trialing'))->toBe(1)
        ->and($response->json('data.totals.subscriptions'))->toBe(2)
        // Every figure carries its definition and time range; a bare number is not evidence.
        ->and($response->json('meta.definitions.subscriptions_by_status'))->toBeString()
        ->and($response->json('meta.time_range'))->toBeString()
        ->and($response->json('meta.authorization_authority'))->toBe('merchants.billing_status');
});

it('filters subscriptions by canonical status and rejects an unknown one', function (): void {
    MerchantSubscription::factory()->forMerchant(Merchant::factory()->create())->status(MerchantSubscriptionStatus::Active)->create();
    MerchantSubscription::factory()->forMerchant(Merchant::factory()->create())->status(MerchantSubscriptionStatus::Overdue)->create();

    $admin = ui08SubscriptionAdmin();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/platform/subscriptions?status=overdue')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.status'))->toBe('overdue');

    // A status the state machine cannot produce is refused rather than silently ignored.
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/platform/subscriptions?status=not_a_real_status')
        ->assertStatus(422);
});

it('ignores a sort the allowlist does not contain instead of passing it to the database', function (): void {
    MerchantSubscription::factory()->forMerchant(Merchant::factory()->create())->status(MerchantSubscriptionStatus::Active)->create();

    // An unlisted sort is refused by validation — the request never reaches a column name.
    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08SubscriptionAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/subscriptions?sort=merchant_id')
        ->assertStatus(422);
});

it('paginates with a bounded page size', function (): void {
    foreach (range(1, 3) as $ignored) {
        MerchantSubscription::factory()->forMerchant(Merchant::factory()->create())->status(MerchantSubscriptionStatus::Active)->create();
    }

    $admin = ui08SubscriptionAdmin();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/platform/subscriptions?per_page=2')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(3);

    // An unbounded page cannot be requested.
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/platform/subscriptions?per_page=100000')
        ->assertStatus(422);
});

it('narrows to nothing for an unknown merchant filter rather than erroring', function (): void {
    MerchantSubscription::factory()->forMerchant(Merchant::factory()->create())->status(MerchantSubscriptionStatus::Active)->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08SubscriptionAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/subscriptions?merchant='.Str::ulid())
        ->assertOk();

    // Non-enumerating: an unknown identifier yields no rows, not a distinguishable error.
    expect($response->json('data'))->toHaveCount(0);
});

it('explains the current state instead of leaving a bare status on a governance screen', function (): void {
    $subscription = MerchantSubscription::factory()
        ->forMerchant(Merchant::factory()->create())
        ->status(MerchantSubscriptionStatus::SuspendedBilling)
        ->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08SubscriptionAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/subscriptions/'.$subscription->ulid)
        ->assertOk();

    expect($response->json('data.current_state.status'))->toBe('suspended_billing')
        ->and($response->json('data.current_state.authorization_authority'))->toBe('merchants.billing_status')
        ->and($response->json('data.current_state.explanation'))->toContain('merchant-side payment outcome');
});

it('shows the stored invoice snapshot and never a recalculation', function (): void {
    $merchant = Merchant::factory()->create();
    $invoice = SubscriptionInvoice::factory()->forMerchant($merchant)->create([
        'status' => SubscriptionInvoiceStatus::Issued,
        'subtotal_minor' => 500000,
        'discount_minor' => 50000,
        'total_minor' => 450000,
        'balance_minor' => 450000,
        'currency' => 'KES',
    ]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08SubscriptionAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/subscription-invoices/'.$invoice->ulid)
        ->assertOk();

    // Exactly the stored figures — nothing is re-derived from the line items.
    expect($response->json('data.subtotal.amount'))->toBe(500000)
        ->and($response->json('data.discount.amount'))->toBe(50000)
        ->and($response->json('data.total.amount'))->toBe(450000)
        ->and($response->json('data.balance.amount'))->toBe(450000)
        ->and($response->json('data.snapshot_note'))->toContain('never a recalculation');

    // The row itself is untouched by the read.
    expect($invoice->refresh()->total_minor)->toBe(450000);
});

it('surfaces the Wallet projection as stored and claims no provider truth', function (): void {
    $invoice = SubscriptionInvoice::factory()->forMerchant(Merchant::factory()->create())->create([
        'status' => SubscriptionInvoiceStatus::Issued,
    ]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08SubscriptionAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/subscription-invoices/'.$invoice->ulid)
        ->assertOk();

    expect($response->json('data.wallet.authority'))->toBe('wallet_by_citrus');

    // No provider reference, credential or callback payload is ever exposed.
    $body = (string) $response->getContent();
    foreach (['checkout_request_id', 'mpesa', 'daraja', 'consumer_key', 'callback'] as $forbidden) {
        expect(str_contains(strtolower($body), $forbidden))->toBeFalse('leaked provider field: '.$forbidden);
    }
});

it('adds no table for this domain', function (): void {
    // COR-UI08-001 §10: subscription operations reads existing Phase 20B truth and creates nothing.
    foreach (['platform_subscriptions', 'platform_subscription_invoices', 'billing_credits', 'platform_billing_credits'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table.' must not exist — this domain adds no table');
    }
});
