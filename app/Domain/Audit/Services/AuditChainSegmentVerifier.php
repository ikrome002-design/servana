<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Console\Commands\VerifyAuditChain;
use App\Domain\Audit\Events\AuditChainVerificationFailed;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Support\AuditChainSegmentResult;

/**
 * Bounded, read-only verification of the most recent segment of one merchant's audit chain
 * (Phase UI-15, UI/UX plan Appendix A §12.4.13; Plan §70, ADR-008).
 *
 * The scheduled {@see VerifyAuditChain} walks every chain in full and persists nothing, so the
 * Audit account had no readable integrity fact. This service re-runs the SAME algorithm — the
 * canonical {@see AuditChainHasher}, the same link rule, the same two failure categories — over the
 * latest `$limit` rows of the caller's merchant chain, anchored to the stored hash of the row just
 * before the segment. It proves the segment is internally consistent and correctly linked to that
 * anchor; it does not prove anything about rows older than the anchor, which is why a segment that
 * does not start at genesis reports `partial`, never `verified`.
 *
 * It reads only stored columns, writes nothing and repairs nothing. The full-chain verifier and any
 * remediation stay with platform engineering (§12.4.13 "implementation/repair controls are
 * platform/engineering-only").
 */
final class AuditChainSegmentVerifier
{
    /** Rows checked per request — bounded so a read can never walk an unbounded chain. */
    public const SEGMENT_LIMIT = 1000;

    public function __construct(private readonly AuditChainHasher $hasher) {}

    public function verifyMerchantSegment(int $merchantId, int $limit = self::SEGMENT_LIMIT): AuditChainSegmentResult
    {
        // One query returns the segment AND its anchor, so a row appended mid-request cannot
        // split them. Hash columns never leave this method.
        $window = AuditLog::query()
            ->where('merchant_id', $merchantId)
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->reverse()
            ->values();

        $anchor = $window->count() > $limit ? $window->first() : null;
        $rows = $anchor === null ? $window : $window->slice(1)->values();

        $previousHash = $anchor?->hash;

        foreach ($rows as $index => $row) {
            $linkOk = $row->previous_hash === $previousHash;
            $hashOk = hash_equals($this->hasher->hashOf($row, $previousHash), $row->hash);

            if (! $linkOk || ! $hashOk) {
                // Like the full verifier, nothing past the first break is trusted or counted.
                return new AuditChainSegmentResult(
                    rows: $rows->take($index + 1)->values(),
                    completeHistory: $anchor === null,
                    breakRow: $row,
                    breakCategory: ! $linkOk
                        ? AuditChainVerificationFailed::CATEGORY_BROKEN_LINK
                        : AuditChainVerificationFailed::CATEGORY_HASH_MISMATCH,
                );
            }

            $previousHash = $row->hash;
        }

        return new AuditChainSegmentResult(
            rows: $rows,
            completeHistory: $anchor === null,
            breakRow: null,
            breakCategory: null,
        );
    }
}
