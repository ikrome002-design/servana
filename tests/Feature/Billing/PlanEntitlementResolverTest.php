<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\PlanContextResolver;
use App\Domain\Billing\Models\PlanEntitlement;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Queries\ResolvePlanEntitlement;
use App\Domain\Billing\Services\SubscriptionPlanContextResolver;
use App\Domain\Billing\ValueObjects\EntitlementDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'entitlement');

/*
 | Phase 20A plan-entitlement resolver substrate (Plan §20). Proves enabled allows, disabled/
 | absent denies, limit boundary behaviour, and downgrade no-data-loss at the service level.
 | The merchant→plan binding is deferred to Phase 20B (PlanContextResolver); the default is
 | unbound and fabricates no subscription.
 */

function resolveEntitlement(): ResolvePlanEntitlement
{
    return app(ResolvePlanEntitlement::class);
}

it('allows an enabled unlimited entitlement', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    PlanEntitlement::factory()->for($plan, 'plan')->unlimited()->create(['entitlement_key' => 'reports.advanced']);

    $decision = resolveEntitlement()->resolve($plan, 'reports.advanced');

    expect($decision->allowed)->toBeTrue()
        ->and($decision->code)->toBe(EntitlementDecision::CODE_ALLOWED)
        ->and($decision->limit)->toBeNull();
});

it('denies an absent entitlement', function (): void {
    $plan = SubscriptionPlan::factory()->create();

    $decision = resolveEntitlement()->resolve($plan, 'reports.advanced');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->code)->toBe(EntitlementDecision::CODE_ABSENT);
});

it('denies a disabled entitlement', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    PlanEntitlement::factory()->for($plan, 'plan')->disabled()->create(['entitlement_key' => 'sms.bulk']);

    $decision = resolveEntitlement()->resolve($plan, 'sms.bulk');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->code)->toBe(EntitlementDecision::CODE_DISABLED);
});

it('allows below the limit and denies at/over the limit boundary', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    PlanEntitlement::factory()->for($plan, 'plan')->limited(3)->create(['entitlement_key' => 'merchant.branch.count']);

    expect(resolveEntitlement()->resolve($plan, 'merchant.branch.count', 2)->allowed)->toBeTrue();
    expect(resolveEntitlement()->resolve($plan, 'merchant.branch.count', 3)->allowed)->toBeFalse();

    $atLimit = resolveEntitlement()->resolve($plan, 'merchant.branch.count', 4);
    expect($atLimit->allowed)->toBeFalse()
        ->and($atLimit->code)->toBe(EntitlementDecision::CODE_LIMIT_EXCEEDED)
        ->and($atLimit->limit)->toBe(3);
});

it('denies new usage after a downgrade without deleting existing entitlement data (no side effects)', function (): void {
    $plan = SubscriptionPlan::factory()->create();
    $entitlement = PlanEntitlement::factory()->for($plan, 'plan')->limited(5)->create(['entitlement_key' => 'merchant.branch.count']);

    // Downgrade: lower the limit below current usage.
    $entitlement->update(['limit_int' => 2]);

    $decision = resolveEntitlement()->resolve($plan->fresh(), 'merchant.branch.count', 4);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->code)->toBe(EntitlementDecision::CODE_LIMIT_EXCEEDED);
    // The resolver has no side effects: the entitlement row and its data are untouched.
    expect(PlanEntitlement::query()->where('id', $entitlement->id)->exists())->toBeTrue()
        ->and($entitlement->fresh()->limit_int)->toBe(2);
});

it('binds the concrete subscription plan-context resolver once merchant subscriptions exist', function (): void {
    $resolver = app(PlanContextResolver::class);

    expect($resolver)->toBeInstanceOf(SubscriptionPlanContextResolver::class)
        ->and($resolver->resolveActivePlan(999))->toBeNull();
});
