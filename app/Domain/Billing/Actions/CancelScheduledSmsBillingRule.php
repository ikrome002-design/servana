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
 * Withdraw a SCHEDULED SMS pricing rule before it takes effect (COR-UI08-001 §9; Phase UI-08).
 *
 * Scheduling a future rule with no way to withdraw it would make an operator error permanent, so
 * this path exists — but it is deliberately narrow. An already-effective rule is permanent history
 * and can never be cancelled; `platform_sms_billing_rules_guard` refuses it at the database even if
 * every layer above were bypassed. The row is locked FOR UPDATE so two concurrent cancellations
 * cannot both succeed, and the effective-time check is re-evaluated inside the transaction so a
 * rule that becomes effective mid-request is not cancelled by a stale read.
 *
 * Audits `platform_sms_billing.rule_cancelled`.
 */
final class CancelScheduledSmsBillingRule
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(PlatformSmsBillingRule $rule, string $reason, User $actor): PlatformSmsBillingRule
    {
        return DB::transaction(function () use ($rule, $reason, $actor): PlatformSmsBillingRule {
            /** @var PlatformSmsBillingRule $locked */
            $locked = PlatformSmsBillingRule::query()
                ->whereKey($rule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isCancelled()) {
                throw SmsBillingRuleException::alreadyCancelled();
            }

            if (! $locked->effective_from->greaterThan(CarbonImmutable::now())) {
                throw SmsBillingRuleException::alreadyEffective();
            }

            $locked->forceFill([
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->record(AuditEvent::PlatformSmsBillingRuleCancelled, $actor, null, null, $locked, [
                'rule_id' => $locked->ulid,
                'effective_from' => $locked->effective_from->toIso8601String(),
            ]);

            return $locked->refresh();
        });
    }
}
