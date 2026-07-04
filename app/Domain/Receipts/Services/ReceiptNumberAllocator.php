<?php

declare(strict_types=1);

namespace App\Domain\Receipts\Services;

use App\Domain\Receipts\Models\ReceiptNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free per-merchant receipt-number allocator (Plan §13.15, §43; Phase 18B).
 *
 * MUST be called inside the receipt-issuance transaction. It serializes allocation on
 * the merchant's `receipt_number_sequences` row with `FOR UPDATE`, returns the current
 * `next_value`, then increments — never `MAX(receipt_number)+1`. A rolled-back issuance
 * consumes no number (the increment rolls back with the transaction). Concurrent
 * issuances for the same merchant block on the row lock and receive distinct
 * sequential values. Numbers are per merchant, gap-free on committed issuance, and
 * never reused.
 */
final class ReceiptNumberAllocator
{
    public const SCOPE_RECEIPT = 'receipt';

    /** Allocate the next receipt number for a merchant (inside the issuance txn). */
    public function allocate(int $merchantId): int
    {
        $this->assertInTransaction();

        // Ensure the row exists without racing on create (unique merchant_id+scope).
        ReceiptNumberSequence::query()->insertOrIgnore([
            'merchant_id' => $merchantId,
            'scope' => self::SCOPE_RECEIPT,
            'next_value' => 1,
            'prefix' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var ReceiptNumberSequence $sequence */
        $sequence = ReceiptNumberSequence::query()
            ->where('merchant_id', $merchantId)
            ->where('scope', self::SCOPE_RECEIPT)
            ->lockForUpdate()
            ->firstOrFail();

        $value = $sequence->next_value;
        $sequence->next_value = $value + 1;
        $sequence->save();

        return $value;
    }

    /** Guard: allocation must occur inside a transaction so rollback frees the number. */
    public function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Receipt numbers must be allocated inside an issuance transaction.');
        }
    }
}
