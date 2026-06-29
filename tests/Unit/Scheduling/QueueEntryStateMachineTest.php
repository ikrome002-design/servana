<?php

declare(strict_types=1);

use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Exceptions\QueueEntryStateException;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;

uses()->group('scheduling', 'queue', 'queue-state-machine');

/** The authoritative Phase-16B Queue Entry transition inventory (Plan §25.2). */
function legalQueueTransitions(): array
{
    return [
        ['waiting', 'assigned'],
        ['waiting', 'transferred'],
        ['waiting', 'cancelled'],
        ['waiting', 'no_show'],
        ['assigned', 'called'],
        ['assigned', 'transferred'],
        ['assigned', 'cancelled'],
        ['assigned', 'no_show'],
        ['called', 'in_service'],
        ['called', 'transferred'],
        ['called', 'cancelled'],
        ['called', 'no_show'],
        ['in_service', 'completed'],
        ['transferred', 'assigned'],
        ['transferred', 'waiting'],
    ];
}

it('allows exactly the legal transitions and rejects every other pair', function (): void {
    $legal = collect(legalQueueTransitions())->map(fn (array $p): string => $p[0].'->'.$p[1]);
    $machine = new QueueEntryStateMachine;

    foreach (QueueEntryStatus::cases() as $from) {
        foreach (QueueEntryStatus::cases() as $to) {
            $key = $from->value.'->'.$to->value;
            $shouldPass = $legal->contains($key);

            expect($machine->canTransition($from, $to))->toBe($shouldPass, "transition {$key}");
        }
    }
});

it('throws the 422 invalid_state_transition envelope for an illegal transition', function (): void {
    (new QueueEntryStateMachine)->ensure(QueueEntryStatus::Waiting, QueueEntryStatus::Completed);
})->throws(QueueEntryStateException::class);

it('treats completed, cancelled and no_show as terminal (no outgoing transitions)', function (): void {
    foreach ([QueueEntryStatus::Completed, QueueEntryStatus::Cancelled, QueueEntryStatus::NoShow] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue()
            ->and($terminal->allowedTransitions())->toBe([]);
    }
});

it('classifies the active and ordered-active state sets correctly', function (): void {
    expect(QueueEntryStatus::Waiting->isActive())->toBeTrue()
        ->and(QueueEntryStatus::Assigned->isActive())->toBeTrue()
        ->and(QueueEntryStatus::Called->isActive())->toBeTrue()
        ->and(QueueEntryStatus::InService->isActive())->toBeTrue()
        ->and(QueueEntryStatus::Transferred->isActive())->toBeTrue()
        ->and(QueueEntryStatus::Completed->isActive())->toBeFalse()
        ->and(QueueEntryStatus::Cancelled->isActive())->toBeFalse()
        ->and(QueueEntryStatus::NoShow->isActive())->toBeFalse();

    expect(QueueEntryStatus::Waiting->isOrderedActive())->toBeTrue()
        ->and(QueueEntryStatus::Assigned->isOrderedActive())->toBeTrue()
        ->and(QueueEntryStatus::Called->isOrderedActive())->toBeTrue()
        ->and(QueueEntryStatus::InService->isOrderedActive())->toBeFalse();
});

it('exposes exactly the eight authoritative states', function (): void {
    $values = array_map(fn (QueueEntryStatus $s): string => $s->value, QueueEntryStatus::cases());

    expect($values)->toBe(['waiting', 'assigned', 'called', 'in_service', 'completed', 'transferred', 'cancelled', 'no_show']);
});
