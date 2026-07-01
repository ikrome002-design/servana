<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Domain\Payments\ValueObjects\DuplicateCheckOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Durable, concurrency-safe duplicate-reference detection (Plan §41, Gate C;
 * Phase 18A). The database is the authority: the first accepted reference reserves
 * the one partial-unique `result='unique'` slot per (merchant, method, normalized
 * reference); a later component with the same reference is recorded as durable
 * `duplicate_suspected` evidence pointing at the matched record. Never silently
 * accepts a duplicate; never edits the original reference; never leaks a SQLSTATE,
 * constraint name, or raw/normalized reference.
 *
 * Concurrency: the reservation INSERT runs inside a nested transaction (SAVEPOINT).
 * A unique-violation from a concurrent first-insert rolls back only the savepoint —
 * the outer recording transaction survives — and is deterministically re-classified
 * as a duplicate against the winning reservation.
 */
final class PaymentReferenceDuplicateChecker
{
    private const UNIQUE_VIOLATION = '23505';

    /** Must run inside the outer recording transaction, under the invoice row lock. */
    public function check(PaymentRecord $record): DuplicateCheckOutcome
    {
        $existing = $this->existingReservation($record);

        if ($existing !== null) {
            return $this->recordDuplicate($record, (int) $existing->payment_record_id);
        }

        try {
            $reservation = DB::transaction(fn (): PaymentReferenceCheck => $this->createCheck(
                $record,
                PaymentReferenceCheckResult::Unique,
                matchedPaymentRecordId: null,
            ));

            return DuplicateCheckOutcome::unique($reservation);
        } catch (QueryException $e) {
            if ($e->getCode() !== self::UNIQUE_VIOLATION) {
                throw $e;
            }

            // A concurrent transaction won the reservation; classify as a duplicate
            // against the winner (the savepoint rolled back; the outer txn is alive).
            $winner = $this->existingReservation($record);
            $matchedId = $winner !== null ? (int) $winner->payment_record_id : (int) $record->id;

            return $this->recordDuplicate($record, $matchedId);
        }
    }

    private function existingReservation(PaymentRecord $record): ?PaymentReferenceCheck
    {
        return PaymentReferenceCheck::query()
            ->where('merchant_id', $record->merchant_id)
            ->where('method', $record->method->value)
            ->where('reference_normalized', $record->reference_normalized)
            ->where('result', PaymentReferenceCheckResult::Unique->value)
            ->orderBy('id')
            ->first();
    }

    private function recordDuplicate(PaymentRecord $record, int $matchedRecordId): DuplicateCheckOutcome
    {
        $check = $this->createCheck($record, PaymentReferenceCheckResult::DuplicateSuspected, $matchedRecordId);

        /** @var PaymentRecord|null $matched */
        $matched = PaymentRecord::query()->whereKey($matchedRecordId)->first();

        return DuplicateCheckOutcome::duplicate($check, $matchedRecordId, $matched?->maskedReference());
    }

    private function createCheck(PaymentRecord $record, PaymentReferenceCheckResult $result, ?int $matchedPaymentRecordId): PaymentReferenceCheck
    {
        return PaymentReferenceCheck::create([
            'merchant_id' => $record->merchant_id,
            'branch_id' => $record->branch_id,
            'payment_record_id' => $record->id,
            'method' => $record->method,
            'reference_normalized' => $record->reference_normalized,
            'result' => $result,
            'matched_payment_record_id' => $matchedPaymentRecordId,
            'checked_at' => CarbonImmutable::now(),
        ]);
    }
}
