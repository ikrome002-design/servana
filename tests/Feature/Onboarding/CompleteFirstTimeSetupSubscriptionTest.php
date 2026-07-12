<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Enums\SubscriptionPlanStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Onboarding\Actions\CompleteFirstTimeSetup;
use App\Domain\Onboarding\Data\FirstTimeSetupData;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class)->group('onboarding', 'phase20b-onboarding', 'billing');

/** @return array{0:Merchant,1:User} pending merchant + founding admin, tenant context bound. */
function p20boMerchant(?CarbonImmutable $adminCreatedAt = null): array
{
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create(); // pending_setup
    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
        'status' => MerchantUserStatus::Active,
        'created_at' => $adminCreatedAt ?? CarbonImmutable::now()->subDays(2),
    ]);
    app(TenantContext::class)->bindForJob($merchant);

    return [$merchant, $user];
}

function p20boSettings(int $trialDays = 14): void
{
    PlatformBillingSettings::factory()->create([
        'default_trial_days' => $trialDays,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);
}

/** @return array{0:SubscriptionPlan,1:SubscriptionPlanPrice} active plan + effective price. */
function p20boPlanPrice(): array
{
    $plan = SubscriptionPlan::factory()->create(['status' => SubscriptionPlanStatus::Active]);
    $price = SubscriptionPlanPrice::factory()->create([
        'plan_id' => $plan->id,
        'billing_interval' => 'monthly',
        'currency' => 'KES',
        'effective_from' => CarbonImmutable::now()->subMonth()->toDateString(),
        'effective_to' => null,
    ]);

    return [$plan, $price];
}

/** @param array<string,mixed> $overrides */
function p20boData(string $planUlid, string $priceUlid, array $overrides = []): FirstTimeSetupData
{
    return FirstTimeSetupData::fromArray(array_merge([
        'service_fee_tier' => 'split_tier',
        'subscription_plan_ulid' => $planUlid,
        'subscription_plan_price_ulid' => $priceUlid,
        'business_category' => 'Salon',
        'contact_phone' => '+254700000000',
        'contact_email' => 'info@demo.co.ke',
        'receipt_display_name' => 'Demo Salon',
        'address' => '123 Biashara St',
        'town' => 'Nairobi',
        'timezone' => 'Africa/Nairobi',
        'branch' => ['name' => 'Main Branch', 'code' => 'MAIN', 'town' => 'Nairobi', 'address' => 'x', 'phone' => '+254700000111', 'email' => 'branch@demo.co.ke'],
        'branch_manager_email' => 'bm@demo.co.ke',
        'hr_email' => 'hr@demo.co.ke',
    ], $overrides));
}

it('binds a trialing subscription anchored to the founding admin membership on completion', function (): void {
    $adminCreated = CarbonImmutable::parse('2026-05-01 08:00:00');
    p20boSettings(14);
    [$merchant, $actor] = p20boMerchant($adminCreated);
    $founding = MerchantUser::query()->where('merchant_id', $merchant->id)->where('role', MerchantUserRole::MerchantAdmin)->sole();
    [$plan, $price] = p20boPlanPrice();

    $result = app(CompleteFirstTimeSetup::class)->handle($merchant, $actor, p20boData($plan->ulid, $price->ulid));

    $sub = MerchantSubscription::query()->where('merchant_id', $merchant->id)->sole();
    expect($result->status)->toBe(MerchantStatus::Active)
        ->and($result->service_fee_tier->value)->toBe('split_tier') // service_fee_tier intact
        ->and($sub->status)->toBe(MerchantSubscriptionStatus::Trialing)
        ->and($sub->plan_id)->toBe($plan->id)
        ->and($sub->price_id)->toBe($price->id)
        ->and($sub->trial_days_snapshot)->toBe(14)
        ->and($sub->trial_started_at->equalTo($founding->created_at))->toBeTrue()
        ->and($merchant->fresh()->billing_status)->toBe(MerchantBillingStatus::Trialing);
});

it('does not rewrite the trial snapshot when platform settings later change', function (): void {
    p20boSettings(14);
    [$merchant, $actor] = p20boMerchant();
    [$plan, $price] = p20boPlanPrice();
    app(CompleteFirstTimeSetup::class)->handle($merchant, $actor, p20boData($plan->ulid, $price->ulid));

    PlatformBillingSettings::factory()->create(['default_trial_days' => 30, 'effective_from' => CarbonImmutable::now()->addDay()]);

    expect(MerchantSubscription::query()->where('merchant_id', $merchant->id)->value('trial_days_snapshot'))->toBe(14);
});

it('rejects a retired plan', function (): void {
    p20boSettings();
    [$merchant, $actor] = p20boMerchant();
    $plan = SubscriptionPlan::factory()->create(['status' => SubscriptionPlanStatus::Retired]);
    $price = SubscriptionPlanPrice::factory()->create(['plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES']);

    expect(fn () => app(CompleteFirstTimeSetup::class)->handle($merchant, $actor, p20boData($plan->ulid, $price->ulid)))
        ->toThrow(ValidationException::class);
});

it('rejects a price that belongs to another plan', function (): void {
    p20boSettings();
    [$merchant, $actor] = p20boMerchant();
    [$planA, $priceA] = p20boPlanPrice();
    $planB = SubscriptionPlan::factory()->create(['status' => SubscriptionPlanStatus::Active]);

    expect(fn () => app(CompleteFirstTimeSetup::class)->handle($merchant, $actor, p20boData($planB->ulid, $priceA->ulid)))
        ->toThrow(ValidationException::class);
});

it('rejects a non-effective (historical) price', function (): void {
    p20boSettings();
    [$merchant, $actor] = p20boMerchant();
    $plan = SubscriptionPlan::factory()->create(['status' => SubscriptionPlanStatus::Active]);
    $price = SubscriptionPlanPrice::factory()->create([
        'plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES',
        'effective_from' => CarbonImmutable::now()->subYear()->toDateString(),
        'effective_to' => CarbonImmutable::now()->subMonth()->toDateString(), // ended → not effective now
    ]);

    expect(fn () => app(CompleteFirstTimeSetup::class)->handle($merchant, $actor, p20boData($plan->ulid, $price->ulid)))
        ->toThrow(ValidationException::class);
});

it('rolls the whole completion back when the plan/price is invalid (no partial setup, no subscription)', function (): void {
    p20boSettings();
    [$merchant, $actor] = p20boMerchant();
    $plan = SubscriptionPlan::factory()->create(['status' => SubscriptionPlanStatus::Retired]);
    $price = SubscriptionPlanPrice::factory()->create(['plan_id' => $plan->id, 'billing_interval' => 'monthly', 'currency' => 'KES']);

    try {
        app(CompleteFirstTimeSetup::class)->handle($merchant, $actor, p20boData($plan->ulid, $price->ulid));
    } catch (ValidationException) {
        // expected
    }

    expect($merchant->fresh()->status)->toBe(MerchantStatus::PendingSetup)
        ->and($merchant->fresh()->setup_completed_at)->toBeNull()
        ->and(MerchantSubscription::query()->where('merchant_id', $merchant->id)->count())->toBe(0);
});

it('guarantees a completed merchant always has exactly one current subscription', function (): void {
    p20boSettings();
    [$merchant, $actor] = p20boMerchant();
    [$plan, $price] = p20boPlanPrice();

    $result = app(CompleteFirstTimeSetup::class)->handle($merchant, $actor, p20boData($plan->ulid, $price->ulid));

    expect($result->setup_completed_at)->not->toBeNull()
        ->and(MerchantSubscription::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('status', MerchantSubscriptionStatus::nonTerminalValues())
            ->count())->toBe(1);
});
