<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Services;

use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Exceptions\PersonnelSmsStateException;

/**
 * Campaign state-machine guard (Plan §25.1, §64; Phase 21S).
 *
 * THE single place that authorizes a `personnel_sms_campaigns.status` transition. Domain actions
 * call {@see ensure()} before writing; the transition inventory lives on
 * {@see PersonnelSmsCampaignStatus::allowedTransitions()}. There is no generic `PATCH status` —
 * every transition has a named action and runs through here, and the database trigger
 * `personnel_sms_campaigns_guard` is the backstop.
 */
final class PersonnelSmsCampaignStateMachine
{
    public function canTransition(PersonnelSmsCampaignStatus $from, PersonnelSmsCampaignStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * Assert a transition is legal or throw the canonical 422 envelope.
     *
     * @throws PersonnelSmsStateException
     */
    public function ensure(PersonnelSmsCampaignStatus $from, PersonnelSmsCampaignStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw PersonnelSmsStateException::invalidCampaignTransition($from, $to);
        }
    }

    /**
     * Assert the campaign composition may still be changed (draft only).
     *
     * @throws PersonnelSmsStateException
     */
    public function ensureEditable(PersonnelSmsCampaignStatus $status): void
    {
        if (! $status->isEditable()) {
            throw PersonnelSmsStateException::notEditable($status);
        }
    }
}
