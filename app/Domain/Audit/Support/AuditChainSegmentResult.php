<?php

declare(strict_types=1);

namespace App\Domain\Audit\Support;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditChainSegmentVerifier;
use Illuminate\Support\Collection;

/**
 * The outcome of one bounded, read-only segment verification (Phase UI-15; ADR-008).
 *
 * Produced by {@see AuditChainSegmentVerifier}. It holds the verified rows so the caller can count
 * only what the viewer is allowed to see, and the first failing row so the caller can decide
 * whether that row may be named. It never carries a hash value outward.
 */
final readonly class AuditChainSegmentResult
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BROKEN = 'broken';

    public const STATUS_EMPTY = 'empty';

    /**
     * @param  Collection<int, AuditLog>  $rows  the checked rows, oldest first
     * @param  bool  $completeHistory  true when the segment starts at the chain's genesis row
     * @param  string|null  $breakCategory  `broken_link` or `hash_mismatch` (VerifyAuditChain categories)
     */
    public function __construct(
        public Collection $rows,
        public bool $completeHistory,
        public ?AuditLog $breakRow,
        public ?string $breakCategory,
    ) {}

    /**
     * `verified` is reserved for a consistent chain checked from its genesis row. A consistent
     * segment that does not reach genesis is `partial`: the earlier history was not part of this
     * check, and calling it verified would claim more than was proved.
     */
    public function status(): string
    {
        if ($this->breakRow !== null) {
            return self::STATUS_BROKEN;
        }

        if ($this->rows->isEmpty()) {
            return self::STATUS_EMPTY;
        }

        return $this->completeHistory ? self::STATUS_VERIFIED : self::STATUS_PARTIAL;
    }
}
