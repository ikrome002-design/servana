<?php

declare(strict_types=1);

use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Exceptions\ServiceSessionStateException;
use App\Domain\Scheduling\Services\ServiceSessionStateMachine;

uses()->group('scheduling', 'service-session', 'service-session-state');

dataset('valid transitions', [
    'pending → in_progress' => [ServiceSessionStatus::Pending, ServiceSessionStatus::InProgress],
    'pending → cancelled' => [ServiceSessionStatus::Pending, ServiceSessionStatus::Cancelled],
    'in_progress → completed' => [ServiceSessionStatus::InProgress, ServiceSessionStatus::Completed],
    'in_progress → cancelled' => [ServiceSessionStatus::InProgress, ServiceSessionStatus::Cancelled],
]);

dataset('invalid transitions', [
    'pending → completed' => [ServiceSessionStatus::Pending, ServiceSessionStatus::Completed],
    'pending → pending' => [ServiceSessionStatus::Pending, ServiceSessionStatus::Pending],
    'in_progress → in_progress' => [ServiceSessionStatus::InProgress, ServiceSessionStatus::InProgress],
    'in_progress → pending' => [ServiceSessionStatus::InProgress, ServiceSessionStatus::Pending],
    'completed → in_progress' => [ServiceSessionStatus::Completed, ServiceSessionStatus::InProgress],
    'completed → cancelled' => [ServiceSessionStatus::Completed, ServiceSessionStatus::Cancelled],
    'completed → completed' => [ServiceSessionStatus::Completed, ServiceSessionStatus::Completed],
    'cancelled → in_progress' => [ServiceSessionStatus::Cancelled, ServiceSessionStatus::InProgress],
    'cancelled → completed' => [ServiceSessionStatus::Cancelled, ServiceSessionStatus::Completed],
    'cancelled → cancelled' => [ServiceSessionStatus::Cancelled, ServiceSessionStatus::Cancelled],
]);

it('allows every legal transition', function (ServiceSessionStatus $from, ServiceSessionStatus $to): void {
    $machine = new ServiceSessionStateMachine;

    expect($machine->canTransition($from, $to))->toBeTrue();
    $machine->ensure($from, $to); // does not throw
})->with('valid transitions');

it('rejects every illegal transition with a 422 invalid_state_transition', function (ServiceSessionStatus $from, ServiceSessionStatus $to): void {
    $machine = new ServiceSessionStateMachine;

    expect($machine->canTransition($from, $to))->toBeFalse();
    expect(fn () => $machine->ensure($from, $to))->toThrow(ServiceSessionStateException::class);
})->with('invalid transitions');

it('treats completed and cancelled as terminal (no reopen)', function (): void {
    expect(ServiceSessionStatus::Completed->isTerminal())->toBeTrue()
        ->and(ServiceSessionStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(ServiceSessionStatus::Completed->allowedTransitions())->toBe([])
        ->and(ServiceSessionStatus::Cancelled->allowedTransitions())->toBe([]);
});

it('treats pending and in_progress as the active (work-occupying) set', function (): void {
    expect(ServiceSessionStatus::activeStatuses())
        ->toBe([ServiceSessionStatus::Pending, ServiceSessionStatus::InProgress])
        ->and(ServiceSessionStatus::Pending->isActive())->toBeTrue()
        ->and(ServiceSessionStatus::InProgress->isActive())->toBeTrue()
        ->and(ServiceSessionStatus::Completed->isActive())->toBeFalse();
});

it('renders the canonical 422 envelope for an invalid transition', function (): void {
    $exception = ServiceSessionStateException::invalidTransition(
        ServiceSessionStatus::Completed,
        ServiceSessionStatus::InProgress,
    );

    $response = $exception->render(request());
    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error']['code'])->toBe('invalid_state_transition');
});
