<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\PlatformFeeDispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Percentage platform-fee dispute payload — the merchant/Finance masked read (Plan §13.10 [Correction 3];
 * Phase 20E). Exposes the dispute ULID, the source public references (ledger entry / subscription
 * invoice), the sanitized reason, status, reviewer/resolver public identity, resolution note, evidence
 * presence (metadata only — never the private evidence content), timestamps, and capability flags.
 * NEVER exposes internal ids or private evidence content.
 *
 * @mixin PlatformFeeDispute
 */
final class PlatformFeeDisputeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'platform_fee_ledger_entry_id' => $this->ledgerEntry?->ulid,
            'subscription_invoice_id' => $this->subscriptionInvoice?->ulid,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'assigned_reviewer' => $this->assignedReviewer?->ulid,
            'resolution_note' => $this->resolution_note,
            'has_evidence' => $this->evidence_file_id !== null,
            'created_by' => $this->createdBy?->ulid,
            'resolved_by' => $this->resolvedBy?->ulid,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'capabilities' => [
                'reviewable' => $this->status->value === 'open',
                'resolvable' => $this->status->value === 'under_review',
                'rejectable' => in_array($this->status->value, ['open', 'under_review'], true),
            ],
        ];
    }
}
