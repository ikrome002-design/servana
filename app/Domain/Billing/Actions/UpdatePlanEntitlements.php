<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Models\PlanEntitlement;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Replace a plan's entitlement set (Plan §13.9, §20, §47; Phase 20A). Platform-governed. The
 * request supplies the full desired entitlement list; each `entitlement_key` is upserted
 * (enabled + optional limit) and keys absent from the payload are removed — a downgrade denies
 * NEW over-limit usage at the gate but never deletes merchant data (the substrate carries no
 * merchant rows). Audits `plan_entitlement.updated` once for the whole change.
 *
 * @phpstan-type EntitlementInput array{entitlement_key:string,enabled:bool,limit_int?:int|null}
 */
final class UpdatePlanEntitlements
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  list<array{entitlement_key:string,enabled:bool,limit_int?:int|null}>  $entitlements
     */
    public function handle(SubscriptionPlan $plan, array $entitlements, User $actor): SubscriptionPlan
    {
        return DB::transaction(function () use ($plan, $entitlements, $actor): SubscriptionPlan {
            SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            $keptKeys = [];
            foreach ($entitlements as $entitlement) {
                PlanEntitlement::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'entitlement_key' => $entitlement['entitlement_key']],
                    ['enabled' => $entitlement['enabled'], 'limit_int' => $entitlement['limit_int'] ?? null],
                );
                $keptKeys[] = $entitlement['entitlement_key'];
            }

            PlanEntitlement::query()
                ->where('plan_id', $plan->id)
                ->when($keptKeys !== [], fn ($query) => $query->whereNotIn('entitlement_key', $keptKeys))
                ->delete();

            $this->audit->record(AuditEvent::PlanEntitlementsUpdated, $actor, null, null, $plan, [
                'plan_id' => $plan->ulid,
                'entitlement_count' => count($keptKeys),
            ]);

            return $plan->refresh();
        });
    }
}
