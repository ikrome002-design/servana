<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Exceptions\PaymentGroupStateException;

/**
 * Payment-recording-group transition guard (Plan §25, §41; Phase 18A). Every status
 * change goes through a named action calling {@see ensure()}; an unlisted transition
 * is rejected with `422 invalid_state_transition`, never a silent no-op.
 *
 * {@see ensurePhase18a()} is the stricter guard the Phase 18A production actions use:
 * it additionally refuses any Phase-18B transition (validate/reject/reverse), even
 * though the full machine defines them, so a mis-wired 18A caller cannot advance a
 * group into a checker-owned state.
 */
final class PaymentRecordingGroupStateMachine
{
    public function ensure(PaymentRecordingGroupStatus $from, PaymentRecordingGroupStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw PaymentGroupStateException::invalidTransition($from, $to);
        }
    }

    public function ensurePhase18a(PaymentRecordingGroupStatus $from, PaymentRecordingGroupStatus $to): void
    {
        if (! $from->isPhase18aTransition($to)) {
            throw PaymentGroupStateException::invalidTransition($from, $to);
        }
    }
}
