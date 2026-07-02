<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\RecordCustomerPaymentException;
use App\Domain\Payments\Actions\RecordCustomerPaymentGroup;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Exceptions\PaymentRecordingException;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\ValueObjects\PaymentComponentInput;
use App\Domain\Payments\ValueObjects\PaymentRecordingResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The transactional heart of merchant-client payment recording (Plan §41; Phase
 * 18A). Shared by {@see RecordCustomerPaymentGroup}
 * (Front Office maker) and {@see RecordCustomerPaymentException}
 * (Finance maker-exception) — only the maker's permission gate and the audit event
 * differ.
 *
 * In ONE transaction it locks the invoice, enforces the recordable state (Gate A),
 * derives the group total (Gate B — Σ concrete components, single currency), rejects
 * overpayment against `validated_balance − active_pending` under the lock, creates
 * the group (recorded) + components (pending_validation) + one invoice-level
 * allocation each, runs the durable duplicate check (Gate C) on reference-bearing
 * components, advances a clean group to `pending_validation`, and records ONE safe
 * audit event. A suspected duplicate leaves the group HELD at `recorded` and is
 * returned (not thrown) so the caller can emit a `409` that idempotent replay caches.
 * Any thrown failure rolls back everything and writes no success event.
 */
final class PaymentRecordingComposer
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly PaymentGroupTotalsValidator $totals,
        private readonly PaymentMethodReferenceValidator $methodReference,
        private readonly PaymentReferenceNormalizer $normalizer,
        private readonly PaymentReferenceDuplicateChecker $duplicates,
        private readonly PaymentPendingBalanceCalculator $balance,
        private readonly PaymentRecordingGroupStateMachine $machine,
    ) {}

    /**
     * @param  list<PaymentComponentInput>  $components
     */
    public function compose(Invoice $invoice, User $maker, array $components, AuditEvent $recordedEvent): PaymentRecordingResult
    {
        // Period openness is enforced before the write (→ 423 financial_period_locked).
        $this->periodGuard->ensureOpen($invoice->merchant_id, $invoice->branch_id);

        return DB::transaction(function () use ($invoice, $maker, $components, $recordedEvent): PaymentRecordingResult {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $locked->load('client');

            // Gate A — only an issued / partially-paid invoice is recordable.
            if (! in_array($locked->status, InvoiceStatus::payableStatuses(), true)) {
                throw PaymentRecordingException::invoiceNotRecordable();
            }

            // Gate B — derive the group total (Σ concrete components, single currency).
            $groupTotal = $this->totals->validateAndTotal($components, $locked->currency);

            // Capacity — reject collective overpayment under the invoice row lock.
            $balanceBefore = $this->balance->validatedBalanceMinor($locked);
            $pendingBefore = $this->balance->activePendingTotalMinor($locked);
            $available = $balanceBefore - $pendingBefore;
            if ($groupTotal > $available) {
                throw PaymentRecordingException::overpayment();
            }

            $group = PaymentRecordingGroup::create([
                'merchant_id' => $locked->merchant_id,
                'branch_id' => $locked->branch_id,
                'invoice_id' => $locked->id,
                'maker_user_id' => $maker->id,
                'total_amount_minor' => $groupTotal,
                'currency' => $locked->currency,
                'status' => PaymentRecordingGroupStatus::Recorded,
                'recorded_at' => CarbonImmutable::now(),
            ]);

            $held = false;
            $duplicateMeta = [];
            $methods = [];

            foreach ($components as $component) {
                $this->methodReference->validate($component->method, $component->rawReference);

                $record = PaymentRecord::create([
                    'merchant_id' => $locked->merchant_id,
                    'branch_id' => $locked->branch_id,
                    'invoice_id' => $locked->id,
                    'payment_recording_group_id' => $group->id,
                    'recorded_by' => $maker->id,
                    'maker_user_id' => $maker->id,
                    // The invoice client is the payer — derived, never from the body.
                    'payer_client_id' => $locked->client_id,
                    'method' => $component->method,
                    'amount_minor' => $component->amountMinor,
                    'currency' => $locked->currency,
                    'reference_normalized' => $this->normalizer->normalize($component->method, $component->rawReference),
                    'reference_display_encrypted' => $this->normalizer->display($component->rawReference),
                    'paid_at' => $component->paidAt,
                    'status' => PaymentRecordStatus::PendingValidation,
                ]);

                PaymentAllocation::create([
                    'merchant_id' => $locked->merchant_id,
                    'branch_id' => $locked->branch_id,
                    'payment_record_id' => $record->id,
                    'invoice_id' => $locked->id,
                    'invoice_item_id' => null,
                    'amount_minor' => $record->amount_minor,
                ]);

                $methods[] = $component->method->value;

                if ($component->method->runsDuplicateCheck()) {
                    $outcome = $this->duplicates->check($record);
                    if ($outcome->isDuplicate && ! $held) {
                        $held = true;
                        $duplicateMeta = [
                            'group_id' => $group->ulid,
                            'method' => $component->method->value,
                            'masked_reference' => $outcome->maskedReference,
                        ];
                    }
                }
            }

            $availableAfter = $available - $groupTotal;

            if ($held) {
                // Held for Finance duplicate review — stays `recorded`, no success event.
                $this->audit->record(
                    AuditEvent::CustomerPaymentDuplicateSuspected,
                    $maker,
                    $locked->merchant_id,
                    $locked->branch_id,
                    $group,
                    $this->context($group, $locked, $methods, $balanceBefore, $pendingBefore, $availableAfter, $duplicateMeta['masked_reference'] ?? null),
                );
            } else {
                $this->machine->ensurePhase18a(PaymentRecordingGroupStatus::Recorded, PaymentRecordingGroupStatus::PendingValidation);
                $group->status = PaymentRecordingGroupStatus::PendingValidation;
                $group->submitted_for_validation_at = now();
                $group->save();

                $this->audit->record(
                    $recordedEvent,
                    $maker,
                    $locked->merchant_id,
                    $locked->branch_id,
                    $group,
                    $this->context($group, $locked, $methods, $balanceBefore, $pendingBefore, $availableAfter, null),
                );
            }

            return new PaymentRecordingResult(
                $group->load(['records', 'records.allocations']),
                $held,
                $held ? $duplicateMeta : [],
                $balanceBefore,
                $pendingBefore,
                $availableAfter,
            );
        });
    }

    /**
     * Safe, masked audit context — never a full/normalized reference, encrypted
     * value, full client contact, raw idempotency key, or sequential id.
     *
     * @param  list<string>  $methods
     * @return array<string, mixed>
     */
    private function context(
        PaymentRecordingGroup $group,
        Invoice $invoice,
        array $methods,
        int $balanceBefore,
        int $pendingBefore,
        int $availableAfter,
        ?string $maskedReference,
    ): array {
        $context = [
            'group_id' => $group->ulid,
            'invoice_id' => $invoice->ulid,
            'invoice_number' => $invoice->invoice_number,
            'client_id' => $invoice->client?->ulid,
            'component_methods' => $methods,
            'component_count' => count($methods),
            'total_amount_minor' => $group->total_amount_minor,
            'currency' => $group->currency,
            'balance_before_minor' => $balanceBefore,
            'pending_before_minor' => $pendingBefore,
            'available_after_minor' => $availableAfter,
            'new_state' => $group->status->value,
        ];

        if ($maskedReference !== null) {
            $context['masked_reference'] = $maskedReference;
        }

        return $context;
    }
}
