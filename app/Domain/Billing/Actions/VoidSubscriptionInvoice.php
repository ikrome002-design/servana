<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Services\SubscriptionInvoiceStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Void a subscription invoice (Plan §25.4; Phase 20B). draft/issued → void (terminal, pre-payment
 * supersession). Cancellation terminology is `void` ONLY (never `cancelled`). Row-locked + typed audit.
 * Requires an active tenant context.
 */
final class VoidSubscriptionInvoice
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SubscriptionInvoiceStateMachine $stateMachine,
    ) {}

    public function handle(SubscriptionInvoice $invoice, ?User $actor = null): SubscriptionInvoice
    {
        return DB::transaction(function () use ($invoice, $actor): SubscriptionInvoice {
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $this->stateMachine->ensure($locked->status, SubscriptionInvoiceStatus::Void);
            $locked->status = SubscriptionInvoiceStatus::Void;
            $locked->save();

            $this->audit->record(AuditEvent::SubscriptionInvoiceVoided, $actor, $locked->merchant_id, null, $locked, [
                'invoice_id' => $locked->ulid,
                'invoice_number' => $locked->invoice_number,
            ]);

            return $locked;
        });
    }
}
