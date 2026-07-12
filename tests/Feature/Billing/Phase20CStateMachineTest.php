<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Services\FreePeriodOfferStateMachine;
use App\Domain\Billing\Services\PromotionalDiscountStateMachine;

uses()->group('billing', 'phase20c', 'phase20c-state-machine');

/*
 | Phase 20C promotion + free-period state machines (Plan §53). Pure guards — no DB. Every valid pair
 | passes ensure(); every other pair raises BillingStateException (→ 422 invalid_state_transition).
 | Promotions allow draft→active; free-period offers do NOT (approval yields scheduled).
 */

/** @return list<array{PromotionStatus,PromotionStatus}> */
function promotionValidPairs(): array
{
    $pairs = [];
    foreach (PromotionStatus::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            $pairs[] = [$from, $to];
        }
    }

    return $pairs;
}

/** @return list<array{FreePeriodOfferStatus,FreePeriodOfferStatus}> */
function offerValidPairs(): array
{
    $pairs = [];
    foreach (FreePeriodOfferStatus::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            $pairs[] = [$from, $to];
        }
    }

    return $pairs;
}

it('promotion allows exactly the documented transition set', function (): void {
    $machine = new PromotionalDiscountStateMachine;
    foreach (promotionValidPairs() as [$from, $to]) {
        $machine->ensure($from, $to);
        expect($machine->canTransition($from, $to))->toBeTrue();
    }

    // Spot-check the documented inventory including draft→active.
    expect(PromotionStatus::Draft->canTransitionTo(PromotionStatus::Active))->toBeTrue()
        ->and(PromotionStatus::Draft->canTransitionTo(PromotionStatus::Scheduled))->toBeTrue()
        ->and(PromotionStatus::Active->canTransitionTo(PromotionStatus::Paused))->toBeTrue()
        ->and(PromotionStatus::Paused->canTransitionTo(PromotionStatus::Active))->toBeTrue();
});

it('promotion rejects every non-allowed transition with 422', function (): void {
    $machine = new PromotionalDiscountStateMachine;
    $valid = promotionValidPairs();

    foreach (PromotionStatus::cases() as $from) {
        foreach (PromotionStatus::cases() as $to) {
            $isValid = collect($valid)->contains(fn (array $p): bool => $p[0] === $from && $p[1] === $to);
            if ($isValid) {
                continue;
            }
            expect(fn () => $machine->ensure($from, $to))->toThrow(BillingStateException::class);
        }
    }
});

it('free-period offer has NO draft→active transition', function (): void {
    expect(FreePeriodOfferStatus::Draft->canTransitionTo(FreePeriodOfferStatus::Active))->toBeFalse()
        ->and(FreePeriodOfferStatus::Draft->canTransitionTo(FreePeriodOfferStatus::Scheduled))->toBeTrue()
        ->and(FreePeriodOfferStatus::Scheduled->canTransitionTo(FreePeriodOfferStatus::Active))->toBeTrue();

    $machine = new FreePeriodOfferStateMachine;
    expect(fn () => $machine->ensure(FreePeriodOfferStatus::Draft, FreePeriodOfferStatus::Active))
        ->toThrow(BillingStateException::class);
});

it('free-period offer allows exactly the documented transition set', function (): void {
    $machine = new FreePeriodOfferStateMachine;
    foreach (offerValidPairs() as [$from, $to]) {
        $machine->ensure($from, $to);
        expect($machine->canTransition($from, $to))->toBeTrue();
    }
    expect(offerValidPairs())->not->toBeEmpty();
});

it('free-period offer rejects every non-allowed transition with 422', function (): void {
    $machine = new FreePeriodOfferStateMachine;
    $valid = offerValidPairs();

    foreach (FreePeriodOfferStatus::cases() as $from) {
        foreach (FreePeriodOfferStatus::cases() as $to) {
            $isValid = collect($valid)->contains(fn (array $p): bool => $p[0] === $from && $p[1] === $to);
            if ($isValid) {
                continue;
            }
            expect(fn () => $machine->ensure($from, $to))->toThrow(BillingStateException::class);
        }
    }
});

it('treats expired and cancelled as terminal for both machines', function (): void {
    expect(PromotionStatus::Expired->allowedTransitions())->toBe([])
        ->and(PromotionStatus::Cancelled->allowedTransitions())->toBe([])
        ->and(PromotionStatus::Expired->isTerminal())->toBeTrue()
        ->and(PromotionStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(FreePeriodOfferStatus::Expired->allowedTransitions())->toBe([])
        ->and(FreePeriodOfferStatus::Cancelled->allowedTransitions())->toBe([]);
});

it('marks only active as resolvable', function (): void {
    foreach (PromotionStatus::cases() as $status) {
        expect($status->isResolvable())->toBe($status === PromotionStatus::Active);
    }
    foreach (FreePeriodOfferStatus::cases() as $status) {
        expect($status->isResolvable())->toBe($status === FreePeriodOfferStatus::Active);
    }
});
