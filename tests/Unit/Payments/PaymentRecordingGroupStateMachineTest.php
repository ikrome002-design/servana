<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Exceptions\PaymentGroupStateException;
use App\Domain\Payments\Services\PaymentRecordingGroupStateMachine;

uses()->group('payments', 'state-machine');

it('allows the canonical group transitions (Phase 18A + 18B)', function (): void {
    $machine = new PaymentRecordingGroupStateMachine;

    // 18A recording transitions.
    $machine->ensure(PaymentRecordingGroupStatus::Draft, PaymentRecordingGroupStatus::Recorded);
    $machine->ensure(PaymentRecordingGroupStatus::Recorded, PaymentRecordingGroupStatus::PendingValidation);
    // 18B decisions.
    $machine->ensure(PaymentRecordingGroupStatus::PendingValidation, PaymentRecordingGroupStatus::Validated);
    $machine->ensure(PaymentRecordingGroupStatus::PendingValidation, PaymentRecordingGroupStatus::Rejected);
    $machine->ensure(PaymentRecordingGroupStatus::PendingValidation, PaymentRecordingGroupStatus::CorrectionRequired);
    // 18B: a corrected group is resubmitted for validation.
    $machine->ensure(PaymentRecordingGroupStatus::CorrectionRequired, PaymentRecordingGroupStatus::PendingValidation);
    // 18B: a validated group can only move to reversed.
    $machine->ensure(PaymentRecordingGroupStatus::Validated, PaymentRecordingGroupStatus::Reversed);

    expect(true)->toBeTrue();
});

it('rejects invalid group transitions with invalid_state_transition', function (): void {
    $machine = new PaymentRecordingGroupStateMachine;

    expect(fn () => $machine->ensure(PaymentRecordingGroupStatus::Validated, PaymentRecordingGroupStatus::Validated))
        ->toThrow(PaymentGroupStateException::class);
    expect(fn () => $machine->ensure(PaymentRecordingGroupStatus::Rejected, PaymentRecordingGroupStatus::PendingValidation))
        ->toThrow(PaymentGroupStateException::class);
    expect(fn () => $machine->ensure(PaymentRecordingGroupStatus::Reversed, PaymentRecordingGroupStatus::Validated))
        ->toThrow(PaymentGroupStateException::class);
    expect(fn () => $machine->ensure(PaymentRecordingGroupStatus::PendingValidation, PaymentRecordingGroupStatus::Reversed))
        ->toThrow(PaymentGroupStateException::class);
});

it('keeps the strict Phase 18A guard refusing every 18B transition', function (): void {
    $machine = new PaymentRecordingGroupStateMachine;

    // ensurePhase18a permits only the recording transitions.
    $machine->ensurePhase18a(PaymentRecordingGroupStatus::Recorded, PaymentRecordingGroupStatus::PendingValidation);

    expect(fn () => $machine->ensurePhase18a(PaymentRecordingGroupStatus::PendingValidation, PaymentRecordingGroupStatus::Validated))
        ->toThrow(PaymentGroupStateException::class);
});

it('defines component transitions coherent with the group', function (): void {
    // Validation / rejection / correction from pending.
    expect(PaymentRecordStatus::PendingValidation->canTransitionTo(PaymentRecordStatus::Validated))->toBeTrue()
        ->and(PaymentRecordStatus::PendingValidation->canTransitionTo(PaymentRecordStatus::Rejected))->toBeTrue()
        ->and(PaymentRecordStatus::PendingValidation->canTransitionTo(PaymentRecordStatus::CorrectionRequired))->toBeTrue()
        // Corrected component resubmits.
        ->and(PaymentRecordStatus::CorrectionRequired->canTransitionTo(PaymentRecordStatus::PendingValidation))->toBeTrue()
        // Validated → adjusted (partial refund) or reversed (full refund) only.
        ->and(PaymentRecordStatus::Validated->canTransitionTo(PaymentRecordStatus::Adjusted))->toBeTrue()
        ->and(PaymentRecordStatus::Validated->canTransitionTo(PaymentRecordStatus::Reversed))->toBeTrue()
        // A rejected/reversed component is terminal.
        ->and(PaymentRecordStatus::Rejected->allowedTransitions())->toBe([])
        ->and(PaymentRecordStatus::Reversed->allowedTransitions())->toBe([])
        // A component may not jump straight from pending to reversed.
        ->and(PaymentRecordStatus::PendingValidation->canTransitionTo(PaymentRecordStatus::Reversed))->toBeFalse();
});
