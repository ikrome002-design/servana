<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PreferredFeeCalculationBasis;
use App\Domain\Billing\Enums\PreferredFeeCalculationType;
use App\Domain\Billing\Enums\PreferredFeeScope;
use App\Domain\Billing\Enums\PreferredPersonnelFeeRuleStatus;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a preferred-personnel fee rule as a DRAFT (Plan §13.10, §47; Phase 20A). Platform-governed
 * (Super-Admin; MFA + fresh step-up). Value-shape and scope invariants are enforced by the Form
 * Request and the DB CHECKs; a draft does not yet participate in the overlap exclusion (only
 * active/scheduled do). Audits `preferred_personnel_fee_rule.created`.
 */
final class CreatePreferredPersonnelFeeRule
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{calculation_type:string,fixed_amount_minor?:int|null,percentage_basis_points?:int|null,currency?:string|null,calculation_basis:string,scope:string,service_id?:int|null,effective_from:string,effective_to?:string|null,change_reason:string}  $data
     */
    public function handle(array $data, User $actor): PreferredPersonnelFeeRule
    {
        return DB::transaction(function () use ($data, $actor): PreferredPersonnelFeeRule {
            $rule = PreferredPersonnelFeeRule::query()->create([
                'calculation_type' => PreferredFeeCalculationType::from($data['calculation_type']),
                'fixed_amount_minor' => $data['fixed_amount_minor'] ?? null,
                'percentage_basis_points' => $data['percentage_basis_points'] ?? null,
                'currency' => $data['currency'] ?? null,
                'calculation_basis' => PreferredFeeCalculationBasis::from($data['calculation_basis']),
                'scope' => PreferredFeeScope::from($data['scope']),
                'service_id' => $data['service_id'] ?? null,
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'status' => PreferredPersonnelFeeRuleStatus::Draft,
                'created_by' => $actor->id,
                'change_reason' => $data['change_reason'],
            ]);

            $this->audit->record(AuditEvent::PreferredPersonnelFeeRuleCreated, $actor, null, null, $rule, [
                'rule_id' => $rule->ulid,
                'scope' => $rule->scope->value,
                'calculation_type' => $rule->calculation_type->value,
            ]);

            return $rule;
        });
    }
}
