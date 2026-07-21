<?php

declare(strict_types=1);

use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Services\EarningsQueryStateMachine;
use App\Domain\Compensation\Services\PayoutRunStateMachine;

uses()->group('compensation', 'phase20h', 'phase20h-state-machine');

/*
 | Phase 20H state machines (Plan §62/§63; §25.4/§25.5). Status-only transitions; terminal states are
 | terminal; an unlisted transition raises CompensationStateException (422 invalid_state_transition).
 */

it('permits the ordinary payout-run lifecycle', function (): void {
    $machine = new PayoutRunStateMachine;

    $machine->ensure(PayoutRunStatus::Draft, PayoutRunStatus::Submitted);
    $machine->ensure(PayoutRunStatus::Submitted, PayoutRunStatus::FinanceVerified);
    $machine->ensure(PayoutRunStatus::FinanceVerified, PayoutRunStatus::Approved);
    $machine->ensure(PayoutRunStatus::Approved, PayoutRunStatus::Paid);

    expect($machine->canTransition(PayoutRunStatus::Approved, PayoutRunStatus::Paid))->toBeTrue();
    expect(PayoutRunStatus::Paid->isTerminal())->toBeTrue();
});

it('permits the high-value payout-run fork through Merchant-Admin approval', function (): void {
    $machine = new PayoutRunStateMachine;

    $machine->ensure(PayoutRunStatus::FinanceVerified, PayoutRunStatus::PendingMerchantAdminApproval);
    $machine->ensure(PayoutRunStatus::PendingMerchantAdminApproval, PayoutRunStatus::Approved);
    $machine->ensure(PayoutRunStatus::PendingMerchantAdminApproval, PayoutRunStatus::Rejected);

    expect($machine->canTransition(PayoutRunStatus::FinanceVerified, PayoutRunStatus::PendingMerchantAdminApproval))->toBeTrue();
});

it('permits draft cancel and pre-approval reject', function (): void {
    $machine = new PayoutRunStateMachine;

    $machine->ensure(PayoutRunStatus::Draft, PayoutRunStatus::Cancelled);
    $machine->ensure(PayoutRunStatus::Submitted, PayoutRunStatus::Rejected);
    $machine->ensure(PayoutRunStatus::FinanceVerified, PayoutRunStatus::Rejected);

    expect($machine->canTransition(PayoutRunStatus::Draft, PayoutRunStatus::Cancelled))->toBeTrue();
    expect(PayoutRunStatus::Cancelled->isTerminal())->toBeTrue();
});

it('blocks invalid payout-run transitions and status rewind', function (): void {
    $machine = new PayoutRunStateMachine;

    // No status rewind after paid; no skipping verification; no un-cancel.
    expect(fn () => $machine->ensure(PayoutRunStatus::Paid, PayoutRunStatus::Approved))
        ->toThrow(CompensationStateException::class);
    expect(fn () => $machine->ensure(PayoutRunStatus::Draft, PayoutRunStatus::Approved))
        ->toThrow(CompensationStateException::class);
    expect(fn () => $machine->ensure(PayoutRunStatus::Submitted, PayoutRunStatus::Paid))
        ->toThrow(CompensationStateException::class);
    expect(fn () => $machine->ensure(PayoutRunStatus::Cancelled, PayoutRunStatus::Draft))
        ->toThrow(CompensationStateException::class);
    expect($machine->canTransition(PayoutRunStatus::Approved, PayoutRunStatus::Rejected))->toBeFalse();
});

it('permits the earnings-query lifecycle and blocks terminal transitions', function (): void {
    $machine = new EarningsQueryStateMachine;

    $machine->ensure(EarningsQueryStatus::Open, EarningsQueryStatus::Assigned);
    $machine->ensure(EarningsQueryStatus::Open, EarningsQueryStatus::Resolved);
    $machine->ensure(EarningsQueryStatus::Assigned, EarningsQueryStatus::Resolved);
    $machine->ensure(EarningsQueryStatus::Assigned, EarningsQueryStatus::Rejected);

    expect(fn () => $machine->ensure(EarningsQueryStatus::Resolved, EarningsQueryStatus::Assigned))
        ->toThrow(CompensationStateException::class);
    expect(fn () => $machine->ensure(EarningsQueryStatus::Rejected, EarningsQueryStatus::Open))
        ->toThrow(CompensationStateException::class);
});
