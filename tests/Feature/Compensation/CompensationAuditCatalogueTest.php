<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditDomain;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSeverity;

uses()->group('compensation', 'phase20f', 'phase20f-audit');

/*
 | Phase 20F audit-catalogue proof (Plan §59, §19.2). Every compensation event is typed, lands in
 | the Compensation read segment (audit.compensation.view), and carries the severity the Plan
 | requires — backdated approval is CRITICAL. No free-form action strings.
 */

/** The Phase 20F compensation-plan event family. */
function phase20fPlanEvents(): array
{
    return [
        AuditEvent::CompensationPlanCreated,
        AuditEvent::CompensationPlanUpdatedDraft,
        AuditEvent::CompensationPlanSubmitted,
        AuditEvent::CompensationPlanApproved,
        AuditEvent::CompensationPlanBackdatedChangeApproved,
        AuditEvent::CompensationPlanActivated,
        AuditEvent::CompensationPlanRejected,
        AuditEvent::CompensationPlanCancelled,
        AuditEvent::CompensationPlanSuperseded,
        AuditEvent::CompensationPlanExpired,
    ];
}

/** The Phase 20F commission-rule event family. */
function phase20fRuleEvents(): array
{
    return [
        AuditEvent::CommissionRuleCreated,
        AuditEvent::CommissionRuleUpdatedDraft,
        AuditEvent::CommissionRuleSubmitted,
        AuditEvent::CommissionRuleApproved,
        AuditEvent::CommissionRuleActivated,
        AuditEvent::CommissionRuleRejected,
        AuditEvent::CommissionRuleCancelled,
        AuditEvent::CommissionRuleEnded,
        AuditEvent::CommissionRuleExpired,
    ];
}

it('classifies every Phase 20F event into the Compensation read segment', function (): void {
    // Phase 19 left AuditDomain::Compensation deliberately unpopulated until its owning phase;
    // Phase 20F populates it, so audit.compensation.view stops returning an empty result.
    foreach ([...phase20fPlanEvents(), ...phase20fRuleEvents()] as $event) {
        expect($event->domain())->toBe(AuditDomain::Compensation);
    }

    expect(AuditEvent::actionsIn(AuditDomain::Compensation))->not->toBeEmpty();
});

it('makes a backdated approval CRITICAL severity (Plan §59)', function (): void {
    expect(AuditEvent::CompensationPlanBackdatedChangeApproved->severity())->toBe(AuditSeverity::Critical);
});

it('makes an ordinary approval and a supersede HIGH severity', function (): void {
    expect(AuditEvent::CompensationPlanApproved->severity())->toBe(AuditSeverity::High)
        ->and(AuditEvent::CompensationPlanSuperseded->severity())->toBe(AuditSeverity::High)
        ->and(AuditEvent::CommissionRuleApproved->severity())->toBe(AuditSeverity::High)
        ->and(AuditEvent::CommissionRuleEnded->severity())->toBe(AuditSeverity::High);
});

it('makes the effective-date boundaries INFO severity', function (): void {
    // Activation and expiry decide nothing — they only recognize a configured date arriving.
    expect(AuditEvent::CompensationPlanActivated->severity())->toBe(AuditSeverity::Info)
        ->and(AuditEvent::CompensationPlanExpired->severity())->toBe(AuditSeverity::Info)
        ->and(AuditEvent::CompensationPlanUpdatedDraft->severity())->toBe(AuditSeverity::Info);
});

it('makes creation, submission, rejection and cancellation WARNING severity', function (): void {
    foreach ([AuditEvent::CompensationPlanCreated, AuditEvent::CompensationPlanSubmitted,
        AuditEvent::CompensationPlanRejected, AuditEvent::CompensationPlanCancelled] as $event) {
        expect($event->severity())->toBe(AuditSeverity::Warning);
    }
});

it('names every Phase 20F event under its canonical action prefix', function (): void {
    foreach (phase20fPlanEvents() as $event) {
        expect($event->value)->toStartWith('compensation.plan.');
    }

    foreach (phase20fRuleEvents() as $event) {
        expect($event->value)->toStartWith('commission_rule.');
    }
});

it('gives the activation boundary its own audit action', function (): void {
    // Symmetric with expiry, and distinct from the approval decision that scheduled it.
    expect(AuditEvent::CompensationPlanActivated->value)->toBe('compensation.plan.activated')
        ->and(AuditEvent::CompensationPlanExpired->value)->toBe('compensation.plan.expired')
        ->and(AuditEvent::CompensationPlanActivated)->not->toBe(AuditEvent::CompensationPlanApproved);
});

it('declares no earned, payout, or settlement audit event in Phase 20F', function (): void {
    // Those belong to Phases 20G/20H — a configuration phase never audits money movement.
    $compensationActions = AuditEvent::actionsIn(AuditDomain::Compensation);

    foreach (['earned', 'payout', 'settled', 'accrued', 'disbursed', 'paid'] as $forbidden) {
        foreach ($compensationActions as $action) {
            expect($action)->not->toContain($forbidden);
        }
    }
});
