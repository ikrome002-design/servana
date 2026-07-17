<?php

declare(strict_types=1);

use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Services\PersonnelCompensationPlanStateMachine;

uses()->group('compensation', 'phase20f', 'phase20f-state-machine');

/*
 | Phase 20F state-machine proof (Plan §59, §80; Scope §12.9). The authoritative arrow sets live on
 | the enums; these tests pin them against the accepted specs in
 | docs/architecture/state-machines/{personnel-compensation-plan,commission-rule}.md and prove every
 | unlisted pair is rejected — no silent no-op, ever.
 */

/** The complete accepted arrow set for a compensation plan. */
function acceptedPlanTransitions(): array
{
    return [
        ['draft', 'pending_approval'],
        ['draft', 'cancelled'],
        ['pending_approval', 'scheduled'],
        ['pending_approval', 'active'],
        ['pending_approval', 'rejected'],
        ['scheduled', 'active'],
        ['scheduled', 'cancelled'],
        ['active', 'superseded'],
        ['active', 'expired'],
    ];
}

/** The complete accepted arrow set for a commission rule. */
function acceptedRuleTransitions(): array
{
    return [
        ['draft', 'pending_approval'],
        ['draft', 'cancelled'],
        ['pending_approval', 'scheduled'],
        ['pending_approval', 'active'],
        ['pending_approval', 'rejected'],
        ['scheduled', 'active'],
        ['scheduled', 'cancelled'],
        ['active', 'superseded'],
        ['active', 'expired'],
    ];
}

// ---- compensation plan ---------------------------------------------------------

it('allows every accepted compensation-plan transition', function (): void {
    $machine = new PersonnelCompensationPlanStateMachine;

    foreach (acceptedPlanTransitions() as [$from, $to]) {
        $machine->ensure(CompensationPlanStatus::from($from), CompensationPlanStatus::from($to));
    }
})->throwsNoExceptions();

it('implements EXACTLY the accepted compensation-plan arrow set and nothing more', function (): void {
    // Any transition the enum allows but the accepted spec does not is an unauthorized widening.
    $implemented = [];

    foreach (CompensationPlanStatus::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            $implemented[] = [$from->value, $to->value];
        }
    }

    expect($implemented)->toEqualCanonicalizing(acceptedPlanTransitions());
});

it('rejects every unlisted compensation-plan transition', function (): void {
    $machine = new PersonnelCompensationPlanStateMachine;
    $accepted = acceptedPlanTransitions();
    $rejected = 0;

    foreach (CompensationPlanStatus::cases() as $from) {
        foreach (CompensationPlanStatus::cases() as $to) {
            if (in_array([$from->value, $to->value], $accepted, true)) {
                continue;
            }

            expect(fn () => $machine->ensure($from, $to))->toThrow(CompensationStateException::class);
            $rejected++;
        }
    }

    // 8 statuses × 8 = 64 pairs; 9 are legal, so 55 must be rejected (incl. every self-transition).
    expect($rejected)->toBe(55);
});

it('never allows an active plan to be cancelled (it is superseded instead)', function (): void {
    $machine = new PersonnelCompensationPlanStateMachine;

    expect($machine->canTransition(CompensationPlanStatus::Active, CompensationPlanStatus::Cancelled))->toBeFalse()
        ->and($machine->canTransition(CompensationPlanStatus::Active, CompensationPlanStatus::Superseded))->toBeTrue();
});

it('never allows a rejected plan to be re-submitted', function (): void {
    // A rejected plan is terminal — HR creates a new draft instead.
    $machine = new PersonnelCompensationPlanStateMachine;

    expect($machine->canTransition(CompensationPlanStatus::Rejected, CompensationPlanStatus::PendingApproval))->toBeFalse()
        ->and(CompensationPlanStatus::Rejected->allowedTransitions())->toBe([]);
});

it('treats every terminal compensation-plan status as a dead end', function (): void {
    foreach ([CompensationPlanStatus::Expired, CompensationPlanStatus::Superseded,
        CompensationPlanStatus::Rejected, CompensationPlanStatus::Cancelled] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue()
            ->and($terminal->allowedTransitions())->toBe([]);
    }
});

it('never allows a plan to skip approval on its way to active', function (): void {
    $machine = new PersonnelCompensationPlanStateMachine;

    expect($machine->canTransition(CompensationPlanStatus::Draft, CompensationPlanStatus::Active))->toBeFalse()
        ->and($machine->canTransition(CompensationPlanStatus::Draft, CompensationPlanStatus::Scheduled))->toBeFalse();
});

it('marks only draft as editable and only active as resolvable', function (): void {
    foreach (CompensationPlanStatus::cases() as $status) {
        expect($status->isEditable())->toBe($status === CompensationPlanStatus::Draft)
            ->and($status->isResolvable())->toBe($status === CompensationPlanStatus::Active);
    }
});

// ---- exception surface ---------------------------------------------------------

it('renders an invalid transition as the canonical 422 envelope', function (): void {
    $response = CompensationStateException::invalidTransition('compensation plan', 'active', 'draft')
        ->render(request());

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error']['code'])->toBe('invalid_state_transition');
});

it('leaks no SQLSTATE, constraint name, or internal id in a state-machine message', function (): void {
    $message = CompensationStateException::invalidTransition('compensation plan', 'active', 'draft')->getMessage();

    expect($message)->not->toContain('23P01')
        ->and($message)->not->toContain('_check')
        ->and($message)->not->toContain('personnel_compensation_plans')
        ->and($message)->toBe('A compensation plan cannot move from active to draft.');
});
