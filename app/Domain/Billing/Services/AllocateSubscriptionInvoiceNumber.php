<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free per-merchant SUBSCRIPTION-invoice number allocator (Plan §13.15, §49; Gate B3; Phase 20B).
 *
 * MUST be called inside the issuance transaction. It serializes allocation on the merchant's
 * `invoice_number_sequences` row for the **independent** `subscription_invoice` scope with `FOR
 * UPDATE`, returns the current `next_value`, then increments — never `MAX(...)+1`. A rolled-back
 * issuance consumes no number (the increment rolls back). The `subscription_invoice` counter is
 * entirely separate from `merchant_client_invoice`, so subscription and merchant-client numbering
 * never collide. Format: `SUB-000123` (merchant-wide; no branch — subscriptions are merchant-level).
 */
final class AllocateSubscriptionInvoiceNumber
{
    public function allocate(int $merchantId): string
    {
        $this->assertInTransaction();

        // Create the counter row without racing (unique merchant_id + scope).
        InvoiceNumberSequence::query()->insertOrIgnore([
            'merchant_id' => $merchantId,
            'scope' => InvoiceNumberSequence::SCOPE_SUBSCRIPTION_INVOICE,
            'next_value' => 1,
            'prefix' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var InvoiceNumberSequence $sequence */
        $sequence = InvoiceNumberSequence::query()
            ->where('merchant_id', $merchantId)
            ->where('scope', InvoiceNumberSequence::SCOPE_SUBSCRIPTION_INVOICE)
            ->lockForUpdate()
            ->firstOrFail();

        $value = $sequence->next_value;
        $sequence->next_value = $value + 1;
        $sequence->save();

        $segment = $sequence->prefix ?? 'SUB';

        return sprintf('%s-%06d', $segment, $value);
    }

    /** Allocation must occur inside a transaction so a rollback frees the number. */
    public function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Subscription invoice numbers must be allocated inside the issuance transaction.');
        }
    }
}
