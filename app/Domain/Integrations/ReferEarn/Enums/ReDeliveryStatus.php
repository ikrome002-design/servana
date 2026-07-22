<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Enums;

/**
 * Outbox delivery lifecycle (Plan §25.6; Phase 21R-A).
 *
 * Mirrors the `re_outbound_events.delivery_status` DB CHECK and its append-only trigger:
 *
 *   pending → delivering → delivered                         (terminal)
 *   delivering → pending  (retry with backoff; same event id + same body hash)
 *   delivering → dead_letter (max age / 409 payload mismatch / 422 schema)   (terminal)
 *   delivered | dead_letter → superseded  (schema-version replacement replay only; final)
 */
enum ReDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case DeadLetter = 'dead_letter';
    case Superseded = 'superseded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::DeadLetter, self::Superseded => true,
            self::Pending, self::Delivering => false,
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => $to === self::Delivering,
            self::Delivering => $to === self::Delivered || $to === self::DeadLetter || $to === self::Pending,
            self::Delivered, self::DeadLetter => $to === self::Superseded,
            self::Superseded => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
