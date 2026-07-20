<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\CommissionLedgerEntryType;
use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Exceptions\CompensationLedgerException;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Services\CommissionEarningResolver;
use App\Domain\Payments\Models\PaymentValidationEvent;
use Carbon\CarbonImmutable;

/**
 * Records the earned commission for one Finance validation event (Plan §61; Phase 20G). It creates
 * append-only `earned` rows from the resolver's server-derived computations — commission is earned
 * ONLY here, never at payment recording, session completion, or invoice finalization. Idempotent:
 * the DB unique (payment_validation_event_id, invoice_item_id, staff_profile_id, entry_type='earned')
 * is the authoritative guard; this action additionally skips already-earned (item, staff) pairs so a
 * replay is a clean no-op. Intended to run INSIDE the handoff consumer's per-event transaction so
 * earning and the `consumed_at` marker commit atomically; it opens no transaction of its own.
 *
 * @throws CompensationLedgerException on a config invariant
 */
final class EarnCommissionForValidationEvent
{
    public function __construct(
        private readonly CommissionEarningResolver $resolver,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return list<int> commission_ledger ids created by this call */
    public function handle(PaymentValidationEvent $event): array
    {
        $computations = $this->resolver->resolve($event);
        if ($computations === []) {
            return [];
        }

        $already = CommissionLedgerEntry::query()
            ->where('payment_validation_event_id', $event->id)
            ->where('entry_type', CommissionLedgerEntryType::Earned->value)
            ->get(['invoice_item_id', 'staff_profile_id'])
            ->map(static fn ($r): string => $r->invoice_item_id.':'.$r->staff_profile_id)
            ->all();

        $now = CarbonImmutable::now();
        $created = [];

        foreach ($computations as $c) {
            if (in_array($c->invoiceItemId.':'.$c->staffProfileId, $already, true)) {
                continue;
            }

            $row = CommissionLedgerEntry::create([
                'merchant_id' => $event->merchant_id,
                'branch_id' => $event->branch_id,
                'staff_profile_id' => $c->staffProfileId,
                'compensation_plan_id' => $c->compensationPlanId,
                'commission_rule_id' => $c->commissionRuleId,
                'service_session_id' => $c->serviceSessionId,
                'invoice_id' => $c->invoiceId,
                'invoice_item_id' => $c->invoiceItemId,
                'payment_record_id' => null,
                'payment_validation_event_id' => $event->id,
                'source_entry_id' => null,
                'entry_type' => CommissionLedgerEntryType::Earned->value,
                'reversal_reason' => null,
                'calculation_basis_minor' => $c->calculationBasisMinor,
                'rate_basis_points' => $c->rateBasisPoints,
                'fixed_rate_minor' => $c->fixedRateMinor,
                'amount_minor' => $c->amountMinor,
                'currency' => $c->currency,
                'earned_at' => $now,
                'status' => CommissionLedgerStatus::Earned->value,
                'created_by' => $event->checker_user_id,
            ]);

            $this->audit->record(
                AuditEvent::CompensationCommissionEarned,
                null,
                $event->merchant_id,
                $event->branch_id,
                $row,
                [
                    'commission_ledger_id' => $row->ulid,
                    'staff_profile_id' => $c->staffProfileUlid,
                    'compensation_plan_ulid' => $c->compensationPlanUlid,
                    'commission_rule_ulid' => $c->commissionRuleUlid,
                    'invoice_item_ulid' => $c->invoiceItemUlid,
                    'payment_validation_event_id' => $event->ulid,
                    'entry_type' => CommissionLedgerEntryType::Earned->value,
                    'calculation_basis' => $c->calculationBasis->value,
                    'calculation_basis_minor' => $c->calculationBasisMinor,
                    'rate_basis_points' => $c->rateBasisPoints,
                    'fixed_rate_minor' => $c->fixedRateMinor,
                    'eligible_validated_allocation_minor' => $c->eligibleValidatedAllocationMinor,
                    'amount_minor' => $c->amountMinor,
                    'currency' => $c->currency,
                ],
            );

            $created[] = $row->id;
        }

        return $created;
    }
}
