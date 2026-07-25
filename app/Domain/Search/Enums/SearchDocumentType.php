<?php

declare(strict_types=1);

namespace App\Domain\Search\Enums;

use App\Domain\Messaging\Sms\Support\ServedClientSelector;

/**
 * The Phase 22 search catalogue types (Plan §68; `docs/architecture/search/search-catalogue.md`).
 *
 * This enum is the request allowlist AND the response `type` vocabulary. A value that is not a case
 * here cannot be requested (the Form Request rejects it with 422) and cannot be returned. Adding a
 * case is therefore a deliberate act that requires a catalogue row, an authority anchor, a masking
 * rule and its own isolation tests — it can never happen by accident.
 *
 * Deferred candidates (services, payments, refunds, cash-ups, finance disputes/exports,
 * compensation, payouts, audit logs, SMS campaigns, platform-side merchants/branches, client
 * consents) are deliberately ABSENT rather than disabled: there is no case, no definition class and
 * no code path. `service` in particular is deferred because the SPA has no service DETAIL screen
 * (`branch.services` is list-only) and Phase 22 does not create screens — see
 * `docs/architecture/search/search-catalogue.md` §5.3.
 */
enum SearchDocumentType: string
{
    case Client = 'client';
    case Staff = 'staff';
    case Appointment = 'appointment';
    case QueueEntry = 'queue_entry';
    case ServiceSession = 'service_session';
    case Invoice = 'invoice';
    case Receipt = 'receipt';

    /**
     * Personnel own-scope, PostgreSQL-only (never indexed — decision D-22-06). Backed by the
     * Phase 21S {@see ServedClientSelector} definition of
     * "personally served".
     */
    case ServedClient = 'served_client';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this type has a Meilisearch index. `served_client` does not: indexing a derived
     * "served by" relation would make own-scope correctness depend on index freshness.
     */
    public function isIndexed(): bool
    {
        return $this !== self::ServedClient;
    }

    /** Human label for the result-type badge (sentence case, per the brand guide). */
    public function label(): string
    {
        return match ($this) {
            self::Client => 'Client',
            self::Staff => 'Staff',
            self::Appointment => 'Appointment',
            self::QueueEntry => 'Queue entry',
            self::ServiceSession => 'Service session',
            self::Invoice => 'Invoice',
            self::Receipt => 'Receipt',
            self::ServedClient => 'Served client',
        };
    }
}
