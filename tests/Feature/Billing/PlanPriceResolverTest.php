<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Queries\ResolveEffectivePlanPrice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'plan-price');

/*
 | Phase 20A plan-price resolution (Plan §13.9, §47; ADR-011). Deterministic current + historical
 | resolution from the sole price source; exactly one row matches per (plan, interval, currency,
 | instant) thanks to the DB EXCLUDE constraint.
 */

function resolvePrice(): ResolveEffectivePlanPrice
{
    return app(ResolveEffectivePlanPrice::class);
}

it('resolves the current price for a plan+interval+currency on a date', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    SubscriptionPlanPrice::factory()->for($plan, 'plan')->create([
        'amount_minor' => 500000, 'currency' => 'KES', 'billing_interval' => 'monthly',
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);

    $price = resolvePrice()->resolve($plan, BillingInterval::Monthly, 'KES', CarbonImmutable::parse('2026-07-15'));

    expect($price)->not->toBeNull()
        ->and($price->amount_minor)->toBe(500000);
});

it('resolves the historical price for a past date and the newer price for a current date', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    SubscriptionPlanPrice::factory()->for($plan, 'plan')->create([
        'amount_minor' => 400000, 'currency' => 'KES', 'billing_interval' => 'monthly',
        'effective_from' => '2026-01-01', 'effective_to' => '2026-06-01',
    ]);
    SubscriptionPlanPrice::factory()->for($plan, 'plan')->create([
        'amount_minor' => 600000, 'currency' => 'KES', 'billing_interval' => 'monthly',
        'effective_from' => '2026-06-01', 'effective_to' => null,
    ]);

    expect(resolvePrice()->resolve($plan, BillingInterval::Monthly, 'KES', CarbonImmutable::parse('2026-03-01'))->amount_minor)->toBe(400000);
    expect(resolvePrice()->resolve($plan, BillingInterval::Monthly, 'KES', CarbonImmutable::parse('2026-07-01'))->amount_minor)->toBe(600000);
});

it('returns null when no price is effective on the date', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    SubscriptionPlanPrice::factory()->for($plan, 'plan')->create([
        'amount_minor' => 500000, 'currency' => 'KES', 'billing_interval' => 'monthly',
        'effective_from' => '2026-06-01', 'effective_to' => null,
    ]);

    expect(resolvePrice()->resolve($plan, BillingInterval::Monthly, 'KES', CarbonImmutable::parse('2026-01-01')))->toBeNull();
});

it('distinguishes interval and currency', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    SubscriptionPlanPrice::factory()->for($plan, 'plan')->create([
        'amount_minor' => 500000, 'currency' => 'KES', 'billing_interval' => 'monthly',
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);
    SubscriptionPlanPrice::factory()->for($plan, 'plan')->create([
        'amount_minor' => 5000000, 'currency' => 'KES', 'billing_interval' => 'annual',
        'effective_from' => '2026-01-01', 'effective_to' => null,
    ]);

    expect(resolvePrice()->resolve($plan, BillingInterval::Annual, 'KES', CarbonImmutable::parse('2026-07-15'))->amount_minor)->toBe(5000000);
    expect(resolvePrice()->resolve($plan, BillingInterval::Monthly, 'USD', CarbonImmutable::parse('2026-07-15')))->toBeNull();
});
