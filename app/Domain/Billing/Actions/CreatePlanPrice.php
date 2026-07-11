<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Exceptions\BillingOverlapException;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create an effective-dated plan price — the SOLE price source (Plan §13.9, §47; ADR-011;
 * Phase 20A). Platform-governed. Takes a `FOR UPDATE` lock on the plan row so a concurrent create
 * cannot race the overlap check; the DB `EXCLUDE USING gist` constraint is the final arbiter
 * (violation → 409). A future `effective_from` is a scheduled price and audits
 * `subscription_plan_price.scheduled`; otherwise `subscription_plan_price.created`.
 */
final class CreatePlanPrice
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{amount_minor:int,currency:string,billing_interval:string,effective_from:string,effective_to?:string|null}  $data
     */
    public function handle(SubscriptionPlan $plan, array $data, User $actor): SubscriptionPlanPrice
    {
        return DB::transaction(function () use ($plan, $data, $actor): SubscriptionPlanPrice {
            // Serialize concurrent creates for this plan; the EXCLUDE constraint remains authoritative.
            SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            $scheduled = CarbonImmutable::parse($data['effective_from'])->isAfter(CarbonImmutable::now('Africa/Nairobi')->startOfDay());

            try {
                $price = SubscriptionPlanPrice::query()->create([
                    'plan_id' => $plan->id,
                    'amount_minor' => $data['amount_minor'],
                    'currency' => $data['currency'],
                    'billing_interval' => BillingInterval::from($data['billing_interval']),
                    'effective_from' => $data['effective_from'],
                    'effective_to' => $data['effective_to'] ?? null,
                    'created_by' => $actor->id,
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23P01') {
                    throw BillingOverlapException::planPrice();
                }
                throw $e;
            }

            $event = $scheduled ? AuditEvent::SubscriptionPlanPriceScheduled : AuditEvent::SubscriptionPlanPriceCreated;
            $this->audit->record($event, $actor, null, null, $price, [
                'plan_id' => $plan->ulid,
                'price_id' => $price->ulid,
                'billing_interval' => $price->billing_interval->value,
                'currency' => $price->currency,
                'amount_minor' => $price->amount_minor,
            ]);

            return $price;
        });
    }
}
