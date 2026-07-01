<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free per-merchant invoice-number allocator (Plan §13.15, §40; Phase 17).
 *
 * MUST be called inside the finalization transaction. It serializes allocation on
 * the merchant's `invoice_number_sequences` row with `FOR UPDATE`, returns the
 * current `next_value`, then increments — never `MAX(invoice_number)+1`. A
 * rolled-back finalization consumes no number (the increment rolls back with the
 * transaction). Concurrent finalizations for the same merchant block on the row
 * lock and therefore receive distinct sequential values.
 *
 * Number format (Scope — Branch Invoice and Receipt Numbering Rules): merchant-wide
 * unique with an optional branch prefix, e.g. `KIL-INV-000124`. The numeric segment
 * comes from the per-merchant counter (so numbers are unique merchant-wide across
 * branches); the branch prefix is the branch code; the middle segment is the
 * sequence `prefix` override or the literal `INV`.
 */
final class InvoiceNumberAllocator
{
    public function allocate(int $merchantId, string $branchCode): string
    {
        // Ensure the row exists without racing on create (unique merchant_id+scope).
        InvoiceNumberSequence::query()->insertOrIgnore([
            'merchant_id' => $merchantId,
            'scope' => InvoiceNumberSequence::SCOPE_MERCHANT_CLIENT_INVOICE,
            'next_value' => 1,
            'prefix' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var InvoiceNumberSequence $sequence */
        $sequence = InvoiceNumberSequence::query()
            ->where('merchant_id', $merchantId)
            ->where('scope', InvoiceNumberSequence::SCOPE_MERCHANT_CLIENT_INVOICE)
            ->lockForUpdate()
            ->firstOrFail();

        $value = $sequence->next_value;
        $sequence->next_value = $value + 1;
        $sequence->save();

        $segment = $sequence->prefix ?? 'INV';

        return sprintf('%s-%s-%06d', $branchCode, $segment, $value);
    }

    /** Guard: allocation must occur inside a transaction so rollback frees the number. */
    public function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Invoice numbers must be allocated inside a finalization transaction.');
        }
    }
}
