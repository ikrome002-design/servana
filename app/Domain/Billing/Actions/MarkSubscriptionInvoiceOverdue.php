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
 * Mark a subscription invoice overdue (Plan §25.4, §54; Phase 20B). issued/partially_paid → overdue.
 * Row-locked, idempotent (a re-run on an already-overdue invoice is a no-op), typed audit. Requires
 * an active tenant context.
 */
final class MarkSubscriptionInvoiceOverdue
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SubscriptionInvoiceStateMachine $stateMachine,
    ) {}

    public function handle(SubscriptionInvoice $invoice, ?User $actor = null): SubscriptionInvoice
    {
        return DB::transaction(function () use ($invoice, $actor): SubscriptionInvoice {
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SubscriptionInvoiceStatus::Overdue) {
                return $locked; // idempotent
            }

            $this->stateMachine->ensure($locked->status, SubscriptionInvoiceStatus::Overdue);
            $locked->status = SubscriptionInvoiceStatus::Overdue;
            $locked->save();

            $this->audit->record(AuditEvent::SubscriptionInvoiceOverdue, $actor, $locked->merchant_id, null, $locked, [
                'invoice_id' => $locked->ulid,
                'invoice_number' => $locked->invoice_number,
            ]);

            return $locked;
        });
    }
}
