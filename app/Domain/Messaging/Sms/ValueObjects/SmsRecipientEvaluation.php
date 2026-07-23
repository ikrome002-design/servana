<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\ValueObjects;

use App\Domain\Messaging\Sms\Enums\SmsRecipientExclusionReason;

/**
 * The outcome of evaluating one recipient selection (Plan §64; Phase 21S).
 *
 * Produced identically by the preview endpoint and by the confirm action — the SAME evaluator runs
 * twice, which is what makes "preview is advisory, confirmation is authoritative" true rather than
 * merely stated: a consent change, an archival or a lost session between the two shows up as a
 * different evaluation at confirm.
 *
 * CONTACT PROTECTION: {@see exclusionCounts()} is the only shape that ever reaches an API response.
 * It is a reason-code → count map, never a per-client list, so the endpoint cannot be used to
 * enumerate which specific ULIDs exist.
 */
final readonly class SmsRecipientEvaluation
{
    /**
     * @param  list<SmsEligibleRecipient>  $eligible
     * @param  list<SmsExcludedRecipient>  $excluded
     */
    public function __construct(
        public array $eligible,
        public array $excluded,
    ) {}

    public function eligibleCount(): int
    {
        return count($this->eligible);
    }

    public function excludedCount(): int
    {
        return count($this->excluded);
    }

    /**
     * Exclusions grouped by safe reason code, in canonical enum order, omitting zero counts.
     *
     * @return array<string, int>
     */
    public function exclusionCounts(): array
    {
        $counts = [];

        foreach (SmsRecipientExclusionReason::cases() as $reason) {
            $counts[$reason->value] = 0;
        }

        foreach ($this->excluded as $exclusion) {
            $counts[$exclusion->reason->value]++;
        }

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    /**
     * Exclusions that must be persisted as visible suppressed/opted-out recipient snapshots.
     *
     * @return list<SmsExcludedRecipient>
     */
    public function snapshottableExclusions(): array
    {
        return array_values(array_filter(
            $this->excluded,
            static fn (SmsExcludedRecipient $exclusion): bool => $exclusion->isSnapshotted(),
        ));
    }
}
