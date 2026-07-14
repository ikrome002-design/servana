<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\PlatformFeeAdjustmentType;
use App\Domain\Billing\Models\PlatformFeeAdjustment;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Records a FULL additive reversal of an original earned platform-fee liability (Plan §13.10, §51, §953;
 * Phase 20E, Increment 5B). Driven by a merchant-client invoice void or a full refund, inside the owning
 * correction transaction (which already enforces the period lock + maker/checker). It reverses exactly
 * the remaining reversible balance via {@see RecordPlatformFeeAdjustment} (`adjustment_type='reversal'`,
 * a negative amount), so a partially-adjusted entry is reversed only for what remains and the original
 * earned amount is never rewritten. Idempotent per source correction event; a nothing-left-to-reverse
 * entry is a no-op (returns null).
 */
final class RecordPlatformFeeReversal
{
    public function __construct(private readonly RecordPlatformFeeAdjustment $adjustments) {}

    public function record(
        PlatformFeeLedgerEntry $original,
        string $reason,
        ?string $sourceReference,
        string $idempotencyKey,
        User $actor,
        CarbonImmutable $businessDate,
    ): ?PlatformFeeAdjustment {
        // Replay: an already-recorded reversal for this source event is returned unchanged.
        $existing = PlatformFeeAdjustment::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $remaining = $this->adjustments->remainingReversible($original);
        if ($remaining <= 0) {
            return null;
        }

        return $this->adjustments->record(
            $original,
            PlatformFeeAdjustmentType::Reversal,
            -$remaining,
            $reason,
            $sourceReference,
            $idempotencyKey,
            $actor,
            $businessDate,
        );
    }
}
