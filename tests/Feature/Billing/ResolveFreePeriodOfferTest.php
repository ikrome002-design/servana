<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Models\FreePeriodOfferTarget;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Queries\ResolveFreePeriodOffer;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'phase20c', 'phase20c-resolver');

/*
 | Phase 20C free-period-offer resolution (Plan §53). Same precedence/tie-break/window rules as the
 | promotion resolver, anchored at the Merchant-Administrator creation instant (Gate C3).
 */

/** @param array<string,mixed> $overrides */
function activeOffer(PromotionTargetScope $scope, array $overrides = []): FreePeriodOffer
{
    return FreePeriodOffer::factory()->active()->scope($scope)
        ->create(array_merge(['effective_from' => today()->subDay(), 'effective_to' => null], $overrides));
}

beforeEach(function (): void {
    $this->merchant = Merchant::factory()->create();
    $this->plan = SubscriptionPlan::factory()->create();
    $this->mode = BillingMode::FixedAmount;
    $this->resolver = new ResolveFreePeriodOffer;
});

it('resolves nothing when no offer matches', function (): void {
    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode))->toBeNull();
});

it('prefers a merchant target over a global offer', function (): void {
    activeOffer(PromotionTargetScope::AllNewMerchants);
    $merchantOffer = activeOffer(PromotionTargetScope::SelectedMerchants);
    FreePeriodOfferTarget::factory()->forMerchant($this->merchant)->create(['free_period_offer_id' => $merchantOffer->id]);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode)?->id)->toBe($merchantOffer->id);
});

it('falls back to a global all_new_merchants offer', function (): void {
    $global = activeOffer(PromotionTargetScope::AllNewMerchants, ['free_period_days' => 45]);
    $resolved = $this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode);
    expect($resolved?->id)->toBe($global->id)->and($resolved?->free_period_days)->toBe(45);
});

it('resolves billing-mode targets for all three canonical modes', function (): void {
    foreach (BillingMode::cases() as $mode) {
        $offer = activeOffer(PromotionTargetScope::BillingMode);
        FreePeriodOfferTarget::factory()->forBillingMode($mode)->create(['free_period_offer_id' => $offer->id]);
        expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $mode)?->id)->toBe($offer->id);
    }
});

it('excludes non-active and out-of-window offers', function (): void {
    foreach ([FreePeriodOfferStatus::Draft, FreePeriodOfferStatus::Scheduled, FreePeriodOfferStatus::Paused, FreePeriodOfferStatus::Expired, FreePeriodOfferStatus::Cancelled] as $status) {
        $offer = FreePeriodOffer::factory()->status($status)->scope(PromotionTargetScope::SelectedMerchants)
            ->create(['effective_from' => today()->subDays(30), 'effective_to' => null]);
        FreePeriodOfferTarget::factory()->forMerchant($this->merchant)->create(['free_period_offer_id' => $offer->id]);
    }
    $future = activeOffer(PromotionTargetScope::SelectedMerchants, ['effective_from' => today()->addDays(5)]);
    FreePeriodOfferTarget::factory()->forMerchant($this->merchant)->create(['free_period_offer_id' => $future->id]);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode))->toBeNull();
});

it('resolves against the supplied anchor date, not just today', function (): void {
    // Offer effective only in a past window; resolving at an anchor inside that window matches.
    $offer = activeOffer(PromotionTargetScope::AllNewMerchants, [
        'effective_from' => today()->subDays(20),
        'effective_to' => today()->subDays(5),
    ]);

    expect($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode))->toBeNull()
        ->and($this->resolver->resolve($this->merchant->id, $this->plan->id, $this->mode, today()->subDays(10))?->id)->toBe($offer->id);
});
