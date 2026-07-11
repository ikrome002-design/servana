<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PreferredPersonnelFeeRuleStatus;
use App\Domain\Billing\Exceptions\BillingOverlapException;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Billing\Services\PreferredPersonnelFeeRuleStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Approve/activate a preferred-personnel fee rule (Plan §13.10, §47; Phase 20A). Platform-governed
 * (MFA + fresh step-up). A `draft`/`scheduled` rule transitions to `active` (effective now) or
 * `scheduled` (future effective_from) via the state machine. A per-scope advisory lock serializes
 * concurrent activations; the DB `EXCLUDE` (over active + scheduled) is the final overlap arbiter
 * (violation → 409). Sets `approved_by`/`approved_at`. Audits `preferred_personnel_fee_rule.approved`.
 */
final class ApprovePreferredPersonnelFeeRule
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PreferredPersonnelFeeRuleStateMachine $stateMachine,
    ) {}

    public function handle(PreferredPersonnelFeeRule $rule, User $actor): PreferredPersonnelFeeRule
    {
        return DB::transaction(function () use ($rule, $actor): PreferredPersonnelFeeRule {
            $locked = PreferredPersonnelFeeRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            // Serialize activations for the same scope(+service) so a concurrent approve fails
            // friendly rather than only at the constraint.
            DB::select('SELECT pg_advisory_xact_lock(?)', [
                crc32($locked->scope->value.':'.($locked->service_id ?? 0)),
            ]);

            $target = $locked->effective_from->isAfter(CarbonImmutable::now('Africa/Nairobi')->startOfDay())
                ? PreferredPersonnelFeeRuleStatus::Scheduled
                : PreferredPersonnelFeeRuleStatus::Active;

            $this->stateMachine->ensure($locked->status, $target);

            $locked->status = $target;
            $locked->approved_by = $actor->id;
            $locked->approved_at = now();

            try {
                $locked->save();
            } catch (QueryException $e) {
                if ($e->getCode() === '23P01') {
                    throw BillingOverlapException::preferredFeeRule();
                }
                throw $e;
            }

            $this->audit->record(AuditEvent::PreferredPersonnelFeeRuleApproved, $actor, null, null, $locked, [
                'rule_id' => $locked->ulid,
                'status' => $locked->status->value,
            ]);

            return $locked;
        });
    }
}
