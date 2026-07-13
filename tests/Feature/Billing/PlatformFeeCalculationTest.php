<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Queries\ResolveMerchantServiceFeeTier;
use App\Domain\Billing\Services\AllocatePlatformFeeByLargestRemainder;
use App\Domain\Billing\Services\CalculatePlatformFee;
use App\Domain\Billing\Services\PlatformFeeConfigurationStateMachine;
use App\Domain\Billing\Services\PlatformFeeDisputeStateMachine;
use App\Domain\Merchants\Enums\ServiceFeeTier;

uses()->group('billing', 'phase20e', 'phase20e-calc');

/*
 | Phase 20E arithmetic engine — pure unit/property/boundary proof (Plan §51; ADR-005). No database.
 */

function pfCalc(): CalculatePlatformFee
{
    return new CalculatePlatformFee;
}

// ---- round-half-up boundaries --------------------------------------------------

it('returns a zero fee for a zero basis', function (): void {
    $fee = pfCalc()->calculate(0, 250, CanonicalPlatformFeeTier::CustomerCentric, null, 'KES');
    expect($fee->grossMinor)->toBe(0);
});

it('rounds half-up: below/at/above the half', function (): void {
    // basis 199 @ 2.50% = 4.975 → 5 (above half); construct exact-half and below-half cases.
    // 1 @ 5000 bps = 0.5 → round-half-up = 1 (exact half).
    // 1 @ 4999 bps = 0.4999 → 0 (below half).
    // 1 @ 5001 bps = 0.5001 → 1 (above half).
    expect(pfCalc()->calculate(1, 5000, CanonicalPlatformFeeTier::CustomerCentric, null, 'KES')->grossMinor)->toBe(1)
        ->and(pfCalc()->calculate(1, 4999, CanonicalPlatformFeeTier::CustomerCentric, null, 'KES')->grossMinor)->toBe(0)
        ->and(pfCalc()->calculate(1, 5001, CanonicalPlatformFeeTier::CustomerCentric, null, 'KES')->grossMinor)->toBe(1);
});

it('handles the 0 bps, 1 bp, and 10000 bps rate boundaries', function (): void {
    expect(pfCalc()->calculate(1000000, 0, CanonicalPlatformFeeTier::CustomerCentric, null, 'KES')->grossMinor)->toBe(0)
        ->and(pfCalc()->calculate(10000, 1, CanonicalPlatformFeeTier::CustomerCentric, null, 'KES')->grossMinor)->toBe(1)
        ->and(pfCalc()->calculate(1000000, 10000, CanonicalPlatformFeeTier::CustomerCentric, null, 'KES')->grossMinor)->toBe(1000000);
});

it('computes a large amount without floating point drift', function (): void {
    // 9_000_000_000 minor @ 2.50% = 225_000_000 exactly.
    $fee = pfCalc()->calculate(9_000_000_000, 250, CanonicalPlatformFeeTier::CustomerCentric, null, 'KES');
    expect($fee->grossMinor)->toBe(225_000_000);
});

// ---- tier behaviour ------------------------------------------------------------

it('customer-centric: shifts nothing, merchant absorbs the full fee', function (): void {
    $fee = pfCalc()->calculate(10000, 250, CanonicalPlatformFeeTier::CustomerCentric, null, 'KES');
    expect($fee->grossMinor)->toBe(250)
        ->and($fee->clientShiftedMinor)->toBe(0)
        ->and($fee->merchantAbsorbedMinor)->toBe(250)
        ->and($fee->merchantLiabilityMinor)->toBe(250);
});

it('business-centric: shifts the full fee to the client, merchant absorbs nothing', function (): void {
    $fee = pfCalc()->calculate(10000, 250, CanonicalPlatformFeeTier::BusinessCentric, null, 'KES');
    expect($fee->clientShiftedMinor)->toBe(250)
        ->and($fee->merchantAbsorbedMinor)->toBe(0)
        ->and($fee->merchantLiabilityMinor)->toBe(250);
});

it('shared: splits by the configured basis points and the residual lands on the merchant', function (): void {
    // gross 251 @ 50% split → shifted = round_half_up(251 * 5000/10000) = round_half_up(125.5) = 126.
    $fee = pfCalc()->calculate(10040, 250, CanonicalPlatformFeeTier::Shared, 5000, 'KES');
    expect($fee->grossMinor)->toBe(251)
        ->and($fee->clientShiftedMinor)->toBe(126)
        ->and($fee->merchantAbsorbedMinor)->toBe(125)
        ->and($fee->clientShiftedMinor + $fee->merchantAbsorbedMinor)->toBe($fee->grossMinor)
        ->and($fee->merchantLiabilityMinor)->toBe($fee->grossMinor);
});

it('fails closed when a shared tier has no configured split', function (): void {
    pfCalc()->calculate(10000, 250, CanonicalPlatformFeeTier::Shared, null, 'KES');
})->throws(PlatformFeeException::class);

it('uppercases the currency on the result', function (): void {
    expect(pfCalc()->calculate(10000, 250, CanonicalPlatformFeeTier::CustomerCentric, null, 'kes')->currency)->toBe('KES');
});

// ---- largest-remainder allocation ----------------------------------------------

function pfAllocator(): AllocatePlatformFeeByLargestRemainder
{
    return new AllocatePlatformFeeByLargestRemainder;
}

it('allocates so the item shares sum exactly to the total', function (): void {
    $shares = pfAllocator()->allocate(100, ['a' => 3, 'b' => 3, 'c' => 4]);
    expect(array_sum($shares))->toBe(100);
});

it('is deterministic and independent of input key ordering', function (): void {
    $one = pfAllocator()->allocate(10, ['a' => 1, 'b' => 1, 'c' => 1]);
    $two = pfAllocator()->allocate(10, ['c' => 1, 'b' => 1, 'a' => 1]);
    expect($one)->toBe($two)
        ->and($one['a'])->toBe(4) // ascending-ULID tie-break gives the residual unit to 'a'
        ->and($one['b'])->toBe(3)
        ->and($one['c'])->toBe(3);
});

it('handles residual 0, 1, and multi-unit distributions', function (): void {
    expect(array_sum(pfAllocator()->allocate(9, ['a' => 1, 'b' => 1, 'c' => 1])))->toBe(9)   // residual 0
        ->and(array_sum(pfAllocator()->allocate(10, ['a' => 1, 'b' => 1, 'c' => 1])))->toBe(10) // residual 1
        ->and(array_sum(pfAllocator()->allocate(11, ['a' => 1, 'b' => 1, 'c' => 1])))->toBe(11); // residual 2
});

it('reconciles zero-weight items by distributing the total in key order', function (): void {
    $shares = pfAllocator()->allocate(2, ['a' => 0, 'b' => 0, 'c' => 0]);
    expect(array_sum($shares))->toBe(2)
        ->and($shares['a'])->toBe(1)
        ->and($shares['b'])->toBe(1)
        ->and($shares['c'])->toBe(0);
});

it('allocateFee keeps item gross and client-shifted sums equal to the invoice fee', function (): void {
    $fee = pfCalc()->calculate(30040, 250, CanonicalPlatformFeeTier::Shared, 5000, 'KES');
    $items = pfAllocator()->allocateFee($fee, ['a' => 10000, 'b' => 10000, 'c' => 10040]);

    $gross = array_sum(array_map(fn ($i) => $i->grossMinor, $items));
    $shifted = array_sum(array_map(fn ($i) => $i->clientShiftedMinor, $items));

    expect($gross)->toBe($fee->grossMinor)
        ->and($shifted)->toBe($fee->clientShiftedMinor)
        ->and(collect($items)->every(fn ($i) => $i->clientShiftedMinor + $i->absorbedMinor === $i->grossMinor))->toBeTrue();
});

// ---- tier resolution (split_tier → shared mapping + precedence + fail-closed) ---

it('maps the shipped split_tier merchant seam to the canonical shared tier', function (): void {
    $resolver = new ResolveMerchantServiceFeeTier;
    expect($resolver->resolve(ServiceFeeTier::SplitTier, null))->toBe(CanonicalPlatformFeeTier::Shared)
        ->and($resolver->resolve(ServiceFeeTier::CustomerCentric, null))->toBe(CanonicalPlatformFeeTier::CustomerCentric)
        ->and($resolver->resolve(ServiceFeeTier::BusinessCentric, null))->toBe(CanonicalPlatformFeeTier::BusinessCentric);
});

it('prefers the merchant tier over the configured default', function (): void {
    $resolver = new ResolveMerchantServiceFeeTier;
    expect($resolver->resolve(ServiceFeeTier::BusinessCentric, CanonicalPlatformFeeTier::CustomerCentric))
        ->toBe(CanonicalPlatformFeeTier::BusinessCentric);
});

it('falls back to the configured default when the merchant has no tier', function (): void {
    $resolver = new ResolveMerchantServiceFeeTier;
    expect($resolver->resolve(null, CanonicalPlatformFeeTier::Shared))->toBe(CanonicalPlatformFeeTier::Shared);
});

it('fails closed when neither a merchant tier nor a default is available', function (): void {
    (new ResolveMerchantServiceFeeTier)->resolve(null, null);
})->throws(PlatformFeeException::class);

// ---- state machines ------------------------------------------------------------

it('allows the documented configuration transitions and rejects the rest', function (): void {
    $sm = new PlatformFeeConfigurationStateMachine;
    expect($sm->canTransition(PlatformFeeConfigurationStatus::Draft, PlatformFeeConfigurationStatus::Active))->toBeTrue()
        ->and($sm->canTransition(PlatformFeeConfigurationStatus::Active, PlatformFeeConfigurationStatus::Superseded))->toBeTrue()
        ->and($sm->canTransition(PlatformFeeConfigurationStatus::Active, PlatformFeeConfigurationStatus::Cancelled))->toBeFalse()
        ->and($sm->canTransition(PlatformFeeConfigurationStatus::Superseded, PlatformFeeConfigurationStatus::Active))->toBeFalse();
});

it('throws invalid_state_transition on a forbidden configuration transition', function (): void {
    (new PlatformFeeConfigurationStateMachine)->ensure(
        PlatformFeeConfigurationStatus::Superseded,
        PlatformFeeConfigurationStatus::Active,
    );
})->throws(BillingStateException::class);

it('allows the documented dispute transitions and rejects the rest', function (): void {
    $sm = new PlatformFeeDisputeStateMachine;
    expect($sm->canTransition(PlatformFeeDisputeStatus::Open, PlatformFeeDisputeStatus::UnderReview))->toBeTrue()
        ->and($sm->canTransition(PlatformFeeDisputeStatus::UnderReview, PlatformFeeDisputeStatus::Resolved))->toBeTrue()
        ->and($sm->canTransition(PlatformFeeDisputeStatus::Open, PlatformFeeDisputeStatus::Resolved))->toBeFalse()
        ->and($sm->canTransition(PlatformFeeDisputeStatus::Resolved, PlatformFeeDisputeStatus::Open))->toBeFalse();
});
