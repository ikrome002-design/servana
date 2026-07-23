<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Jobs;

use App\Domain\Messaging\Sms\Actions\DeliverSmsRecipient;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Tenancy\Jobs\TenantAwareJob;

/**
 * Deliver one SMS recipient through the provider adapter (Plan §64, §67; Phase 21S).
 *
 * A `TenantAwareJob` (Plan §8.3): SMS is tenant data, so the merchant id is captured at dispatch
 * and re-validated on run — a suspended or deactivated merchant fails closed with
 * `MissingTenantContext` rather than sending. The branch id is deliberately NOT bound: a campaign's
 * recipients may span several branches when a membership is active in more than one, and the job
 * must read all of them within the merchant.
 *
 * `$tries = 1` on purpose. Retrying is the DOMAIN's job, not the queue's:
 * {@see DeliverSmsRecipient} records every attempt in `sms_delivery_attempts`, applies the capped
 * backoff, decides transient-vs-permanent from the provider result class, and re-dispatches this
 * job with a delay when a retry is warranted. A queue-level retry would duplicate attempt rows,
 * skew `attempt_number` and bypass the backoff schedule.
 */
final class DeliverSmsRecipientJob extends TenantAwareJob
{
    public int $tries = 1;

    public function __construct(?int $tenantMerchantId, public readonly int $recipientId)
    {
        parent::__construct($tenantMerchantId, null);

        $this->onQueue((string) config('sms.delivery.queue', 'default'));
    }

    protected function handleWithinTenant(): void
    {
        $recipient = PersonnelSmsRecipient::query()->find($this->recipientId);

        if ($recipient === null) {
            return;
        }

        // The action is the authority on whether there is anything to do: it returns immediately
        // for a recipient that is no longer pending (already sent, suppressed, opted out or
        // terminal), which is what makes a duplicate dispatch harmless.
        app(DeliverSmsRecipient::class)->handle($recipient);
    }
}
