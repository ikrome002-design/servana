<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Integrations\ReferEarn\Clients\ReferEarnClientInterface;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;

/**
 * Ask Citrus R&E to confirm the attribution for a validated referral snapshot (Plan §58A.1,
 * §58A.2, §58B.5 R-04; §25.6; Phase 21R-A).
 *
 * Attribution uniqueness is **R&E's** decision, never Servana's (ADR-013). A rejection here usually
 * means another referrer is already effective for this merchant — the code was fine, the claim was
 * not. Servana records the outcome and stops emitting; it never re-decides.
 *
 * The call is idempotent by snapshot ULID (Plan §58A.2), so a retry after an ambiguous network
 * failure cannot create a second attribution at R&E.
 *
 * The only thing Servana keeps from a confirmation is R&E's OPAQUE public attribution id. No
 * referrer identity is stored or displayed, ever (Plan §9 rule 23, §13.17).
 */
final class ConfirmAttribution
{
    public function __construct(
        private readonly ReferEarnClientInterface $client,
        private readonly TransitionReferralSnapshot $transition,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return bool true when the snapshot reached a terminal outcome */
    public function handle(ReferralSnapshot $snapshot): bool
    {
        $code = $snapshot->code_normalized;

        if ($code === null || $snapshot->snapshot_status !== ReferralSnapshotStatus::Validated) {
            return false;
        }

        $merchantPublicId = (string) $snapshot->merchant()->value('ulid');

        if ($merchantPublicId === '') {
            return false;
        }

        $result = $this->client->confirmAttribution($code, $snapshot->ulid, $merchantPublicId);

        // No verdict yet: stay `validated` and let the job retry. The expiry sweep is what
        // eventually settles a snapshot R&E never answers for.
        if ($result->retryable) {
            return false;
        }

        if (! $result->confirmed || $result->attributionPublicId === null) {
            $rejected = $this->transition->handle($snapshot, ReferralSnapshotStatus::Rejected, [
                're_validation_result_code' => $result->resultCode,
            ]);

            $this->audit->record(AuditEvent::ReAttributionRejected, null, $rejected->merchant_id, null, $rejected, [
                'referral_snapshot' => $rejected->ulid,
                'result_code' => $result->resultCode,
            ]);

            return true;
        }

        $confirmed = $this->transition->handle($snapshot, ReferralSnapshotStatus::Confirmed, [
            're_attribution_public_id' => $result->attributionPublicId,
            're_validation_result_code' => $result->resultCode,
        ]);

        $this->audit->record(AuditEvent::ReAttributionConfirmed, null, $confirmed->merchant_id, null, $confirmed, [
            'referral_snapshot' => $confirmed->ulid,
            // R&E's opaque public id is safe to audit; the referral CODE is not (Plan §24.5).
            'attribution_public_id' => $result->attributionPublicId,
        ]);

        return true;
    }
}
