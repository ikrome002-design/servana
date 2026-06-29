<?php

declare(strict_types=1);

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use Carbon\CarbonImmutable;

/**
 * Branch closure / archival protection (Scope §3.3 "Branch Closure and Archival
 * Protection"). A branch must not be archived/closed while live operational
 * records exist. Scope lists eight blocking conditions:
 *
 *   1 active queue entries          ─ ENFORCED (Phase 16B; queue_entries)
 *   2 in-progress service sessions  ─ Phase 16C (sessions) — explicit stub
 *   3 unpaid invoices               ─ Phase 17 (invoicing) — explicit stub
 *   4 pending payment validations   ─ Phase 18 (payments) — explicit stub
 *   5 unissued receipts (validated) ─ Phase 18 (receipts) — explicit stub
 *   6 active appointments           ─ ENFORCED (Phase 16A; appointments)
 *   7 unclosed branch day           ─ ENFORCED now (branch_day_records)
 *   8 unresolved cash-up discrepancy─ ENFORCED now (branch_cash_ups)
 *
 * Archival blocks while ANY active appointment (scheduled/confirmed/checked_in)
 * exists; day close ({@see dayCloseBlockers()}) blocks while a SAME-DAY active
 * appointment exists (Plan §25.2 — the appointment day-close guard flipped on by
 * Phase 16A). Terminal cancelled/cancelled_with_reason/no_show appointments never
 * block.
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
        if ($this->hasActiveAppointments($branch)) {
            $blockers[] = 'active_appointments';
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

    /**
     * Blockers preventing a branch business-day CLOSE for the given business date
     * (Plan §25.2; Phase 16A appointment guard). Same-day active appointments must
     * be completed or cancelled first; terminal appointments never block. Empty =
     * closable (subject to the other day-close checks the Branch Day workflow runs).
     *
     * @return list<string>
     */
    public function dayCloseBlockers(MerchantBranch $branch, string $businessDate): array
    {
        $blockers = [];

        if ($this->hasActiveAppointmentsOn($branch, $businessDate)) {
            $blockers[] = 'active_appointments';
        }
        if ($this->hasActiveQueueEntries($branch)) {
            $blockers[] = 'active_queue_entries';
        }

        return $blockers;
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

    /**
     * Phase 16B (queue): any active queue entry (waiting/assigned/called/in_service/
     * transferred) blocks archival and day close. Terminal completed/cancelled/
     * no_show never block.
     */
    private function hasActiveQueueEntries(MerchantBranch $branch): bool
    {
        return QueueEntry::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', QueueEntry::statusValues(QueueEntryStatus::activeStatuses()))
            ->exists();
    }

    /** Phase 16C (service sessions). */
    private function hasInProgressSessions(MerchantBranch $branch): bool
    {
        return false;
    }

    /** Phase 16A (appointments): any active (reserving) appointment blocks archival. */
    private function hasActiveAppointments(MerchantBranch $branch): bool
    {
        return $branch->appointments()
            ->whereIn('status', $this->reservingStatusValues())
            ->exists();
    }

    /**
     * Phase 16A (appointments): any same-day active appointment (its `Africa/Nairobi`
     * business date == $businessDate) blocks a day close.
     */
    private function hasActiveAppointmentsOn(MerchantBranch $branch, string $businessDate): bool
    {
        $tz = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');
        $dayStart = CarbonImmutable::parse($businessDate, $tz)->startOfDay();
        $dayEnd = $dayStart->addDay();

        return $branch->appointments()
            ->whereIn('status', $this->reservingStatusValues())
            ->where('starts_at', '>=', $dayStart)
            ->where('starts_at', '<', $dayEnd)
            ->exists();
    }

    /** @return list<string> */
    private function reservingStatusValues(): array
    {
        return array_map(
            static fn (AppointmentStatus $s): string => $s->value,
            AppointmentStatus::reservingStatuses(),
        );
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
}
