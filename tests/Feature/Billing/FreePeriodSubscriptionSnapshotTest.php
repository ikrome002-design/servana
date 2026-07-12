<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\CreateTrialSubscription;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Models\FreePeriodOfferTarget;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('billing', 'phase20c', 'phase20c-subscription-snapshot');

/*
 | Phase 20C free-period subscription snapshot integration (Plan §53; Gate C3/C4). CreateTrialSubscription
 | resolves at most one free-period offer at the founding-admin anchor and snapshots its days once; no
 | offer ⇒ platform default; existing trials are never rewritten by later offer changes.
 */

function fpSettings(int $trialDays): void
{
    PlatformBillingSettings::factory()->create([
        'default_trial_days' => $trialDays,
        'billing_mode' => BillingMode::FixedAmount->value,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);
}

/** @return array{0:Merchant,1:SubscriptionPlanPrice} */
function fpFixture(): array
{
    $merchant = Merchant::factory()->create();
    MerchantUser::factory()->create([
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
        'created_at' => CarbonImmutable::now()->subDays(3),
    ]);
    $plan = SubscriptionPlan::factory()->create();
    $price = SubscriptionPlanPrice::factory()->create(['plan_id' => $plan->id, 'billing_interval' => 'monthly']);
    app(TenantContext::class)->bindForJob($merchant);

    return [$merchant, $price];
}

function fpMerchantOffer(Merchant $merchant, int $days): FreePeriodOffer
{
    $offer = FreePeriodOffer::factory()->active()->days($days)->scope(PromotionTargetScope::SelectedMerchants)
        ->create(['effective_from' => today()->subDays(10)]);
    FreePeriodOfferTarget::factory()->forMerchant($merchant)->create(['free_period_offer_id' => $offer->id]);

    return $offer;
}

function fpActor(): User
{
    return User::factory()->create();
}

it('falls back to the platform default trial days when no offer applies', function (): void {
    fpSettings(14);
    [$merchant, $price] = fpFixture();

    $sub = app(CreateTrialSubscription::class)->handle($merchant, $price, fpActor());

    expect($sub->trial_days_snapshot)->toBe(14)
        ->and($sub->free_period_offer_id)->toBeNull()
        ->and($sub->free_period_resolved_at)->toBeNull();
});

it('snapshots the resolved free-period offer days and provenance', function (): void {
    fpSettings(14);
    [$merchant, $price] = fpFixture();
    $offer = fpMerchantOffer($merchant, 45);

    $sub = app(CreateTrialSubscription::class)->handle($merchant, $price, fpActor());

    expect($sub->trial_days_snapshot)->toBe(45)
        ->and($sub->free_period_offer_id)->toBe($offer->id)
        ->and($sub->free_period_resolved_at)->not->toBeNull();
});

it('anchors the trial end to the founding-admin creation, not setup time, with the offer days', function (): void {
    fpSettings(14);
    [$merchant, $price] = fpFixture();
    fpMerchantOffer($merchant, 30);
    $foundingAdmin = MerchantUser::query()->where('merchant_id', $merchant->id)->sole();

    $sub = app(CreateTrialSubscription::class)->handle($merchant, $price, fpActor());

    expect($sub->trial_started_at->equalTo($foundingAdmin->created_at))->toBeTrue()
        ->and($sub->trial_ends_at->toDateString())->toBe(CarbonImmutable::parse($foundingAdmin->created_at)->addDays(30)->toDateString());
});

it('does not change an existing trial when the offer is later edited, paused, or cancelled', function (): void {
    fpSettings(14);
    [$merchant, $price] = fpFixture();
    $offer = fpMerchantOffer($merchant, 45);

    $sub = app(CreateTrialSubscription::class)->handle($merchant, $price, fpActor());
    $originalDays = $sub->trial_days_snapshot;

    DB::table('free_period_offers')->where('id', $offer->id)->update(['free_period_days' => 90, 'status' => 'cancelled']);

    expect($sub->refresh()->trial_days_snapshot)->toBe($originalDays)
        ->and($sub->free_period_offer_id)->toBe($offer->id);
});

it('is idempotent — replay returns the same subscription without recomputing', function (): void {
    fpSettings(14);
    [$merchant, $price] = fpFixture();
    fpMerchantOffer($merchant, 45);

    $first = app(CreateTrialSubscription::class)->handle($merchant, $price, fpActor());
    $second = app(CreateTrialSubscription::class)->handle($merchant, $price, fpActor());

    expect($second->id)->toBe($first->id)
        ->and($second->trial_days_snapshot)->toBe(45)
        ->and(MerchantSubscription::query()->where('merchant_id', $merchant->id)->count())->toBe(1);
});

it('does not retroactively apply a new offer to a pre-existing subscription', function (): void {
    fpSettings(14);
    [$merchant, $price] = fpFixture();

    // Subscription created BEFORE any offer exists → default days, no offer.
    $sub = app(CreateTrialSubscription::class)->handle($merchant, $price, fpActor());
    expect($sub->free_period_offer_id)->toBeNull();

    // A new offer created afterwards must not change the existing trial.
    fpMerchantOffer($merchant, 60);
    expect($sub->refresh()->trial_days_snapshot)->toBe(14)
        ->and($sub->free_period_offer_id)->toBeNull();
});
