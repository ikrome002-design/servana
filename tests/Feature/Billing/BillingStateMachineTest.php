<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PreferredPersonnelFeeRuleStatus as FeeStatus;
use App\Domain\Billing\Enums\SubscriptionPlanStatus as PlanStatus;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Services\PreferredPersonnelFeeRuleStateMachine;
use App\Domain\Billing\Services\SubscriptionPlanStateMachine;

uses()->group('billing', 'billing-state-machine');

/*
 | Phase 20A billing state machines (Plan §13.9, §13.10). Every legal transition is allowed and
 | every unlisted pair is rejected with the canonical invalid_state_transition (422) envelope.
 | Status is never assigned directly — the named actions call ensure() before writing.
 */

// --- SubscriptionPlan (active → retired only) ---

it('allows the plan retire transition', function (): void {
    $machine = new SubscriptionPlanStateMachine;
    expect($machine->canTransition(PlanStatus::Active, PlanStatus::Retired))->toBeTrue();
    $machine->ensure(PlanStatus::Active, PlanStatus::Retired);
    // ensure() returns void on a legal transition (no exception thrown).
    expect(true)->toBeTrue();
});

it('rejects every non-retire plan transition', function (PlanStatus $from, PlanStatus $to): void {
    expect(fn () => (new SubscriptionPlanStateMachine)->ensure($from, $to))
        ->toThrow(BillingStateException::class);
})->with([
    'active→active' => [PlanStatus::Active, PlanStatus::Active],
    'retired→active' => [PlanStatus::Retired, PlanStatus::Active],
    'retired→retired' => [PlanStatus::Retired, PlanStatus::Retired],
]);

// --- PreferredPersonnelFeeRule ---

it('allows every listed fee-rule transition', function (FeeStatus $from, FeeStatus $to): void {
    expect((new PreferredPersonnelFeeRuleStateMachine)->canTransition($from, $to))->toBeTrue();
})->with([
    'draft→scheduled' => [FeeStatus::Draft, FeeStatus::Scheduled],
    'draft→active' => [FeeStatus::Draft, FeeStatus::Active],
    'draft→cancelled' => [FeeStatus::Draft, FeeStatus::Cancelled],
    'scheduled→active' => [FeeStatus::Scheduled, FeeStatus::Active],
    'scheduled→cancelled' => [FeeStatus::Scheduled, FeeStatus::Cancelled],
    'active→superseded' => [FeeStatus::Active, FeeStatus::Superseded],
    'active→expired' => [FeeStatus::Active, FeeStatus::Expired],
]);

it('rejects unlisted fee-rule transitions', function (FeeStatus $from, FeeStatus $to): void {
    expect(fn () => (new PreferredPersonnelFeeRuleStateMachine)->ensure($from, $to))
        ->toThrow(BillingStateException::class);
})->with([
    'draft→superseded' => [FeeStatus::Draft, FeeStatus::Superseded],
    'draft→expired' => [FeeStatus::Draft, FeeStatus::Expired],
    'scheduled→superseded' => [FeeStatus::Scheduled, FeeStatus::Superseded],
    'active→cancelled' => [FeeStatus::Active, FeeStatus::Cancelled],
    'active→draft' => [FeeStatus::Active, FeeStatus::Draft],
    'superseded→active' => [FeeStatus::Superseded, FeeStatus::Active],
    'expired→active' => [FeeStatus::Expired, FeeStatus::Active],
    'cancelled→active' => [FeeStatus::Cancelled, FeeStatus::Active],
]);

it('marks the terminal fee-rule states as terminal', function (): void {
    expect(FeeStatus::Superseded->isTerminal())->toBeTrue()
        ->and(FeeStatus::Expired->isTerminal())->toBeTrue()
        ->and(FeeStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(FeeStatus::Active->isTerminal())->toBeFalse()
        ->and(FeeStatus::Draft->isTerminal())->toBeFalse();
});

it('reserves the overlap range only for active and scheduled', function (): void {
    expect(FeeStatus::Active->reservesRange())->toBeTrue()
        ->and(FeeStatus::Scheduled->reservesRange())->toBeTrue()
        ->and(FeeStatus::Draft->reservesRange())->toBeFalse()
        ->and(FeeStatus::Superseded->reservesRange())->toBeFalse();
});

it('renders the invalid_state_transition envelope from the billing state exception', function (): void {
    $response = BillingStateException::invalidTransition('subscription plan', 'retired', 'active')
        ->render(request());

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error']['code'])->toBe('invalid_state_transition');
});
