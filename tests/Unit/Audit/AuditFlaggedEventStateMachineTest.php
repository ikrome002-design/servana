<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditFlaggedEventStatus as S;

/*
 | Phase 19 — the flagged-event review lifecycle (Plan §25). Pure enum coverage of the
 | allowed transition graph; the DB CHECK + actions enforce the same graph at runtime.
 */

it('allows exactly the Plan lifecycle transitions', function (): void {
    expect(S::Open->allowedTransitions())->toBe([S::UnderReview])
        ->and(S::Reopened->allowedTransitions())->toBe([S::UnderReview])
        ->and(S::UnderReview->allowedTransitions())->toBe([S::Resolved, S::Dismissed])
        ->and(S::Resolved->allowedTransitions())->toBe([S::Reopened])
        ->and(S::Dismissed->allowedTransitions())->toBe([S::Reopened]);
});

it('rejects skips and self-loops', function (): void {
    expect(S::Open->canTransitionTo(S::Resolved))->toBeFalse()
        ->and(S::Open->canTransitionTo(S::Dismissed))->toBeFalse()
        ->and(S::Open->canTransitionTo(S::Open))->toBeFalse()
        ->and(S::UnderReview->canTransitionTo(S::Reopened))->toBeFalse()
        ->and(S::Resolved->canTransitionTo(S::UnderReview))->toBeFalse()
        ->and(S::Dismissed->canTransitionTo(S::Resolved))->toBeFalse();
});

it('treats resolved and dismissed as terminal outcomes that still reopen', function (): void {
    expect(S::Resolved->isResolvedOrDismissed())->toBeTrue()
        ->and(S::Dismissed->isResolvedOrDismissed())->toBeTrue()
        ->and(S::Open->isResolvedOrDismissed())->toBeFalse()
        ->and(S::UnderReview->isResolvedOrDismissed())->toBeFalse()
        ->and(S::Reopened->isResolvedOrDismissed())->toBeFalse()
        ->and(S::Resolved->canTransitionTo(S::Reopened))->toBeTrue()
        ->and(S::Dismissed->canTransitionTo(S::Reopened))->toBeTrue();
});

it('maps every status to a DB-CHECK value', function (): void {
    expect(array_map(fn (S $s) => $s->value, S::cases()))
        ->toBe(['open', 'under_review', 'resolved', 'dismissed', 'reopened']);
});
