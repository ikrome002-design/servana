<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Integrations\ReferEarn\Data\ReferralCaptureData;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use App\Domain\Integrations\ReferEarn\Support\ReferralCodeNormalizer;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Capture a referral code at merchant self-registration (Plan §58A.1, §13.17, §25.6; ADR-013;
 * Phase 21R-A).
 *
 * Runs **inside** the registration transaction (`RegisterMerchant::handle()`), because the snapshot
 * is the evidence that makes a referrer's claim survive R&E being unavailable — evidence that must
 * not exist for a registration that rolled back, and must always exist for one that did not.
 *
 * **Registration is never blocked or failed by R&E (Plan A-19, §58B.5 R-03).** Nothing here calls a
 * partner: it writes one local row and queues work for after the commit. It is additionally wrapped
 * so that an unexpected fault in referral capture can never take down a merchant registration — a
 * lost referral snapshot is a recoverable partner-side problem, a failed registration is a lost
 * customer.
 *
 * **Malformed codes are stored as `invalid_format` and never sent to R&E** (§58A.1): the evidence
 * is kept, no job is queued, and the emission-scope rule suppresses every downstream event.
 */
final class CaptureReferralSnapshot
{
    public function __construct(
        private readonly ReferralCodeNormalizer $normalizer,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return ReferralSnapshot|null null when no code was submitted, or capture failed safely */
    public function handle(Merchant $merchant, ?ReferralCaptureData $capture): ?ReferralSnapshot
    {
        if ($capture === null) {
            return null;
        }

        if (DB::transactionLevel() === 0) {
            // A snapshot outside the registration transaction could survive a rolled-back
            // registration. Refuse rather than create orphan evidence.
            throw new \RuntimeException('CaptureReferralSnapshot must run inside the registration transaction (Plan §58A.1).');
        }

        try {
            // A NESTED transaction, so this runs inside a SAVEPOINT. That detail is load-bearing:
            // PostgreSQL aborts the whole transaction on a failed statement, so simply catching a
            // QueryException here would leave the registration unable to continue. Rolling back to
            // the savepoint restores a usable transaction and lets registration finish unreferred.
            return DB::transaction(function () use ($merchant, $capture): ReferralSnapshot {
                $normalized = $this->normalizer->normalize($capture->submittedCode);
                $status = $normalized === null ? ReferralSnapshotStatus::InvalidFormat : ReferralSnapshotStatus::Captured;

                $snapshot = ReferralSnapshot::query()->create([
                    'merchant_id' => $merchant->id,
                    // The Eloquent cast encrypts this; the column never holds plaintext, and Plan
                    // §24.5 forbids logging the decrypted value.
                    'raw_code_encrypted' => $capture->submittedCode,
                    'code_normalized' => $normalized,
                    'capture_channel' => $capture->channel,
                    'captured_at' => now(),
                    'landing_metadata' => $capture->landingMetadata,
                    'snapshot_status' => $status,
                    'last_transition_at' => now(),
                ]);

                $this->audit->record(AuditEvent::ReReferralCaptured, null, $merchant->id, null, $snapshot, [
                    'referral_snapshot' => $snapshot->ulid,
                    'capture_channel' => $capture->channel->value,
                    'snapshot_status' => $status->value,
                    // Whether the code was well-formed is the auditable fact; the code itself is not.
                    'landing_metadata_keys' => array_keys($capture->landingMetadata ?? []),
                ]);

                return $snapshot;
            });
        } catch (Throwable $e) {
            // Plan A-19 is absolute: registration outcome is independent of the referral subsystem.
            // Report the fault (so it is not silent) and let registration proceed unreferred.
            report($e);

            return null;
        }
    }
}
