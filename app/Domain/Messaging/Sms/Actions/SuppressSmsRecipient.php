<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsRecipientExclusionReason;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Services\PersonnelSmsRecipientStateMachine;

/**
 * THE single way a recipient stops being deliverable without ever reaching the provider
 * (Plan §64 "opt-out suppression"; Phase 21S).
 *
 * Used by {@see ConfirmSmsCampaign} (a recipient that no longer qualifies at the commitment point)
 * and {@see CancelSmsCampaign} (every still-pending recipient of a cancelled campaign). Having one
 * path means the mapping from a safe exclusion reason to a terminal status — an explicit consent
 * opt-out becomes `opted_out`, everything else becomes `suppressed` — is defined in exactly one
 * place, and the recipient state machine authorises every one of those writes.
 *
 * Returns true when the recipient actually moved, false when it was already terminal (so callers
 * can count accurately without re-reading).
 */
final class SuppressSmsRecipient
{
    public function __construct(private readonly PersonnelSmsRecipientStateMachine $state) {}

    public function handle(PersonnelSmsRecipient $recipient, SmsRecipientExclusionReason $reason): bool
    {
        if ($recipient->delivery_status !== PersonnelSmsRecipientDeliveryStatus::Pending) {
            return false;
        }

        $terminal = $reason->recipientStatus();

        $this->state->ensure($recipient->delivery_status, $terminal);
        $recipient->forceFill(['delivery_status' => $terminal])->save();

        return true;
    }
}
