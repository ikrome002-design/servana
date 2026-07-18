<?php

declare(strict_types=1);

use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Services\CommissionLedgerStateMachine;
use App\Domain\Compensation\Services\SalaryLedgerStateMachine;

uses()->group('compensation', 'phase20g', 'phase20g-state-machine');

/*
 | Phase 20G ledger state machines (Plan §60/§61; §11). Status-only transitions; terminal states are
 | terminal; an unlisted transition raises CompensationStateException (422 invalid_state_transition).
 */

it('permits the commission-ledger earned lifecycle and blocks terminal transitions', function (): void {
    $machine = new CommissionLedgerStateMachine;

    $machine->ensure(CommissionLedgerStatus::Earned, CommissionLedgerStatus::Reversed);
    $machine->ensure(CommissionLedgerStatus::Earned, CommissionLedgerStatus::IncludedInPayout); // Phase 20H, defined for parity
    $machine->ensure(CommissionLedgerStatus::IncludedInPayout, CommissionLedgerStatus::Paid);

    expect(fn () => $machine->ensure(CommissionLedgerStatus::Paid, CommissionLedgerStatus::Reversed))
        ->toThrow(CompensationStateException::class);
    expect(fn () => $machine->ensure(CommissionLedgerStatus::Reversed, CommissionLedgerStatus::Earned))
        ->toThrow(CompensationStateException::class);
    expect($machine->canTransition(CommissionLedgerStatus::Earned, CommissionLedgerStatus::Paid))->toBeFalse();
});

it('permits the salary-ledger accrual lifecycle and blocks terminal transitions', function (): void {
    $machine = new SalaryLedgerStateMachine;

    $machine->ensure(SalaryLedgerStatus::Pending, SalaryLedgerStatus::Reversed);
    $machine->ensure(SalaryLedgerStatus::Pending, SalaryLedgerStatus::IncludedInPayout);
    $machine->ensure(SalaryLedgerStatus::IncludedInPayout, SalaryLedgerStatus::Paid);

    expect(fn () => $machine->ensure(SalaryLedgerStatus::Paid, SalaryLedgerStatus::Reversed))
        ->toThrow(CompensationStateException::class);
    expect(fn () => $machine->ensure(SalaryLedgerStatus::Reversed, SalaryLedgerStatus::Pending))
        ->toThrow(CompensationStateException::class);
});
