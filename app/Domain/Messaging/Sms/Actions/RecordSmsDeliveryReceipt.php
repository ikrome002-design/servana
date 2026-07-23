<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsDeliveryAttemptStatus;
use App\Domain\Messaging\Sms\Enums\SmsProviderResultClass;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Models\SmsDeliveryAttempt;
use App\Domain\Messaging\Sms\Services\PersonnelSmsRecipientStateMachine;
use App\Domain\Messaging\Sms\Support\SmsProviderPayloadRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Apply a provider DELIVERY RECEIPT to a submitted recipient (Plan §64 "idempotent delivery
 * receipts"; Phase 21S).
 *
 * INTERNAL ONLY IN PHASE 21S. **No HTTP route reaches this action.** The Plan pins no SMS provider,
 * so no authenticated receipt-callback contract exists, and Plan §24.1 forbids shipping an
 * unverifiable provider webhook — an unauthenticated receipt endpoint would let anyone mark another
 * merchant's messages delivered or failed. Bringing a verified receipt channel online is
 * **REM-SMS-002**, which must close before Phase 25. Until then this action is driven only by the
 * fake provider in tests, which is what keeps the `delivered` state honest: Servana never claims a
 * message was delivered without evidence.
 *
 * IDEMPOTENT: only a recipient in `sent` is affected. A duplicate or out-of-order receipt for an
 * already-terminal recipient is a no-op, so a provider that retries its callback cannot flip a
 * settled outcome. A receipt arriving after the campaign itself has settled updates the recipient
 * row (evidence) but never reopens a terminal campaign — {@see FinalizeSmsCampaign} refuses.
 *
 * REDACTION: the provider's diagnostic goes through {@see SmsProviderPayloadRedactor} exactly as on
 * the send path, and the receipt is recorded as a further append-only attempt row.
 */
final class RecordSmsDeliveryReceipt
{
    public function __construct(
        private readonly PersonnelSmsRecipientStateMachine $state,
        private readonly SmsProviderPayloadRedactor $redactor,
        private readonly FinalizeSmsCampaign $finalize,
    ) {}

    public function handle(
        PersonnelSmsRecipient $recipient,
        SmsProviderResultClass $resultClass,
        ?string $providerCode = null,
        ?string $rawProviderMessage = null,
    ): bool {
        $applied = DB::transaction(function () use ($recipient, $resultClass, $providerCode, $rawProviderMessage): bool {
            /** @var PersonnelSmsRecipient $locked */
            $locked = PersonnelSmsRecipient::query()->lockForUpdate()->findOrFail($recipient->id);

            if ($locked->delivery_status !== PersonnelSmsRecipientDeliveryStatus::Sent) {
                return false; // duplicate, out-of-order, or never submitted — ignore
            }

            $next = $resultClass->isAccepted()
                ? PersonnelSmsRecipientDeliveryStatus::Delivered
                : PersonnelSmsRecipientDeliveryStatus::Failed;

            $attemptNumber = (int) SmsDeliveryAttempt::query()
                ->where('recipient_id', $locked->id)
                ->max('attempt_number') + 1;

            SmsDeliveryAttempt::query()->create([
                'recipient_id' => $locked->id,
                'attempt_number' => $attemptNumber,
                'provider' => 'receipt',
                'status' => $resultClass->isAccepted()
                    ? SmsDeliveryAttemptStatus::Accepted
                    : SmsDeliveryAttemptStatus::PermanentFailure,
                'result_class' => $resultClass,
                'provider_code' => $providerCode,
                'provider_message_redacted' => $this->redactor->redact($rawProviderMessage),
                'attempted_at' => Carbon::now(),
                'next_retry_at' => null,
            ]);

            $this->state->ensure($locked->delivery_status, $next);
            $locked->forceFill(['delivery_status' => $next])->save();

            return true;
        });

        if ($applied) {
            /** @var PersonnelSmsCampaign|null $campaign */
            $campaign = PersonnelSmsCampaign::query()->whereKey($recipient->campaign_id)->first();

            if ($campaign !== null) {
                $this->finalize->handle($campaign);
            }
        }

        return $applied;
    }
}
