<?php

declare(strict_types=1);

use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Services\CommissionRuleStateMachine;

uses()->group('compensation', 'phase20f', 'phase20f-state-machine');

/*
 | Phase 20F commission-rule state-machine proof (Plan §59, §80; Scope §12.7 Step 3A/3C, §18.3).
 | The authoritative arrow set lives on CommissionRuleStatus; this pins it against the accepted spec
 | in docs/architecture/state-machines/commission-rule.md. A rule is ENDED (active → superseded),
 | never deleted, and never transitions independently of its referencing plan.
 */

/** The complete accepted arrow set for a commission rule. */
function acceptedCommissionRuleTransitions(): array
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

it('allows every accepted commission-rule transition', function (): void {
    $machine = new CommissionRuleStateMachine;

    foreach (acceptedCommissionRuleTransitions() as [$from, $to]) {
        $machine->ensure(CommissionRuleStatus::from($from), CommissionRuleStatus::from($to));
    }
})->throwsNoExceptions();

it('implements EXACTLY the accepted commission-rule arrow set and nothing more', function (): void {
    // Any transition the enum allows but the accepted spec does not is an unauthorized widening.
    $implemented = [];

    foreach (CommissionRuleStatus::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            $implemented[] = [$from->value, $to->value];
        }
    }

    expect($implemented)->toEqualCanonicalizing(acceptedCommissionRuleTransitions());
});

it('rejects every unlisted commission-rule transition', function (): void {
    $machine = new CommissionRuleStateMachine;
    $accepted = acceptedCommissionRuleTransitions();
    $rejected = 0;

    foreach (CommissionRuleStatus::cases() as $from) {
        foreach (CommissionRuleStatus::cases() as $to) {
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

it('never allows an active commission rule to be cancelled (it is ended instead)', function (): void {
    // Scope §12.7 Step 3C: a previously active rule is ENDED, not deleted — and never cancelled.
    $machine = new CommissionRuleStateMachine;

    expect($machine->canTransition(CommissionRuleStatus::Active, CommissionRuleStatus::Cancelled))->toBeFalse()
        ->and($machine->canTransition(CommissionRuleStatus::Active, CommissionRuleStatus::Superseded))->toBeTrue();
});

it('never allows a commission rule to skip approval on its way to active', function (): void {
    $machine = new CommissionRuleStateMachine;

    expect($machine->canTransition(CommissionRuleStatus::Draft, CommissionRuleStatus::Active))->toBeFalse()
        ->and($machine->canTransition(CommissionRuleStatus::Draft, CommissionRuleStatus::Scheduled))->toBeFalse();
});

it('treats every terminal commission-rule status as a dead end', function (): void {
    foreach ([CommissionRuleStatus::Superseded, CommissionRuleStatus::Expired,
        CommissionRuleStatus::Rejected, CommissionRuleStatus::Cancelled] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue()
            ->and($terminal->allowedTransitions())->toBe([]);
    }
});

it('marks only draft as editable and only active as resolvable', function (): void {
    foreach (CommissionRuleStatus::cases() as $status) {
        expect($status->isEditable())->toBe($status === CommissionRuleStatus::Draft)
            ->and($status->isResolvable())->toBe($status === CommissionRuleStatus::Active);
    }
});

it('renders an invalid commission-rule transition as the canonical 422 envelope', function (): void {
    $response = CompensationStateException::invalidTransition('commission rule', 'active', 'draft')
        ->render(request());

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error']['code'])->toBe('invalid_state_transition');
});
