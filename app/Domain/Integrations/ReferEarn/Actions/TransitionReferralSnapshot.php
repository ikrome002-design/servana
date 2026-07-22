<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Actions;

use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Exceptions\ReferralSnapshotStateException;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of `referral_snapshots.snapshot_status` (Plan §25.6; Phase 21R-A).
 *
 * Locks the row, validates the transition against the enum machine, applies the small set of
 * columns each transition owns, and stamps `last_transition_at`. Controllers and jobs never assign
 * a status directly — an invalid move raises `ReferralSnapshotStateException` rather than silently
 * no-op'ing, and the `referral_snapshots_guard` DB trigger is the independent backstop.
 *
 * Concurrency: two workers may race (e.g. a retried validation job and the expiry sweep). The row
 * lock plus the re-read inside the transaction means the loser sees the winner's committed status
 * and fails the guard, so a terminal state can never be overwritten.
 */
final class TransitionReferralSnapshot
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns this transition owns
     */
    public function handle(
        ReferralSnapshot $snapshot,
        ReferralSnapshotStatus $to,
        array $attributes = [],
    ): ReferralSnapshot {
        return DB::transaction(function () use ($snapshot, $to, $attributes): ReferralSnapshot {
            $locked = ReferralSnapshot::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();

            $from = $locked->snapshot_status;

            if (! $from->canTransitionTo($to)) {
                throw ReferralSnapshotStateException::invalidTransition($from->value, $to->value);
            }

            $locked->fill($attributes);
            $locked->snapshot_status = $to;
            $locked->last_transition_at = now();

            // The DB CHECK ties confirmed_at to the confirmed status; set it here so no caller has
            // to remember, and so a confirm can never land without its timestamp.
            if ($to === ReferralSnapshotStatus::Confirmed && $locked->confirmed_at === null) {
                $locked->confirmed_at = now();
            }

            $locked->save();

            return $locked;
        });
    }
}
