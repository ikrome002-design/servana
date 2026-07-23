<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Enums;

use App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine;

/**
 * Personnel SMS campaign lifecycle (Plan §13.13, §64; Phase 21S). Mirrors the
 * `personnel_sms_campaigns.status` DB CHECK exactly — parity is guarded by
 * `Phase21SEnumParityTest`.
 *
 * Status is never assigned directly: every change goes through a named domain action via
 * {@see PersonnelSmsCampaignStateMachine}, and the database trigger
 * `personnel_sms_campaigns_guard` is the backstop for terminal finality.
 *
 * `draft` is the composed-but-unconfirmed campaign (recipient snapshots already exist, nothing is
 * billed and nothing is queued). `confirmed` is the authoritative commitment point: consent is
 * snapshotted, the billing entry is created and delivery is queued after commit. `partially_failed`
 * is a settleable state, not a terminal one — outstanding recipients may still resolve.
 */
enum PersonnelSmsCampaignStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Queued = 'queued';
    case Sending = 'sending';
    case Completed = 'completed';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

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

    /** Terminal states can never transition again (trigger-enforced). */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /** Whether the composition/pricing snapshot is still editable (draft only). */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether a cancellation is still possible (nothing has been handed to the provider yet). */
    public function isCancellable(): bool
    {
        return match ($this) {
            self::Draft, self::Confirmed, self::Queued => true,
            default => false,
        };
    }

    /**
     * Authoritative Phase-21S transition inventory (Plan §64). Anything else is invalid and fails
     * closed with 422 `invalid_state_transition`.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Queued, self::Cancelled],
            self::Queued => [self::Sending, self::Cancelled],
            self::Sending => [self::Completed, self::PartiallyFailed, self::Failed],
            // Outstanding recipients may still deliver (-> completed) or exhaust their retries
            // (-> failed). The settle action decides from the recipient roll-up, never a client.
            self::PartiallyFailed => [self::Completed, self::Failed],
            self::Completed, self::Failed, self::Cancelled => [],
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
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Completed => 'Completed',
            self::PartiallyFailed => 'Partially failed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
