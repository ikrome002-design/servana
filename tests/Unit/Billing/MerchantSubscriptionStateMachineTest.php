<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus as S;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Services\MerchantSubscriptionStateMachine;

uses()->group('billing', 'phase20b-state-machine');

function machine(): MerchantSubscriptionStateMachine
{
    return new MerchantSubscriptionStateMachine;
}

it('allows every canonical transition', function (S $from, S $to): void {
    expect(machine()->canTransition($from, $to))->toBeTrue();
    machine()->ensure($from, $to); // no throw
})->with([
    [S::Trialing, S::Active],
    [S::Trialing, S::ReadOnlyGrace],
    [S::Trialing, S::Expired],
    [S::Trialing, S::Cancelled],
    [S::Active, S::Overdue],
    [S::Active, S::ReadOnlyGrace],
    [S::Active, S::SuspendedBilling],
    [S::Active, S::Cancelled],
    [S::Active, S::Expired],
    [S::ReadOnlyGrace, S::Active],
    [S::ReadOnlyGrace, S::SuspendedBilling],
    [S::Overdue, S::Active],
    [S::Overdue, S::SuspendedBilling],
    [S::SuspendedBilling, S::Active],
    [S::SuspendedBilling, S::Cancelled],
    [S::SuspendedBilling, S::Expired],
]);

it('rejects invalid transitions with 422 invalid_state_transition', function (S $from, S $to): void {
    expect(machine()->canTransition($from, $to))->toBeFalse();
    expect(fn () => machine()->ensure($from, $to))->toThrow(BillingStateException::class);
})->with([
    [S::Trialing, S::Overdue],
    [S::Trialing, S::SuspendedBilling],
    [S::Active, S::Trialing],
    [S::SuspendedBilling, S::Overdue],
    [S::SuspendedBilling, S::ReadOnlyGrace],
    [S::Cancelled, S::Active],
    [S::Expired, S::Active],
    [S::Cancelled, S::Expired],
]);

it('treats cancelled and expired as terminal (no outgoing transitions)', function (): void {
    expect(S::Cancelled->allowedTransitions())->toBe([])
        ->and(S::Expired->allowedTransitions())->toBe([])
        ->and(S::Cancelled->isTerminal())->toBeTrue()
        ->and(S::Expired->isTerminal())->toBeTrue();
});

it('projects each status to the correct billing status (Gate B2)', function (): void {
    expect(S::Trialing->projectedBillingStatus())->toBe(MerchantBillingStatus::Trialing)
        ->and(S::Active->projectedBillingStatus())->toBe(MerchantBillingStatus::Active)
        ->and(S::ReadOnlyGrace->projectedBillingStatus())->toBe(MerchantBillingStatus::ReadOnlyGrace)
        ->and(S::Overdue->projectedBillingStatus())->toBe(MerchantBillingStatus::Overdue)
        ->and(S::SuspendedBilling->projectedBillingStatus())->toBe(MerchantBillingStatus::SuspendedBilling)
        ->and(S::Cancelled->projectedBillingStatus())->toBe(MerchantBillingStatus::SuspendedBilling)
        ->and(S::Expired->projectedBillingStatus())->toBe(MerchantBillingStatus::SuspendedBilling);
});

it('excludes only terminal states from the one-current-subscription set', function (): void {
    expect(S::nonTerminalValues())->toBe(['trialing', 'active', 'read_only_grace', 'overdue', 'suspended_billing'])
        ->and(S::nonTerminalValues())->not->toContain('cancelled')
        ->and(S::nonTerminalValues())->not->toContain('expired');
});
