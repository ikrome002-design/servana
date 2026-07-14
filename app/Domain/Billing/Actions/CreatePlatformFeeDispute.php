<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Exceptions\PlatformFeeDisputeException;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Models\User;

/**
 * Raise a platform-fee dispute (Plan §13.10 [Correction 3]; Phase 20E, Increment 5C; `(none) → open`).
 * The dispute targets a platform-fee ledger entry and/or a subscription invoice, at least one present
 * and each within the actor's tenant scope (a cross-tenant target is reported as not-found). A sanitised
 * reason is mandatory; an optional private evidence file (Phase 10F uploaded_files) may be attached. The
 * final permission key is reconciled in Increment 6; authority gating is enforced at the HTTP boundary.
 */
final class CreatePlatformFeeDispute
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(
        User $actor,
        int $merchantId,
        ?int $branchId,
        ?PlatformFeeLedgerEntry $ledgerEntry,
        ?SubscriptionInvoice $subscriptionInvoice,
        string $reason,
        ?int $evidenceFileId = null,
    ): PlatformFeeDispute {
        if ($ledgerEntry === null && $subscriptionInvoice === null) {
            throw PlatformFeeDisputeException::missingTarget();
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw PlatformFeeDisputeException::reasonRequired();
        }

        // Tenant scope: both targets must belong to the acting merchant (no cross-tenant disclosure).
        if ($ledgerEntry !== null && $ledgerEntry->merchant_id !== $merchantId) {
            throw PlatformFeeDisputeException::crossTenantTarget();
        }
        if ($subscriptionInvoice !== null && $subscriptionInvoice->merchant_id !== $merchantId) {
            throw PlatformFeeDisputeException::crossTenantTarget();
        }

        $dispute = PlatformFeeDispute::create([
            'merchant_id' => $merchantId,
            'branch_id' => $branchId,
            'platform_fee_ledger_entry_id' => $ledgerEntry?->id,
            'subscription_invoice_id' => $subscriptionInvoice?->id,
            'reason' => $reason,
            'status' => PlatformFeeDisputeStatus::Open->value,
            'assigned_reviewer' => null,
            'evidence_file_id' => $evidenceFileId,
            'resolution_note' => null,
            'created_by' => $actor->id,
            'resolved_by' => null,
            'resolved_at' => null,
        ]);

        $this->audit->record(AuditEvent::PlatformFeeDisputeCreated, $actor, $merchantId, $branchId, $dispute, [
            'dispute_id' => $dispute->ulid,
            'platform_fee_ledger_entry_id' => $ledgerEntry?->ulid,
            'subscription_invoice_id' => $subscriptionInvoice?->ulid,
            'has_evidence' => $evidenceFileId !== null,
        ]);

        return $dispute;
    }
}
