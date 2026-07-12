<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Enums\ScheduledPlanChangeStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'phase20b-scheduler', 'scheduler');

function p20bschSettings(int $graceDays): void
{
    PlatformBillingSettings::factory()->create([
        'grace_days' => $graceDays,
        'default_trial_days' => 14,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);
}

/** A subscription for a fresh merchant, with an explicit status and trial window. */
function p20bschSub(S $status, CarbonImmutable $trialStart, CarbonImmutable $trialEnd): MerchantSubscription
{
    $merchant = Merchant::factory()->create();

    return MerchantSubscription::factory()
        ->forMerchant($merchant)
        ->status($status)
        ->create(['trial_started_at' => $trialStart, 'trial_ends_at' => $trialEnd]);
}

function runLifecycle(): void
{
    // Ensure no ambient tenant context leaks into the scope-free scheduler scan.
    app(TenantContext::class)->reset();
    test()->artisan('billing:process-subscription-lifecycle')->assertExitCode(0);
}

it('moves an expired trial into read-only grace when grace is configured', function (): void {
    p20bschSettings(7);
    $sub = p20bschSub(S::Trialing, CarbonImmutable::now()->subDays(20), CarbonImmutable::now()->subDay());

    runLifecycle();

    expect($sub->fresh()->status)->toBe(S::ReadOnlyGrace)
        ->and($sub->fresh()->merchant->billing_status)->toBe(MerchantBillingStatus::ReadOnlyGrace);
});

it('expires a trial with no configured grace', function (): void {
    p20bschSettings(0);
    $sub = p20bschSub(S::Trialing, CarbonImmutable::now()->subDays(20), CarbonImmutable::now()->subDay());

    runLifecycle();

    expect($sub->fresh()->status)->toBe(S::Expired)
        ->and($sub->fresh()->merchant->billing_status)->toBe(MerchantBillingStatus::SuspendedBilling)
        ->and($sub->fresh()->merchant->billing_status_reason)->toBe('subscription_expired');
});

it('suspends a grace subscription once the grace window elapses', function (): void {
    p20bschSettings(7);
    // trial ended 10 days ago → trial_ends_at + 7 grace days is in the past.
    $sub = p20bschSub(S::ReadOnlyGrace, CarbonImmutable::now()->subDays(30), CarbonImmutable::now()->subDays(10));

    runLifecycle();

    expect($sub->fresh()->status)->toBe(S::SuspendedBilling)
        ->and($sub->fresh()->merchant->billing_status)->toBe(MerchantBillingStatus::SuspendedBilling);
});

it('does not touch a trial that has not yet ended (no early terminal projection)', function (): void {
    p20bschSettings(7);
    $sub = p20bschSub(S::Trialing, CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDays(10));
    $sub->merchant->update(['billing_status' => MerchantBillingStatus::Trialing]);

    runLifecycle();

    expect($sub->fresh()->status)->toBe(S::Trialing)
        ->and($sub->fresh()->merchant->billing_status)->toBe(MerchantBillingStatus::Trialing);
});

it('does not suspend a grace subscription before the grace window elapses', function (): void {
    p20bschSettings(30);
    // trial ended 5 days ago, grace is 30 days → still within grace.
    $sub = p20bschSub(S::ReadOnlyGrace, CarbonImmutable::now()->subDays(20), CarbonImmutable::now()->subDays(5));

    runLifecycle();

    expect($sub->fresh()->status)->toBe(S::ReadOnlyGrace);
});

it('applies a due scheduled plan change exactly once', function (): void {
    p20bschSettings(7);
    $merchant = Merchant::factory()->create();
    $sub = MerchantSubscription::factory()->forMerchant($merchant)->status(S::Active)->create([
        'current_period_start' => '2026-07-01',
        'current_period_end' => '2026-08-01',
        'billing_interval' => 'monthly',
    ]);
    $targetPlan = SubscriptionPlan::factory()->create();
    $targetPrice = SubscriptionPlanPrice::factory()->create(['plan_id' => $targetPlan->id, 'billing_interval' => 'quarterly']);
    $change = ScheduledPlanChange::factory()->create([
        'merchant_id' => $merchant->id,
        'merchant_subscription_id' => $sub->id,
        'target_plan_id' => $targetPlan->id,
        'target_price_id' => $targetPrice->id,
        'effective_at' => CarbonImmutable::now()->subDay()->toDateString(),
        'status' => ScheduledPlanChangeStatus::Scheduled,
        'created_by' => User::factory()->create()->id,
    ]);

    runLifecycle();

    expect($change->fresh()->status)->toBe(ScheduledPlanChangeStatus::Applied)
        ->and($sub->fresh()->price_id)->toBe($targetPrice->id)
        ->and($sub->fresh()->billing_interval->value)->toBe('quarterly');
});

it('is idempotent across repeated runs', function (): void {
    p20bschSettings(0);
    $sub = p20bschSub(S::Trialing, CarbonImmutable::now()->subDays(20), CarbonImmutable::now()->subDay());

    runLifecycle();
    $statusAfterFirst = $sub->fresh()->status;
    runLifecycle(); // replay

    expect($sub->fresh()->status)->toBe($statusAfterFirst)
        ->and($sub->fresh()->status)->toBe(S::Expired);
});
