<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Messaging\Sms\Clients\SmsProviderClientInterface;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsDeliveryAttemptStatus;
use App\Domain\Messaging\Sms\Enums\SmsProviderResultClass;
use App\Domain\Messaging\Sms\Jobs\DeliverSmsRecipientJob;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Models\SmsDeliveryAttempt;
use App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine;
use App\Domain\Messaging\Sms\Services\PersonnelSmsRecipientStateMachine;
use App\Domain\Messaging\Sms\Support\SmsProviderPayloadRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Submit ONE recipient to the SMS provider and record what happened (Plan §64: *"record provider
 * result + cost … retry transient (capped backoff), not permanent invalid/opt-out failures;
 * dedupe by campaign-recipient key"*; Phase 21S).
 *
 * DEDUPE + IDEMPOTENCY: the recipient's own `delivery_status` is the claim. Only a `pending`
 * recipient is ever submitted, and the row is moved out of `pending` in the same transaction that
 * records the attempt — so a duplicate dispatch, a queue redelivery or a concurrent worker finds
 * nothing to do. The `(campaign_id, client_id)` unique index is the structural dedupe key behind it.
 *
 * CONTACT PROTECTION (ADR-010, Plan §24.5): the encrypted delivery snapshot is decrypted here,
 * handed to the adapter, and never written anywhere else — not to a log, not to the attempt row,
 * not to an audit context, not to an exception. The provider's diagnostic goes through
 * {@see SmsProviderPayloadRedactor} before it is persisted, and a DB CHECK rejects the row outright
 * if a run of 7+ digits somehow survives.
 *
 * RETRY POLICY: {@see SmsProviderResultClass} decides. A permanent
 * failure (invalid recipient / provider-side opt-out) is terminal immediately and is never retried.
 * A transient failure is re-dispatched with capped exponential backoff until `sms.delivery
 * .max_attempts` is exhausted, at which point the recipient dead-letters with a HIGH-severity audit
 * event.
 */
final class DeliverSmsRecipient
{
    public function __construct(
        private readonly SmsProviderClientInterface $provider,
        private readonly SmsProviderPayloadRedactor $redactor,
        private readonly PersonnelSmsRecipientStateMachine $recipientState,
        private readonly PersonnelSmsCampaignStateMachine $campaignState,
        private readonly FinalizeSmsCampaign $finalize,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelSmsRecipient $recipient): void
    {
        /** @var PersonnelSmsCampaign|null $campaign */
        $campaign = PersonnelSmsCampaign::query()->whereKey($recipient->campaign_id)->first();

        if ($campaign === null || ! $this->isDispatchable($recipient, $campaign)) {
            return;
        }

        $this->markCampaignSending($campaign);

        $phone = $recipient->phone_encrypted;

        if ($phone === null) {
            // Structurally impossible for a pending recipient (DB CHECK), but fail closed rather
            // than send to nothing.
            return;
        }

        $attemptNumber = $this->nextAttemptNumber($recipient);
        $result = $this->provider->send($phone, $campaign->message_body_encrypted, $this->reference($recipient, $attemptNumber));

        $maxAttempts = max(1, (int) config('sms.delivery.max_attempts', 4));
        $isFinalAttempt = $attemptNumber >= $maxAttempts;
        $resultClass = $result->resultClass;
        $attemptStatus = $resultClass->attemptStatus();
        $willRetry = $attemptStatus === SmsDeliveryAttemptStatus::TransientFailure && ! $isFinalAttempt;
        $nextRetryAt = $willRetry ? Carbon::now()->addSeconds($this->backoffSeconds($attemptNumber)) : null;

        DB::transaction(function () use ($recipient, $result, $attemptNumber, $attemptStatus, $nextRetryAt, $resultClass, $willRetry): void {
            SmsDeliveryAttempt::query()->create([
                'recipient_id' => $recipient->id,
                'attempt_number' => $attemptNumber,
                'provider' => substr($this->provider->providerSlug(), 0, 32),
                'status' => $attemptStatus,
                'result_class' => $resultClass,
                'provider_code' => $result->providerCode,
                // Redacted BEFORE persistence; the column is additionally bounded to 512 chars and
                // a CHECK rejects any surviving 7+ digit run.
                'provider_message_redacted' => $this->redactor->redact($result->rawProviderMessage),
                'duration_ms' => $result->durationMs,
                'attempted_at' => Carbon::now(),
                'next_retry_at' => $nextRetryAt,
            ]);

            if ($resultClass->isAccepted()) {
                $this->recipientState->ensure($recipient->delivery_status, PersonnelSmsRecipientDeliveryStatus::Sent);
                $recipient->forceFill([
                    'delivery_status' => PersonnelSmsRecipientDeliveryStatus::Sent,
                    'provider_message_id' => $result->providerMessageId,
                    'cost_minor' => $result->costMinor,
                ])->save();

                return;
            }

            if ($willRetry) {
                return; // stays `pending`; the re-dispatch below carries the backoff
            }

            // Permanent, or transient with the retry budget exhausted.
            $terminal = $resultClass->isPermanentFailure()
                ? $resultClass->permanentRecipientStatus()
                : PersonnelSmsRecipientDeliveryStatus::Failed;

            $this->recipientState->ensure($recipient->delivery_status, $terminal);
            $recipient->forceFill(['delivery_status' => $terminal])->save();
        });

        $this->recordOutcome($campaign, $recipient, $result->resultClass, $attemptNumber, $willRetry, $isFinalAttempt);

        if ($willRetry) {
            DeliverSmsRecipientJob::dispatch($recipient->merchant_id, $recipient->id)
                ->delay($nextRetryAt);

            return;
        }

        $this->finalize->handle($campaign);
    }

    /** Only a `pending` recipient of a live campaign is ever submitted. */
    private function isDispatchable(PersonnelSmsRecipient $recipient, PersonnelSmsCampaign $campaign): bool
    {
        if ($recipient->delivery_status !== PersonnelSmsRecipientDeliveryStatus::Pending) {
            return false;
        }

        return in_array($campaign->status, [
            PersonnelSmsCampaignStatus::Queued,
            PersonnelSmsCampaignStatus::Sending,
            PersonnelSmsCampaignStatus::PartiallyFailed,
        ], true);
    }

    /** First delivery of a queued campaign flips it to `sending` (once, under a lock). */
    private function markCampaignSending(PersonnelSmsCampaign $campaign): void
    {
        if ($campaign->status !== PersonnelSmsCampaignStatus::Queued) {
            return;
        }

        DB::transaction(function () use ($campaign): void {
            /** @var PersonnelSmsCampaign $locked */
            $locked = PersonnelSmsCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($locked->status !== PersonnelSmsCampaignStatus::Queued) {
                return;
            }

            $this->campaignState->ensure($locked->status, PersonnelSmsCampaignStatus::Sending);
            $locked->forceFill(['status' => PersonnelSmsCampaignStatus::Sending])->save();
        });

        $campaign->refresh();
    }

    private function nextAttemptNumber(PersonnelSmsRecipient $recipient): int
    {
        return (int) SmsDeliveryAttempt::query()
            ->where('recipient_id', $recipient->id)
            ->max('attempt_number') + 1;
    }

    /** Capped exponential backoff (Plan §64 "capped backoff"). */
    private function backoffSeconds(int $attemptNumber): int
    {
        $base = max(1, (int) config('sms.delivery.backoff_base_seconds', 60));
        $cap = max($base, (int) config('sms.delivery.backoff_cap_seconds', 3600));

        return (int) min($cap, $base * (2 ** max(0, $attemptNumber - 1)));
    }

    /**
     * An opaque correlation reference for the provider. Deliberately NOT the client ULID and not
     * the recipient's identity — a provider-side log must not become a contact record.
     */
    private function reference(PersonnelSmsRecipient $recipient, int $attemptNumber): string
    {
        return 'SMS-'.substr(hash('sha256', $recipient->id.':'.$recipient->campaign_id), 0, 24).'-'.$attemptNumber;
    }

    private function recordOutcome(
        PersonnelSmsCampaign $campaign,
        PersonnelSmsRecipient $recipient,
        SmsProviderResultClass $resultClass,
        int $attemptNumber,
        bool $willRetry,
        bool $isFinalAttempt,
    ): void {
        // Context carries the campaign ULID, counts, the provider RESULT CLASS and the attempt
        // number only. Never the phone, never the client identity, never the message body.
        $context = [
            'campaign_ulid' => $campaign->ulid,
            'attempt_number' => $attemptNumber,
            'result_class' => $resultClass->value,
            'provider' => $this->provider->providerSlug(),
        ];

        if ($resultClass->isAccepted()) {
            $this->audit->record(
                AuditEvent::PersonnelSmsDeliverySucceeded,
                null,
                $campaign->merchant_id,
                $recipient->branch_id,
                $campaign,
                $context,
            );

            return;
        }

        if ($willRetry) {
            $this->audit->record(
                AuditEvent::PersonnelSmsDeliveryFailed,
                null,
                $campaign->merchant_id,
                $recipient->branch_id,
                $campaign,
                $context + ['retry_scheduled' => true],
            );

            return;
        }

        // Terminal failure. A transient class that ran out of attempts is a DEAD LETTER (high
        // severity — it usually means an operator condition such as an unauthorized key or an
        // exhausted balance); a permanent class is an ordinary per-recipient failure.
        $deadLettered = ! $resultClass->isPermanentFailure() && $isFinalAttempt;

        $this->audit->record(
            $deadLettered ? AuditEvent::PersonnelSmsDeliveryDeadLettered : AuditEvent::PersonnelSmsDeliveryFailed,
            null,
            $campaign->merchant_id,
            $recipient->branch_id,
            $campaign,
            $context + [
                'retry_scheduled' => false,
                'operator_condition' => $resultClass->isOperatorCondition(),
            ],
        );
    }
}
