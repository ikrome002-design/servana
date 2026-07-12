<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\GenerateSubscriptionInvoicePdf;
use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('billing', 'phase20b', 'phase20b-api', 'subscription-api');

/*
 | Phase 20B — Merchant subscription self-service API (Plan §22, §47, §48, §49). Merchant Administrator,
 | merchant scope. Proves the reads, plan options, no-proration plan-change, billing-read-only mutation
 | denial, invoice list/detail, new-PDF generation vs existing-PDF download, tenant isolation, and the
 | absence of any trial/activation/issue/void/payment/Wallet route.
 */

/** @return array{0:User,1:Merchant,2:MerchantSubscription,3:SubscriptionPlan,4:SubscriptionPlanPrice} */
function p20bSubApi(MerchantBillingStatus $billing = MerchantBillingStatus::Active): array
{
    PlatformBillingSettings::factory()->create(['billing_mode' => BillingMode::FixedAmount, 'effective_from' => CarbonImmutable::now()->subYear()]);

    [$admin, $merchant] = activeAdmin();
    // billing_status is not mass-assignable (Merchant::$fillable = ['name']); set it directly.
    $merchant->billing_status = $billing;
    $merchant->save();

    $plan = SubscriptionPlan::factory()->create(['key' => 'starter', 'name' => 'Starter']);
    $price = SubscriptionPlanPrice::factory()->create([
        'plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES',
        'amount_minor' => 500000, 'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    $sub = MerchantSubscription::factory()->forMerchant($merchant)->status(S::Active)->create([
        'plan_id' => $plan->id, 'price_id' => $price->id, 'billing_interval' => 'monthly',
        'current_period_start' => '2026-07-01', 'current_period_end' => '2026-08-01',
    ]);

    return [$admin, $merchant, $sub, $plan, $price];
}

/** A second active plan + currently-effective monthly KES price the merchant can switch to. */
function p20bTargetPrice(): SubscriptionPlanPrice
{
    $plan = SubscriptionPlan::factory()->create(['key' => 'growth', 'name' => 'Growth']);

    return SubscriptionPlanPrice::factory()->create([
        'plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES',
        'amount_minor' => 900000, 'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
}

// --- Reads ---------------------------------------------------------------

it('serves the subscription dashboard with billing status shown separately + a server can map', function (): void {
    [$admin, , , $plan] = p20bSubApi();

    $response = test()->actingAs($admin, 'sanctum')->getJson('/api/v1/subscription')->assertOk();

    expect($response->json('data.status'))->toBe('active')
        ->and($response->json('data.billing_status'))->toBe('active')
        ->and($response->json('data.plan.key'))->toBe($plan->key)
        ->and($response->json('data.price.currency'))->toBe('KES')
        ->and($response->json('data.current_period_end'))->toBe('2026-08-01')
        ->and($response->json('data.can.schedule_plan_change'))->toBeTrue()
        ->and(strlen((string) $response->json('data.id')))->toBe(26) // ULID, not a bigint
        ->and($response->json('data'))->not->toHaveKey('merchant_id');
});

it('lists available plans with their effective price and flags the current plan', function (): void {
    [$admin] = p20bSubApi();
    p20bTargetPrice();

    $response = test()->actingAs($admin, 'sanctum')->getJson('/api/v1/subscription/plans')->assertOk();

    $plans = collect($response->json('data'));
    expect($plans->count())->toBeGreaterThanOrEqual(2);
    expect($plans->firstWhere('key', 'starter')['is_current'])->toBeTrue();
    expect($plans->firstWhere('key', 'growth')['effective_price']['amount_minor'])->toBe(900000);
});

// --- Plan change (no proration, next cycle) ------------------------------

it('schedules a no-proration next-cycle plan change with a server-computed effective_at', function (): void {
    [$admin, $merchant, $sub] = p20bSubApi();
    $target = p20bTargetPrice();

    $response = test()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/subscription/scheduled-plan-change', [
            'subscription_plan_ulid' => $target->plan->ulid,
            'subscription_plan_price_ulid' => $target->ulid,
        ])
        ->assertCreated();

    // effective_at is the current period end — never client-supplied.
    expect($response->json('data.effective_at'))->toBe('2026-08-01')
        ->and($response->json('data.status'))->toBe('scheduled')
        ->and($response->json('data.target_plan.key'))->toBe('growth');

    expect(ScheduledPlanChange::query()->where('merchant_subscription_id', $sub->id)->where('status', 'scheduled')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'subscription.plan_change_scheduled')->count())->toBe(1);
});

it('rejects a second pending scheduled change with a structured 409', function (): void {
    [$admin] = p20bSubApi();
    $target = p20bTargetPrice();
    $body = ['subscription_plan_ulid' => $target->plan->ulid, 'subscription_plan_price_ulid' => $target->ulid];

    test()->actingAs($admin, 'sanctum')->postJson('/api/v1/subscription/scheduled-plan-change', $body)->assertCreated();

    test()->actingAs($admin, 'sanctum')->postJson('/api/v1/subscription/scheduled-plan-change', $body)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'scheduled_plan_change_exists');
});

it('reads and cancels the pending scheduled plan change', function (): void {
    [$admin] = p20bSubApi();
    $target = p20bTargetPrice();
    test()->actingAs($admin, 'sanctum')->postJson('/api/v1/subscription/scheduled-plan-change', [
        'subscription_plan_ulid' => $target->plan->ulid, 'subscription_plan_price_ulid' => $target->ulid,
    ])->assertCreated();

    test()->actingAs($admin, 'sanctum')->getJson('/api/v1/subscription/scheduled-plan-change')
        ->assertOk()->assertJsonPath('data.status', 'scheduled');

    test()->actingAs($admin, 'sanctum')->postJson('/api/v1/subscription/scheduled-plan-change/cancel')
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect(AuditLog::query()->where('action', 'subscription.plan_change_cancelled')->count())->toBe(1);
});

it('denies a plan-change mutation while billing is read-only (grace) but still serves the read', function (): void {
    [$admin] = p20bSubApi(MerchantBillingStatus::ReadOnlyGrace);
    $target = p20bTargetPrice();

    test()->actingAs($admin, 'sanctum')->getJson('/api/v1/subscription')
        ->assertOk()->assertJsonPath('data.can.schedule_plan_change', false);

    test()->actingAs($admin, 'sanctum')->postJson('/api/v1/subscription/scheduled-plan-change', [
        'subscription_plan_ulid' => $target->plan->ulid, 'subscription_plan_price_ulid' => $target->ulid,
    ])->assertForbidden()->assertJsonPath('error.code', 'billing_read_only');
});

// --- Invoices + PDF ------------------------------------------------------

it('lists and shows subscription invoices with the payment-reference-pending flag', function (): void {
    [$admin, , $sub] = p20bSubApi();
    app(TenantContext::class)->bindForJob($sub->merchant);
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, User::factory()->create());

    test()->actingAs($admin, 'sanctum')->getJson('/api/v1/subscription-invoices')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.payment_reference_pending', true);

    test()->actingAs($admin, 'sanctum')->getJson("/api/v1/subscription-invoices/{$invoice->ulid}")
        ->assertOk()->assertJsonPath('data.id', $invoice->ulid)
        ->assertJsonPath('data.account_reference', null);
});

it('generates a new invoice PDF (mutation) and then serves an existing download link', function (): void {
    Storage::fake((string) config('files.disk'));
    [$admin, , $sub] = p20bSubApi();
    app(TenantContext::class)->bindForJob($sub->merchant);
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, User::factory()->create());

    test()->actingAs($admin, 'sanctum')->postJson("/api/v1/subscription-invoices/{$invoice->ulid}/pdf")
        ->assertOk()->assertJsonPath('data.has_pdf', true);

    expect(AuditLog::query()->where('action', 'subscription_invoice.pdf_generated')->count())->toBe(1);

    $link = test()->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/subscription-invoices/{$invoice->ulid}/pdf/download-link")
        ->assertOk();
    expect($link->json('data.url'))->toContain('signature=');
});

it('blocks new PDF generation in read-only billing but keeps an existing PDF downloadable', function (): void {
    Storage::fake((string) config('files.disk'));
    [$admin, $merchant, $sub] = p20bSubApi();
    app(TenantContext::class)->bindForJob($sub->merchant);
    $invoice = app(IssueSubscriptionInvoice::class)->handle($sub, User::factory()->create());
    app(GenerateSubscriptionInvoicePdf::class)->handle($invoice, User::factory()->create());

    $merchant->billing_status = MerchantBillingStatus::SuspendedBilling;
    $merchant->save();

    // New generation blocked...
    test()->actingAs($admin, 'sanctum')->postJson("/api/v1/subscription-invoices/{$invoice->ulid}/pdf")
        ->assertForbidden()->assertJsonPath('error.code', 'billing_read_only');

    // ...but the existing PDF stays downloadable.
    test()->actingAs($admin, 'sanctum')->getJson("/api/v1/subscription-invoices/{$invoice->ulid}/pdf/download-link")
        ->assertOk();
});

// --- Tenant isolation + boundary -----------------------------------------

it('404s a subscription invoice belonging to another merchant (no existence leak)', function (): void {
    [$admin] = p20bSubApi();

    $other = Merchant::factory()->active()->create();
    app(TenantContext::class)->bindForJob($other);
    $otherPlan = SubscriptionPlan::factory()->create();
    $otherPrice = SubscriptionPlanPrice::factory()->create(['plan_id' => $otherPlan->id, 'billing_interval' => 'monthly', 'currency' => 'KES']);
    $otherSub = MerchantSubscription::factory()->forMerchant($other)->status(S::Active)->create([
        'plan_id' => $otherPlan->id, 'price_id' => $otherPrice->id,
        'current_period_start' => '2026-07-01', 'current_period_end' => '2026-08-01',
    ]);
    app(TenantContext::class)->bindForJob($other);
    $otherInvoice = app(IssueSubscriptionInvoice::class)->handle($otherSub, User::factory()->create());

    test()->actingAs($admin, 'sanctum')->getJson("/api/v1/subscription-invoices/{$otherInvoice->ulid}")
        ->assertNotFound();
});

it('exposes no subscription payment / Wallet / issue / void route', function (): void {
    [$admin, , $sub] = p20bSubApi();

    foreach ([
        '/api/v1/subscription/pay',
        '/api/v1/subscription-invoices/'.$sub->ulid.'/pay',
        '/api/v1/subscription-invoices/'.$sub->ulid.'/void',
        '/api/v1/subscription/stk-push',
    ] as $guess) {
        test()->actingAs($admin, 'sanctum')->postJson($guess)->assertNotFound();
    }
});

it('denies a non-admin merchant role the subscription surface', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$frontOffice] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    test()->actingAs($frontOffice, 'sanctum')->getJson('/api/v1/subscription')->assertForbidden();
});
