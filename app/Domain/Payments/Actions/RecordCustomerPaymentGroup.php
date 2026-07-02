<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Services\NotifyFinanceOfRecordedPayment;
use App\Domain\Payments\Services\PaymentRecordingComposer;
use App\Domain\Payments\ValueObjects\PaymentComponentInput;
use App\Domain\Payments\ValueObjects\PaymentRecordingResult;
use App\Models\User;

/**
 * Record a merchant-client payment group as the Front Office maker (Plan §41;
 * Phase 18A). Delegates the atomic recording to {@see PaymentRecordingComposer}
 * (audit event `customer_payment.recorded`) and, AFTER commit, notifies Finance.
 * The route is `financial_mutation` (idempotency-keyed); the maker, tenant, branch,
 * totals, status, and allocations are all derived server-side.
 */
final class RecordCustomerPaymentGroup
{
    public function __construct(
        private readonly PaymentRecordingComposer $composer,
        private readonly NotifyFinanceOfRecordedPayment $notify,
    ) {}

    /**
     * @param  list<PaymentComponentInput>  $components
     */
    public function handle(Invoice $invoice, User $maker, array $components): PaymentRecordingResult
    {
        $result = $this->composer->compose($invoice, $maker, $components, AuditEvent::CustomerPaymentRecorded);

        $this->notify->dispatch($result);

        return $result;
    }
}
