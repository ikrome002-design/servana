<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PreferredFeeCalculationBasis;
use App\Domain\Billing\Enums\PreferredFeeCalculationType;
use App\Domain\Billing\Enums\PreferredPersonnelFeeRuleStatus;
use App\Domain\Billing\Exceptions\BillingOverlapException;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Billing\Services\PreferredPersonnelFeeRuleStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Supersede an ACTIVE preferred-personnel fee rule with a new version (Plan §13.10, §47; Phase 20A).
 * Active monetary terms are immutable — a change NEVER edits the active row in place; the current
 * active rule transitions `active → superseded` and a NEW successor version is created (draft/
 * scheduled/active per its effective_from). Platform-governed (MFA + fresh step-up). Same scope as
 * the superseded rule. Audits `preferred_personnel_fee_rule.superseded`.
 */
final class SupersedePreferredPersonnelFeeRule
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PreferredPersonnelFeeRuleStateMachine $stateMachine,
    ) {}

    /**
     * @param  array{calculation_type:string,fixed_amount_minor?:int|null,percentage_basis_points?:int|null,currency?:string|null,calculation_basis:string,effective_from:string,effective_to?:string|null,change_reason:string}  $data
     */
    public function handle(PreferredPersonnelFeeRule $current, array $data, User $actor): PreferredPersonnelFeeRule
    {
        return DB::transaction(function () use ($current, $data, $actor): PreferredPersonnelFeeRule {
            $locked = PreferredPersonnelFeeRule::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();

            DB::select('SELECT pg_advisory_xact_lock(?)', [
                crc32($locked->scope->value.':'.($locked->service_id ?? 0)),
            ]);

            // Mark the current active rule superseded FIRST so the new version does not collide with
            // it under the active/scheduled exclusion.
            $this->stateMachine->ensure($locked->status, PreferredPersonnelFeeRuleStatus::Superseded);
            $locked->status = PreferredPersonnelFeeRuleStatus::Superseded;
            $locked->save();

            $target = CarbonImmutable::parse($data['effective_from'])->isAfter(CarbonImmutable::now('Africa/Nairobi')->startOfDay())
                ? PreferredPersonnelFeeRuleStatus::Scheduled
                : PreferredPersonnelFeeRuleStatus::Active;

            try {
                $successor = PreferredPersonnelFeeRule::query()->create([
                    'calculation_type' => PreferredFeeCalculationType::from($data['calculation_type']),
                    'fixed_amount_minor' => $data['fixed_amount_minor'] ?? null,
                    'percentage_basis_points' => $data['percentage_basis_points'] ?? null,
                    'currency' => $data['currency'] ?? null,
                    'calculation_basis' => PreferredFeeCalculationBasis::from($data['calculation_basis']),
                    'scope' => $locked->scope,
                    'service_id' => $locked->service_id,
                    'effective_from' => $data['effective_from'],
                    'effective_to' => $data['effective_to'] ?? null,
                    'status' => $target,
                    'created_by' => $actor->id,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'change_reason' => $data['change_reason'],
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23P01') {
                    throw BillingOverlapException::preferredFeeRule();
                }
                throw $e;
            }

            $this->audit->record(AuditEvent::PreferredPersonnelFeeRuleSuperseded, $actor, null, null, $successor, [
                'superseded_rule_id' => $locked->ulid,
                'successor_rule_id' => $successor->ulid,
            ]);

            return $successor;
        });
    }
}
