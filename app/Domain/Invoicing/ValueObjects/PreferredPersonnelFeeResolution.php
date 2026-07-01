<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\ValueObjects;

/**
 * The outcome of resolving a completed session's preferred-personnel fee at
 * finalization (Gate D, Phase 17). Immutable; carries both the snapshotted amount
 * and the source classification so the invoice audit can record how the fee was
 * derived. `amountMinor` is null when no fee applies (request not honoured, or no
 * preferred request); the snapshot is permanent and never recalculated.
 */
final readonly class PreferredPersonnelFeeResolution
{
    public const SOURCE_NOT_REQUESTED = 'not_requested';

    public const SOURCE_NOT_HONOURED = 'not_honoured';

    public const SOURCE_LEGACY_SERVICE_FIXED = 'legacy_service_fixed';

    public function __construct(
        public ?int $amountMinor,
        public string $source,
        public bool $honoured,
    ) {}

    public static function notRequested(): self
    {
        return new self(null, self::SOURCE_NOT_REQUESTED, false);
    }

    public static function notHonoured(): self
    {
        return new self(null, self::SOURCE_NOT_HONOURED, false);
    }

    public static function legacyServiceFixed(?int $amountMinor): self
    {
        return new self($amountMinor, self::SOURCE_LEGACY_SERVICE_FIXED, true);
    }

    public function hasFee(): bool
    {
        return $this->amountMinor !== null && $this->amountMinor > 0;
    }
}
