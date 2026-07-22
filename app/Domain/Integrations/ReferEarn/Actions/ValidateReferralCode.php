<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Actions;

use App\Domain\Integrations\ReferEarn\Clients\ReferEarnClientInterface;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Jobs\ConfirmAttributionJob;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;

/**
 * Ask Citrus R&E whether a captured referral code is usable (Plan §58A.1, §58A.2, §25.6;
 * Phase 21R-A).
 *
 * Runs asynchronously, never inside the registration request — registration is complete and
 * committed before this ever executes (Plan A-19: registration never blocks or fails because of
 * R&E).
 *
 * A malformed code can never reach here: `CaptureReferralSnapshot` marks it `invalid_format` and
 * queues nothing, and this action re-asserts that by refusing to act on any snapshot whose
 * normalized code is null. That is the §58A.1 guarantee — "malformed codes … are never sent to R&E"
 * — enforced twice, on purpose.
 */
final class ValidateReferralCode
{
    public function __construct(
        private readonly ReferEarnClientInterface $client,
        private readonly TransitionReferralSnapshot $transition,
    ) {}

    /** @return bool true when the snapshot reached a settled outcome (validated or rejected) */
    public function handle(ReferralSnapshot $snapshot): bool
    {
        $code = $snapshot->code_normalized;

        // Nothing to do: malformed, already settled, or already past validation.
        if ($code === null || $snapshot->snapshot_status->isTerminal() || $snapshot->snapshot_status === ReferralSnapshotStatus::Validated) {
            return false;
        }

        if ($snapshot->snapshot_status === ReferralSnapshotStatus::Captured) {
            $snapshot = $this->transition->handle($snapshot, ReferralSnapshotStatus::Validating);
        }

        $result = $this->client->validateReferralCode($code, $snapshot->ulid);

        // No verdict — R&E was unreachable, rate-limited or erroring. The snapshot STAYS
        // `validating` (§25.6: "retries stay within the same state") and the job retries.
        if ($result->retryable) {
            return false;
        }

        if (! $result->valid) {
            $this->transition->handle($snapshot, ReferralSnapshotStatus::Rejected, [
                're_validation_result_code' => $result->resultCode,
            ]);

            return true;
        }

        $snapshot = $this->transition->handle($snapshot, ReferralSnapshotStatus::Validated, [
            're_validation_result_code' => $result->resultCode,
        ]);

        // Confirmation is its OWN queued step, not an inline call: it has its own failure mode and
        // its own retry budget. Folding it in here would let a retryable confirm failure be swallowed
        // by a validation job that has already succeeded.
        ConfirmAttributionJob::dispatch($snapshot->id);

        return true;
    }
}
