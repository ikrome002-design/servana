<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Enums;

/**
 * Safe, closed vocabulary of reasons a selected client is excluded from an SMS campaign
 * (Plan §64 "returns recipient count, excluded count/reasons"; Phase 21S).
 *
 * CONTACT PROTECTION (ADR-010): these codes are the ONLY explanation a preview or a recipient
 * snapshot ever carries. They are deliberately coarse — they say *why the send will not happen*,
 * never *who the client is*. In particular:
 *
 *   - `unknown_client` and `not_served` are separate codes but carry identical detail, so a
 *     Personnel user cannot use the preview endpoint to probe whether an arbitrary client ULID
 *     exists in the merchant (both are also uniformly counted, never listed by identity).
 *   - No code carries a phone number, an email, a client name, or a branch/merchant name.
 *
 * The preview response groups exclusions BY CODE with a count; it never returns a per-client list,
 * which is what stops the endpoint from becoming an enumeration oracle.
 */
enum SmsRecipientExclusionReason: string
{
    /**
     * The ULID matched no client the acting personnel may see. Deliberately UNIFORM across
     * "no such client", "another merchant's client" and "another branch's client": the branch and
     * merchant global scopes make all three indistinguishable, which is what stops the preview
     * endpoint from confirming whether a guessed ULID exists.
     */
    case UnknownClient = 'unknown_client';
    /** No COMPLETED service session performed by this staff profile for this client. */
    case NotServed = 'not_served';
    case ConsentOptedOut = 'consent_opted_out';
    case ConsentMissing = 'consent_missing';
    case ClientArchived = 'client_archived';
    /** The same client ULID appeared more than once in the selection. */
    case DuplicateSelection = 'duplicate_selection';
    /**
     * The campaign was cancelled before this recipient was handed to the provider. Not a consent
     * decision and not an eligibility miss — it exists so a cancelled campaign's suppressions are
     * distinguishable from every other reason in the recipient snapshot.
     */
    case CampaignCancelled = 'campaign_cancelled';

    /**
     * All backing values, in canonical order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }

    /**
     * Whether this exclusion is recorded as an opt-out on the recipient snapshot. Only an explicit
     * consent opt-out is; everything else is a generic suppression, so the merchant can tell a
     * consent decision apart from an eligibility miss.
     */
    public function recipientStatus(): PersonnelSmsRecipientDeliveryStatus
    {
        return match ($this) {
            self::ConsentOptedOut => PersonnelSmsRecipientDeliveryStatus::OptedOut,
            default => PersonnelSmsRecipientDeliveryStatus::Suppressed,
        };
    }

    /** Sentence-case, contact-free explanation shown in the UI. */
    public function label(): string
    {
        return match ($this) {
            self::UnknownClient => 'Not available to you',
            self::NotServed => 'You have no completed session with this client',
            self::ConsentOptedOut => 'Client opted out of SMS',
            self::ConsentMissing => 'No SMS consent on record',
            self::ClientArchived => 'Client is archived',
            self::DuplicateSelection => 'Selected more than once',
            self::CampaignCancelled => 'Campaign was cancelled before sending',
        };
    }
}
