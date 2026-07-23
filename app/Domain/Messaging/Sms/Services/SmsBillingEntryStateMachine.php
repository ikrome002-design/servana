<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Services;

use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus;
use App\Domain\Messaging\Sms\Exceptions\PersonnelSmsStateException;

/**
 * Billable-SMS entry guard (Plan §25.1, §64; Phase 21S).
 *
 * THE single place that authorizes an `sms_billing_entries.status` transition. The inventory lives
 * on {@see SmsBillingEntryStatus::allowedTransitions()}; `sms_billing_entries_guard` freezes every
 * monetary column and enforces terminal finality in the database.
 */
final class SmsBillingEntryStateMachine
{
    public function canTransition(SmsBillingEntryStatus $from, SmsBillingEntryStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * Assert a transition is legal or throw the canonical 422 envelope.
     *
     * @throws PersonnelSmsStateException
     */
    public function ensure(SmsBillingEntryStatus $from, SmsBillingEntryStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw PersonnelSmsStateException::invalidBillingTransition($from, $to);
        }
    }
}
