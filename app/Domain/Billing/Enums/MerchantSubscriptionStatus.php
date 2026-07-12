<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical merchant-subscription record-lifecycle statuses (Plan §13.9, §25.4,
 * §48; Phase 20B). This is the lifecycle of the subscription RECORD; the request-
 * authorization billing-access authority is `merchants.billing_status`
 * ({@see MerchantBillingStatus}), projected transactionally from this status (§22).
 * The seven values are used consistently across the PHP enum, the PostgreSQL CHECK
 * on `merchant_subscriptions.status`, factories, request validation/OpenAPI/TS when
 * APIs arrive, frontend options, and audit context. Parity is guarded by
 * `Phase20BEnumParityTest`.
 *
 * Terminal `Cancelled`/`Expired` project to
 * {@see MerchantBillingStatus::SuspendedBilling} (Gate B2); `MerchantBillingStatus`
 * itself has no cancelled/expired value.
 */
enum MerchantSubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case ReadOnlyGrace = 'read_only_grace';
    case Overdue = 'overdue';
    case SuspendedBilling = 'suspended_billing';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * All backing values, in canonical order — the authoritative list for the DB
     * CHECK and every parity assertion.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** True for the two terminal record states (retained history; no reactivation). */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Expired;
    }

    /**
     * Allowed record-lifecycle transitions (Plan §25.2/§25.4; Phase 20B). Mirrors the
     * billing-status access machine plus the terminal `cancelled`/`expired` transitions
     * reachable from any non-terminal state. Recovery `suspended_billing → active` is
     * gated (validated payment + billing-only reason) at the action layer, not here.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Trialing => [self::Active, self::ReadOnlyGrace, self::Expired, self::Cancelled],
            self::Active => [self::Overdue, self::ReadOnlyGrace, self::SuspendedBilling, self::Cancelled, self::Expired],
            self::ReadOnlyGrace => [self::Active, self::SuspendedBilling, self::Cancelled, self::Expired],
            self::Overdue => [self::Active, self::SuspendedBilling, self::Cancelled, self::Expired],
            self::SuspendedBilling => [self::Active, self::Cancelled, self::Expired],
            self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * The merchant billing-access status projected from this record status (Gate B2).
     * Terminal `cancelled`/`expired` project to `suspended_billing`.
     */
    public function projectedBillingStatus(): MerchantBillingStatus
    {
        return match ($this) {
            self::Trialing => MerchantBillingStatus::Trialing,
            self::Active => MerchantBillingStatus::Active,
            self::ReadOnlyGrace => MerchantBillingStatus::ReadOnlyGrace,
            self::Overdue => MerchantBillingStatus::Overdue,
            self::SuspendedBilling, self::Cancelled, self::Expired => MerchantBillingStatus::SuspendedBilling,
        };
    }

    /**
     * Terminal states are excluded from the "one current non-terminal subscription
     * per merchant" partial unique index.
     *
     * @return list<string>
     */
    public static function nonTerminalValues(): array
    {
        return array_values(array_filter(
            self::values(),
            static fn (string $v): bool => ! self::from($v)->isTerminal(),
        ));
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::ReadOnlyGrace => 'Read-only grace',
            self::Overdue => 'Overdue',
            self::SuspendedBilling => 'Suspended (billing)',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }
}
