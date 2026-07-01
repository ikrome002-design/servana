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
 * Record a merchant-client payment group as a Finance MAKER exception (Plan §41,
 * §19.3; Phase 18A). Uses the same atomic composer as the Front Office path but
 * with the distinct `customer_payment.record_exception` capability and the
 * `customer_payment.recorded_exception` (high) audit event. The Finance user is the
 * MAKER and — by the maker/checker guard preserved for Phase 18B — can never be the
 * checker for the same group. This is NOT a broad Finance operational superuser
 * path: it grants no validation authority.
 */
final class RecordCustomerPaymentException
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
        $result = $this->composer->compose($invoice, $maker, $components, AuditEvent::CustomerPaymentRecordedException);

        $this->notify->dispatch($result);

        return $result;
    }
}
