<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical Wallet payment-registration statuses for subscription invoices
 * (Plan §13.9, §49; ADR-014; Phase 20B). This is an ORTHOGONAL technical projection,
 * NOT part of the invoice financial machine, and never blocks issuance (ADR-014).
 *
 * Phase 20B ships this column at its default `unregistered` on every issued invoice
 * and NEVER writes any other value — there is no Wallet runtime, client, or outbox in
 * 20B. Registration (`unregistered → pending → registered | failed`) is Phase 20D-W.
 * Used across the PHP enum, the PostgreSQL CHECK on
 * `subscription_invoices.wallet_registration_status`, factories, OpenAPI/TS, and audit.
 */
enum WalletRegistrationStatus: string
{
    case Unregistered = 'unregistered';
    case Pending = 'pending';
    case Registered = 'registered';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Unregistered => 'Unregistered',
            self::Pending => 'Pending',
            self::Registered => 'Registered',
            self::Failed => 'Failed',
        };
    }
}
