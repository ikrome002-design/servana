<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Services;

use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Exceptions\PersonnelSmsStateException;

/**
 * Recipient delivery-status guard (Plan §25.1, §64; Phase 21S).
 *
 * THE single place that authorizes a `personnel_sms_recipients.delivery_status` transition. The
 * delivery job and the suppression action call {@see ensure()} before writing; the inventory lives
 * on {@see PersonnelSmsRecipientDeliveryStatus::allowedTransitions()}, and
 * `personnel_sms_recipients_guard` is the database backstop for terminal finality.
 */
final class PersonnelSmsRecipientStateMachine
{
    public function canTransition(PersonnelSmsRecipientDeliveryStatus $from, PersonnelSmsRecipientDeliveryStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * Assert a transition is legal or throw the canonical 422 envelope.
     *
     * @throws PersonnelSmsStateException
     */
    public function ensure(PersonnelSmsRecipientDeliveryStatus $from, PersonnelSmsRecipientDeliveryStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw PersonnelSmsStateException::invalidRecipientTransition($from, $to);
        }
    }
}
