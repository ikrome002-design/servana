<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PreferredPersonnelFeeRuleStatus;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Billing\Services\PreferredPersonnelFeeRuleStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a not-yet-effective preferred-personnel fee rule (Plan §13.10, §47; Phase 20A). Only a
 * `draft` or `scheduled` rule may be cancelled (an `active` rule is superseded, never cancelled) —
 * the state machine rejects any other transition with 422. Platform-governed (MFA + fresh step-up).
 * Audits `preferred_personnel_fee_rule.cancelled`.
 */
final class CancelPreferredPersonnelFeeRule
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PreferredPersonnelFeeRuleStateMachine $stateMachine,
    ) {}

    public function handle(PreferredPersonnelFeeRule $rule, User $actor): PreferredPersonnelFeeRule
    {
        return DB::transaction(function () use ($rule, $actor): PreferredPersonnelFeeRule {
            $locked = PreferredPersonnelFeeRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            $this->stateMachine->ensure($locked->status, PreferredPersonnelFeeRuleStatus::Cancelled);

            $locked->status = PreferredPersonnelFeeRuleStatus::Cancelled;
            $locked->save();

            $this->audit->record(AuditEvent::PreferredPersonnelFeeRuleCancelled, $actor, null, null, $locked, [
                'rule_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
