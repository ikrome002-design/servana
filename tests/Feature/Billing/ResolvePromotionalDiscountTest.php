<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Models\PromotionalDiscountTarget;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Queries\ResolvePromotionalDiscount;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'phase20c', 'phase20c-resolver');

/*
 | Phase 20C promotional-discount resolution (Plan §53). Precedence merchant > plan > billing_mode >
 | global; ties by latest effective_from then ascending target ULID; only active + in-window records.
 */

/** @param array<string,mixed> $overrides */
function activePromo(PromotionTargetScope $scope, array $overrides = []): PromotionalDiscount
{
    return PromotionalDiscount::factory()->active()->scope($scope)
        ->create(array_merge(['effective_from' => today()->subDay(), 'effective_to' => null], $overrides));
}

function promoMerchantTarget(PromotionalDiscount $p, Merchant $m, ?string $ulid = null): void
{
    PromotionalDiscountTarget::factory()->forMerchant($m)->create(array_filter([
        'promotional_discount_id' => $p->id,
        'ulid' => $ulid,
    ], fn ($v): bool => $v !== null));
}

function promoPlanTarget(PromotionalDiscount $p, SubscriptionPlan $plan): void
{
    PromotionalDiscountTarget::factory()->forPlan($plan)->create(['promotional_discount_id' => $p->id]);
}

function promoModeTarget(PromotionalDiscount $p, BillingMode $mode): void
{
    PromotionalDiscountTarget::factory()->forBillingMode($mode)->create(['promotional_discount_id' => $p->id]);
}

beforeEach(function (): void {
    $this->merchant = Merchant::factory()->create();
    $this->plan = SubscriptionPlan::factory()->create();
    $this->mode = BillingMode::FixedAmount;
    $this->resolver = new ResolvePromotionalDiscount;
});

it('resolves nothing when no promotion matches', function (): void {
    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode))->toBeNull();
});

it('prefers a merchant target over a plan target', function (): void {
    $planPromo = activePromo(PromotionTargetScope::SelectedPlans);
    promoPlanTarget($planPromo, $this->plan);
    $merchantPromo = activePromo(PromotionTargetScope::SelectedMerchants);
    promoMerchantTarget($merchantPromo, $this->merchant);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode)?->id)->toBe($merchantPromo->id);
});

it('prefers a plan target over a billing-mode target', function (): void {
    $modePromo = activePromo(PromotionTargetScope::BillingMode);
    promoModeTarget($modePromo, $this->mode);
    $planPromo = activePromo(PromotionTargetScope::SelectedPlans);
    promoPlanTarget($planPromo, $this->plan);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode)?->id)->toBe($planPromo->id);
});

it('prefers a billing-mode target over a global offer', function (): void {
    $global = activePromo(PromotionTargetScope::AllNewMerchants);
    $modePromo = activePromo(PromotionTargetScope::BillingMode);
    promoModeTarget($modePromo, $this->mode);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode)?->id)->toBe($modePromo->id);
});

it('falls back to a global all_new_merchants offer', function (): void {
    $global = activePromo(PromotionTargetScope::AllNewMerchants);
    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode)?->id)->toBe($global->id);
});

it('resolves billing-mode targets for all three canonical modes', function (): void {
    foreach (BillingMode::cases() as $mode) {
        $promo = activePromo(PromotionTargetScope::BillingMode);
        promoModeTarget($promo, $mode);
        expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $mode)?->id)->toBe($promo->id);
    }
});

it('breaks ties within a precedence class by latest effective_from', function (): void {
    $older = activePromo(PromotionTargetScope::SelectedMerchants, ['effective_from' => today()->subDays(10)]);
    promoMerchantTarget($older, $this->merchant);
    $newer = activePromo(PromotionTargetScope::SelectedMerchants, ['effective_from' => today()->subDay()]);
    promoMerchantTarget($newer, $this->merchant);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode)?->id)->toBe($newer->id);
});

it('breaks same-effective_from ties by ascending target ULID', function (): void {
    $from = today()->subDay();
    $a = activePromo(PromotionTargetScope::SelectedMerchants, ['effective_from' => $from]);
    $b = activePromo(PromotionTargetScope::SelectedMerchants, ['effective_from' => $from]);

    // Assign explicit ULIDs: 'A...' (smaller) wins over 'B...'.
    $smaller = '0AAAAAAAAAAAAAAAAAAAAAAAAA';
    $larger = '0BBBBBBBBBBBBBBBBBBBBBBBBB';
    promoMerchantTarget($a, $this->merchant, $smaller);
    promoMerchantTarget($b, $this->merchant, $larger);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode)?->id)->toBe($a->id);
});

it('excludes non-active statuses from resolution', function (): void {
    foreach ([PromotionStatus::Draft, PromotionStatus::Scheduled, PromotionStatus::Paused, PromotionStatus::Expired, PromotionStatus::Cancelled] as $status) {
        $promo = PromotionalDiscount::factory()->status($status)->scope(PromotionTargetScope::SelectedMerchants)
            ->create(['effective_from' => today()->subDays(30), 'effective_to' => null]);
        promoMerchantTarget($promo, $this->merchant);
    }

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode))->toBeNull();
});

it('excludes out-of-window active records (future start and past end)', function (): void {
    $future = activePromo(PromotionTargetScope::SelectedMerchants, ['effective_from' => today()->addDays(5)]);
    promoMerchantTarget($future, $this->merchant);
    $past = activePromo(PromotionTargetScope::SelectedMerchants, ['effective_from' => today()->subDays(20), 'effective_to' => today()->subDay()]);
    promoMerchantTarget($past, $this->merchant);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode))->toBeNull();
});

it('does not leak a promotion targeted at a different merchant', function (): void {
    $other = Merchant::factory()->create();
    $promo = activePromo(PromotionTargetScope::SelectedMerchants);
    promoMerchantTarget($promo, $other);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode))->toBeNull();
});

it('is stable regardless of insertion order', function (): void {
    $from = today()->subDay();
    $b = activePromo(PromotionTargetScope::SelectedMerchants, ['effective_from' => $from]);
    promoMerchantTarget($b, $this->merchant, '0BBBBBBBBBBBBBBBBBBBBBBBBB');
    $a = activePromo(PromotionTargetScope::SelectedMerchants, ['effective_from' => $from]);
    promoMerchantTarget($a, $this->merchant, '0AAAAAAAAAAAAAAAAAAAAAAAAA');

    // Run twice; the min-ULID target wins both times.
    $first = $this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode)?->id;
    $second = $this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode)?->id;
    expect($first)->toBe($a->id)->and($second)->toBe($a->id);
});
