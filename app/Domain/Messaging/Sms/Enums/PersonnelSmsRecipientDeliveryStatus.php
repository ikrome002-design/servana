<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Enums;

use App\Domain\Messaging\Sms\Services\PersonnelSmsRecipientStateMachine;

/**
 * Per-recipient delivery lifecycle (Plan §13.13, §64; Phase 21S). Mirrors the
 * `personnel_sms_recipients.delivery_status` DB CHECK exactly — parity is guarded by
 * `Phase21SEnumParityTest`.
 *
 * Status is never assigned directly: every change goes through {@see PersonnelSmsRecipientStateMachine},
 * and `personnel_sms_recipients_guard` is the database backstop for terminal finality.
 *
 * `opted_out` and `suppressed` both mean "never handed to the provider", and both are recorded at
 * confirm time (or when a provider tells us the subscriber has opted out). They are distinguished
 * because opt-out is a consent fact the merchant must see, while suppression covers every other
 * safe exclusion reason ({@see SmsRecipientExclusionReason}).
 */
enum PersonnelSmsRecipientDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case OptedOut = 'opted_out';
    case Suppressed = 'suppressed';

    /**
     * All backing values, in canonical order — the authoritative list for the DB CHECK and every
     * parity assertion.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Failed, self::OptedOut, self::Suppressed => true,
            default => false,
        };
    }

    /** Whether this recipient was ever handed to the provider (drives the billable roll-up). */
    public function wasDispatched(): bool
    {
        return match ($this) {
            self::Sent, self::Delivered, self::Failed => true,
            self::Pending, self::OptedOut, self::Suppressed => false,
        };
    }

    /**
     * Billable outcomes (Plan §64 "record provider result + cost"). A recipient that was never
     * dispatched is never billed; a permanently failed one was still submitted to the provider and
     * carries the provider's own cost, so it stays billable.
     */
    public function isBillable(): bool
    {
        return $this->wasDispatched();
    }

    /**
     * Statuses that still have delivery work outstanding — the campaign cannot settle while any
     * recipient is in one of these.
     *
     * @return list<self>
     */
    public static function outstandingStatuses(): array
    {
        return [self::Pending, self::Sent];
    }

    /**
     * Authoritative Phase-21S transition inventory (Plan §64).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Sent, self::Failed, self::OptedOut, self::Suppressed],
            self::Sent => [self::Delivered, self::Failed],
            self::Delivered, self::Failed, self::OptedOut, self::Suppressed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::OptedOut => 'Opted out',
            self::Suppressed => 'Suppressed',
        };
    }
}
