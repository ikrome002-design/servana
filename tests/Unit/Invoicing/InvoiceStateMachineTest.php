<?php

declare(strict_types=1);

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoiceStateException;
use App\Domain\Invoicing\Services\InvoiceStateMachine;

uses()->group('invoicing', 'invoice-state');

dataset('valid invoice transitions', [
    'draft → issued' => [InvoiceStatus::Draft, InvoiceStatus::Issued],
    'issued → partially_paid' => [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid],
    'issued → paid' => [InvoiceStatus::Issued, InvoiceStatus::Paid],
    'issued → void_pending' => [InvoiceStatus::Issued, InvoiceStatus::VoidPending],
    'issued → adjusted' => [InvoiceStatus::Issued, InvoiceStatus::Adjusted],
    'partially_paid → paid' => [InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid],
    'partially_paid → void_pending' => [InvoiceStatus::PartiallyPaid, InvoiceStatus::VoidPending],
    'partially_paid → adjusted' => [InvoiceStatus::PartiallyPaid, InvoiceStatus::Adjusted],
    'void_pending → voided' => [InvoiceStatus::VoidPending, InvoiceStatus::Voided],
    'void_pending → issued (reject)' => [InvoiceStatus::VoidPending, InvoiceStatus::Issued],
    'void_pending → partially_paid (reject)' => [InvoiceStatus::VoidPending, InvoiceStatus::PartiallyPaid],
    'paid → refund_pending' => [InvoiceStatus::Paid, InvoiceStatus::RefundPending],
    'paid → adjustment_required' => [InvoiceStatus::Paid, InvoiceStatus::AdjustmentRequired],
]);

dataset('invalid invoice transitions', [
    'draft → paid' => [InvoiceStatus::Draft, InvoiceStatus::Paid],
    'draft → voided' => [InvoiceStatus::Draft, InvoiceStatus::Voided],
    'draft → draft' => [InvoiceStatus::Draft, InvoiceStatus::Draft],
    'draft → void_pending' => [InvoiceStatus::Draft, InvoiceStatus::VoidPending],
    'issued → draft' => [InvoiceStatus::Issued, InvoiceStatus::Draft],
    'issued → issued' => [InvoiceStatus::Issued, InvoiceStatus::Issued],
    'issued → voided' => [InvoiceStatus::Issued, InvoiceStatus::Voided],
    'issued → refund_pending' => [InvoiceStatus::Issued, InvoiceStatus::RefundPending],
    'partially_paid → draft' => [InvoiceStatus::PartiallyPaid, InvoiceStatus::Draft],
    'partially_paid → issued' => [InvoiceStatus::PartiallyPaid, InvoiceStatus::Issued],
    'void_pending → adjusted' => [InvoiceStatus::VoidPending, InvoiceStatus::Adjusted],
    'void_pending → void_pending' => [InvoiceStatus::VoidPending, InvoiceStatus::VoidPending],
    'voided → issued' => [InvoiceStatus::Voided, InvoiceStatus::Issued],
    'voided → draft' => [InvoiceStatus::Voided, InvoiceStatus::Draft],
    'adjusted → issued' => [InvoiceStatus::Adjusted, InvoiceStatus::Issued],
    'paid → paid' => [InvoiceStatus::Paid, InvoiceStatus::Paid],
    'paid → voided' => [InvoiceStatus::Paid, InvoiceStatus::Voided],
    'refund_pending → paid' => [InvoiceStatus::RefundPending, InvoiceStatus::Paid],
    'adjustment_required → paid' => [InvoiceStatus::AdjustmentRequired, InvoiceStatus::Paid],
]);

it('allows every legal invoice transition', function (InvoiceStatus $from, InvoiceStatus $to): void {
    $machine = new InvoiceStateMachine;

    expect($machine->canTransition($from, $to))->toBeTrue();
    $machine->ensure($from, $to); // does not throw
})->with('valid invoice transitions');

it('rejects every illegal invoice transition with invalid_state_transition', function (InvoiceStatus $from, InvoiceStatus $to): void {
    $machine = new InvoiceStateMachine;

    expect($machine->canTransition($from, $to))->toBeFalse();
    expect(fn () => $machine->ensure($from, $to))->toThrow(InvoiceStateException::class);
})->with('invalid invoice transitions');

it('treats voided/adjusted/refund_pending/adjustment_required as terminal', function (): void {
    expect(InvoiceStatus::Voided->isTerminal())->toBeTrue()
        ->and(InvoiceStatus::Adjusted->isTerminal())->toBeTrue()
        ->and(InvoiceStatus::RefundPending->isTerminal())->toBeTrue()
        ->and(InvoiceStatus::AdjustmentRequired->isTerminal())->toBeTrue()
        ->and(InvoiceStatus::Voided->allowedTransitions())->toBe([])
        ->and(InvoiceStatus::Adjusted->allowedTransitions())->toBe([]);
});

it('marks every non-draft status finalized (snapshots frozen)', function (): void {
    expect(InvoiceStatus::Draft->isFinalized())->toBeFalse()
        ->and(InvoiceStatus::Issued->isFinalized())->toBeTrue()
        ->and(InvoiceStatus::Voided->isFinalized())->toBeTrue();
});

it('exposes issued + partially_paid as the payable (voidable / restore) set', function (): void {
    expect(InvoiceStatus::payableStatuses())
        ->toBe([InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid]);
});

it('mirrors the DB CHECK: exactly nine canonical states', function (): void {
    $values = array_map(fn (InvoiceStatus $s): string => $s->value, InvoiceStatus::cases());

    expect($values)->toBe([
        'draft', 'issued', 'partially_paid', 'paid', 'void_pending',
        'voided', 'adjusted', 'refund_pending', 'adjustment_required',
    ]);
});
