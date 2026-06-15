<?php

declare(strict_types=1);

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Models\MerchantBranch;

/**
 * Branch closure / archival protection (Scope §3.3 "Branch Closure and Archival
 * Protection"). A branch must not be archived/closed while live operational
 * records exist. Scope lists eight blocking conditions:
 *
 *   1 active queue entries          ─ Phase 16 (queue) — explicit stub
 *   2 in-progress service sessions  ─ Phase 16 (sessions) — explicit stub
 *   3 unpaid invoices               ─ Phase 17 (invoicing) — explicit stub
 *   4 pending payment validations   ─ Phase 18 (payments) — explicit stub
 *   5 unissued receipts (validated) ─ Phase 18 (receipts) — explicit stub
 *   6 pending appointment check-ins ─ Phase 16 (scheduling) — explicit stub
 *   7 unclosed branch day           ─ ENFORCED now (branch_day_records)
 *   8 unresolved cash-up discrepancy─ ENFORCED now (branch_cash_ups)
 *
 * Conditions whose source tables do not exist yet return false from a NAMED
 * method (never a silent skip) so the owning phase replaces that one method and
 * the blocker activates. Plus the platform-fee debt gate (Plan §10.2).
 */
final class BranchClosureGuard
{
    public function __construct(private readonly BranchDebtGate $debtGate) {}

    /**
     * Reasons the branch cannot be closed/archived right now. Empty = closable.
     *
     * @return list<string>
     */
    public function blockers(MerchantBranch $branch): array
    {
        $blockers = [];

        if ($this->hasUnclosedDay($branch)) {
            $blockers[] = 'unclosed_branch_day';
        }
        if ($this->hasUnresolvedCashUp($branch)) {
            $blockers[] = 'unresolved_cash_up_discrepancy';
        }
        if ($this->hasActiveQueueEntries($branch)) {
            $blockers[] = 'active_queue_entries';
        }
        if ($this->hasInProgressSessions($branch)) {
            $blockers[] = 'in_progress_sessions';
        }
        if ($this->hasUnpaidInvoices($branch)) {
            $blockers[] = 'unpaid_invoices';
        }
        if ($this->hasPendingPaymentValidations($branch)) {
            $blockers[] = 'pending_payment_validations';
        }
        if ($this->hasUnissuedReceipts($branch)) {
            $blockers[] = 'unissued_receipts';
        }
        if ($this->hasPendingAppointmentCheckIns($branch)) {
            $blockers[] = 'pending_appointment_check_ins';
        }
        if ($this->debtGate->hasOutstandingDebt($branch)) {
            $blockers[] = 'outstanding_platform_fee_debt';
        }

        return $blockers;
    }

    public function canClose(MerchantBranch $branch): bool
    {
        return $this->blockers($branch) === [];
    }

    // ── Enforced now ────────────────────────────────────────────────────────

    private function hasUnclosedDay(MerchantBranch $branch): bool
    {
        return $branch->dayRecords()
            ->whereIn('status', [
                BranchDayStatus::Open->value,
                BranchDayStatus::Paused->value,
                BranchDayStatus::Reopened->value,
            ])
            ->exists();
    }

    private function hasUnresolvedCashUp(MerchantBranch $branch): bool
    {
        return $branch->cashUps()
            ->where('discrepancy_amount', '!=', 0)
            ->whereIn('status', [CashUpStatus::Draft->value, CashUpStatus::Submitted->value])
            ->exists();
    }

    // ── Explicit stubs (owning phase replaces each method) ────────────────────

    /** Phase 16 (queue). */
    private function hasActiveQueueEntries(MerchantBranch $branch): bool
    {
        return false;
    }

    /** Phase 16 (service sessions). */
    private function hasInProgressSessions(MerchantBranch $branch): bool
    {
        return false;
    }

    /** Phase 17 (invoicing). */
    private function hasUnpaidInvoices(MerchantBranch $branch): bool
    {
        return false;
    }

    /** Phase 18 (payments). */
    private function hasPendingPaymentValidations(MerchantBranch $branch): bool
    {
        return false;
    }

    /** Phase 18 (receipts). */
    private function hasUnissuedReceipts(MerchantBranch $branch): bool
    {
        return false;
    }

    /** Phase 16 (scheduling). */
    private function hasPendingAppointmentCheckIns(MerchantBranch $branch): bool
    {
        return false;
    }
}
