<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Services\FreePeriodOfferStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Approve a DRAFT free-period offer (Plan §53; Gate C6; §12; Phase 20C). Super-Administrator + MFA +
 * fresh step-up + mandatory reason are enforced at the route/request layer. Approval ALWAYS lands in
 * `scheduled` — the free-period machine has no direct `draft → active`; a same-day-effective offer
 * becomes `active` via the lifecycle scheduler. Records `approved_by`/`approved_at`; approved terms +
 * targets immutable thereafter. Row-locked; one transaction. Audit `free_period_offer.approved` (high).
 */
final class ApproveFreePeriodOffer
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly FreePeriodOfferStateMachine $machine,
    ) {}

    public function handle(FreePeriodOffer $offer, User $actor, string $reason): FreePeriodOffer
    {
        return DB::transaction(function () use ($offer, $actor, $reason): FreePeriodOffer {
            /** @var FreePeriodOffer $locked */
            $locked = FreePeriodOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, FreePeriodOfferStatus::Scheduled);

            $locked->status = FreePeriodOfferStatus::Scheduled;
            $locked->approved_by = $actor->id;
            $locked->approved_at = CarbonImmutable::now();
            $locked->change_reason = $reason;
            $locked->save();

            $this->audit->record(AuditEvent::FreePeriodOfferApproved, $actor, null, null, $locked, [
                'free_period_offer_id' => $locked->ulid,
                'to_status' => FreePeriodOfferStatus::Scheduled->value,
                'effective_from' => $locked->effective_from->toDateString(),
                'effective_to' => $locked->effective_to?->toDateString(),
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
