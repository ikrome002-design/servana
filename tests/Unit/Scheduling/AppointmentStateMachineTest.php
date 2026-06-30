<?php

declare(strict_types=1);

use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Exceptions\AppointmentStateException;
use App\Domain\Scheduling\Services\AppointmentStateMachine;

uses()->group('scheduling', 'appointments', 'appointments-state-machine');

/** The authoritative Phase-16A transition inventory (Plan §25.2). */
function legalAppointmentTransitions(): array
{
    return [
        ['scheduled', 'confirmed'],
        ['scheduled', 'cancelled'],
        ['confirmed', 'checked_in'],
        ['confirmed', 'rescheduled'],
        ['confirmed', 'cancelled'],
        ['confirmed', 'no_show'],
        ['checked_in', 'cancelled_with_reason'],
        ['checked_in', 'queued'], // Phase 16B expand
        ['rescheduled', 'scheduled'],
        ['rescheduled', 'confirmed'],
    ];
}

it('allows exactly the legal transitions and rejects every other pair', function (): void {
    $legal = collect(legalAppointmentTransitions())->map(fn (array $p): string => $p[0].'->'.$p[1]);
    $machine = new AppointmentStateMachine;

    foreach (AppointmentStatus::cases() as $from) {
        foreach (AppointmentStatus::cases() as $to) {
            $key = $from->value.'->'.$to->value;
            $shouldPass = $legal->contains($key);

            expect($machine->canTransition($from, $to))->toBe($shouldPass, "transition {$key}");
        }
    }
});

it('throws the 422 invalid_state_transition envelope for an illegal transition', function (): void {
    (new AppointmentStateMachine)->ensure(AppointmentStatus::Scheduled, AppointmentStatus::CheckedIn);
})->throws(AppointmentStateException::class);

it('treats cancelled, cancelled_with_reason, no_show and queued as terminal (no outgoing transitions)', function (): void {
    foreach ([AppointmentStatus::Cancelled, AppointmentStatus::CancelledWithReason, AppointmentStatus::NoShow, AppointmentStatus::Queued] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue()
            ->and($terminal->allowedTransitions())->toBe([]);
    }
});

it('marks scheduled, confirmed and checked_in as the personnel-reserving states', function (): void {
    expect(AppointmentStatus::Scheduled->reservesTime())->toBeTrue()
        ->and(AppointmentStatus::Confirmed->reservesTime())->toBeTrue()
        ->and(AppointmentStatus::CheckedIn->reservesTime())->toBeTrue()
        ->and(AppointmentStatus::Rescheduled->reservesTime())->toBeFalse()
        ->and(AppointmentStatus::Cancelled->reservesTime())->toBeFalse()
        ->and(AppointmentStatus::CancelledWithReason->reservesTime())->toBeFalse()
        ->and(AppointmentStatus::NoShow->reservesTime())->toBeFalse();
});

it('defines queued (16B expand) but not in_service or completed (deferred to 16C)', function (): void {
    $values = array_map(fn (AppointmentStatus $s): string => $s->value, AppointmentStatus::cases());

    expect($values)->toContain('queued')
        ->and($values)->not->toContain('in_service')
        ->and($values)->not->toContain('completed');
});
