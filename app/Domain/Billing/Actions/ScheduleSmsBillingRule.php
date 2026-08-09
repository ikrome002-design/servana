<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Exceptions\SmsBillingRuleException;
use App\Domain\Billing\Models\PlatformSmsBillingRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Schedule a new effective-dated SMS pricing rule (COR-UI08-001 §9; Phase UI-08).
 *
 * Append-only: a prior version is never mutated, and an already-effective rule is permanent
 * history. Overlap is prevented structurally by `UNIQUE(effective_from)`; this action converts
 * that collision into the domain's 422 rather than letting a driver exception escape.
 *
 * Backdating is refused. It could not rewrite a charge — `sms_billing_entries` is trigger-frozen —
 * but it would make the recorded pricing history untruthful, which is worth refusing outright.
 *
 * Platform-governed: `platform.billing_settings.update`, MFA and a fresh `billing_configuration`
 * step-up are enforced on the route. Audits `platform_sms_billing.rule_scheduled`.
 */
final class ScheduleSmsBillingRule
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{
     *     unit_cost_minor:int,
     *     effective_from:string,
     *     reason:string,
     *     tax_basis_points?:int|null,
     *     usage_warning_threshold_units?:int|null,
     *     usage_anomaly_threshold_basis_points?:int|null
     * }  $data
     */
    public function handle(array $data, User $actor): PlatformSmsBillingRule
    {
        $effectiveFrom = CarbonImmutable::parse($data['effective_from']);

        // A one-minute tolerance absorbs request latency between validation and this check;
        // anything earlier is a deliberate backdate.
        if ($effectiveFrom->isBefore(CarbonImmutable::now()->subMinute())) {
            throw SmsBillingRuleException::backdated();
        }

        return DB::transaction(function () use ($data, $actor, $effectiveFrom): PlatformSmsBillingRule {
            $collides = PlatformSmsBillingRule::query()
                ->where('effective_from', $effectiveFrom)
                ->exists();

            if ($collides) {
                throw SmsBillingRuleException::overlappingEffectiveInstant();
            }

            $rule = PlatformSmsBillingRule::query()->create([
                'unit_cost_minor' => $data['unit_cost_minor'],
                'tax_basis_points' => $data['tax_basis_points'] ?? null,
                'usage_warning_threshold_units' => $data['usage_warning_threshold_units'] ?? null,
                'usage_anomaly_threshold_basis_points' => $data['usage_anomaly_threshold_basis_points'] ?? null,
                'effective_from' => $effectiveFrom,
                'reason' => $data['reason'],
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(AuditEvent::PlatformSmsBillingRuleScheduled, $actor, null, null, $rule, [
                'rule_id' => $rule->ulid,
                'unit_cost_minor' => $rule->unit_cost_minor,
                'tax_basis_points' => $rule->tax_basis_points,
                'effective_from' => $rule->effective_from->toIso8601String(),
            ]);

            return $rule;
        });
    }
}
