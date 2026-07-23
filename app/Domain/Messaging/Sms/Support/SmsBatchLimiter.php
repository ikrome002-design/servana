<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Support;

use App\Domain\Messaging\Sms\Exceptions\SmsBatchLimitException;

/**
 * Enforces the configured maximum batch size for one SMS campaign (Plan §64 "select recipients
 * (configurable max batch)"; Phase 21S).
 *
 * This is the control that stops a Personnel user from turning the send flow into a bulk contact
 * operation, so it is enforced SERVER-SIDE at BOTH preview and confirm, never by the frontend
 * (which only displays the limit). The Form Request also carries the same bound as a validation
 * rule so an over-sized selection is rejected with the standard 422 field envelope before any
 * database work happens; this class is the domain-level backstop for the confirm path and for any
 * caller that bypasses the request layer.
 */
final class SmsBatchLimiter
{
    public function maxRecipients(): int
    {
        return max(1, (int) config('sms.limits.max_recipients_per_campaign', 200));
    }

    public function maxMessageCharacters(): int
    {
        return max(1, (int) config('sms.limits.max_message_characters', 480));
    }

    public function maxSegments(): int
    {
        return max(1, (int) config('sms.limits.max_segments_per_message', 4));
    }

    public function exceedsRecipientLimit(int $count): bool
    {
        return $count > $this->maxRecipients();
    }

    /**
     * Assert a selection fits the batch cap.
     *
     * @throws SmsBatchLimitException
     */
    public function ensureWithinRecipientLimit(int $count): void
    {
        if ($this->exceedsRecipientLimit($count)) {
            throw SmsBatchLimitException::tooManyRecipients($count, $this->maxRecipients());
        }
    }

    /**
     * Assert a composed message fits the length + segment caps.
     *
     * @throws SmsBatchLimitException
     */
    public function ensureWithinMessageLimits(int $characterCount, int $segmentCount): void
    {
        if ($characterCount > $this->maxMessageCharacters()) {
            throw SmsBatchLimitException::messageTooLong($characterCount, $this->maxMessageCharacters());
        }

        if ($segmentCount > $this->maxSegments()) {
            throw SmsBatchLimitException::tooManySegments($segmentCount, $this->maxSegments());
        }
    }
}
