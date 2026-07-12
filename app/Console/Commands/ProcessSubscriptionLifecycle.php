<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Actions\ApplyScheduledPlanChange;
use App\Domain\Billing\Actions\EnterReadOnlyGrace;
use App\Domain\Billing\Actions\ExpireSubscription;
use App\Domain\Billing\Actions\SuspendSubscriptionForBilling;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Domain\Billing\Queries\ResolveEffectivePlatformBillingSettings;
use App\Domain\Billing\Services\BillingIntervalCalculator;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared overdue/lifecycle escalation scheduler (Plan §22, §54, §67; Phase 20B). Scheduled DAILY in
 * `Africa/Nairobi` (routes/console.php; `withoutOverlapping` singleton + `onOneServer` leader-only) —
 * the established billing/integrity cadence (no finer cadence is pinned in the Plan).
 *
 * It ORCHESTRATES existing named actions only (no duplicated transition logic) and drives, from
 * authoritative state (never hardcoded day counts):
 *   - trial expiry (`trial_ends_at`): trialing → read_only_grace when grace is configured, else expired;
 *   - trial-grace expiry (`trial_ends_at` + effective `grace_days`): read_only_grace → suspended_billing;
 *   - due scheduled plan changes (`scheduled_plan_changes.effective_at`): applied exactly once.
 *
 * Invoice-`due_at`-driven overdue/suspension for ACTIVE paid subscriptions arrives with invoices in
 * Increment 4. Cross-tenant scanning uses scope-free `DB::table` reads bounded to {@see self::BATCH}
 * rows per category; each item is then processed under its own merchant tenant context with the
 * action's own row lock (bounded per-item transactions — never one unbounded transaction). Each
 * transition is idempotent (the state machine + re-selection guarantee exactly-once). A per-item
 * failure emits ONE bounded, redacted signal and the run exits non-zero; centralized paging/runbooks
 * remain Phase 25.
 */
final class ProcessSubscriptionLifecycle extends Command
{
    protected $signature = 'billing:process-subscription-lifecycle';

    protected $description = 'Drive trial expiry, read-only grace, billing suspension, and scheduled plan-change application (Phase 20B).';

    private const BATCH = 500;

    public function handle(
        TenantContext $context,
        ResolveEffectivePlatformBillingSettings $settings,
        EnterReadOnlyGrace $enterGrace,
        ExpireSubscription $expire,
        SuspendSubscriptionForBilling $suspend,
        ApplyScheduledPlanChange $applyChange,
    ): int {
        $now = CarbonImmutable::now(BillingIntervalCalculator::TIMEZONE);
        $current = $settings->current();
        $graceDays = $current === null ? 0 : $current->grace_days;
        $failures = 0;

        // 1. Trial expiry — trialing subscriptions past trial_ends_at.
        foreach ($this->scan('merchant_subscriptions', 'trialing', $now) as $row) {
            $failures += $this->process($context, (int) $row->merchant_id, function () use ($row, $enterGrace, $expire, $graceDays): void {
                $sub = MerchantSubscription::query()->whereKey($row->id)->first();
                if ($sub === null) {
                    return;
                }
                $graceDays > 0 ? $enterGrace->handle($sub) : $expire->handle($sub);
            });
        }

        // 2. Trial-grace expiry — read_only_grace past trial_ends_at + effective grace_days.
        $graceRows = DB::table('merchant_subscriptions')
            ->where('status', 'read_only_grace')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id', 'merchant_id', 'trial_ends_at']);
        foreach ($graceRows as $row) {
            $graceEnd = CarbonImmutable::parse($row->trial_ends_at)->addDays($graceDays);
            if ($now->lessThan($graceEnd)) {
                continue;
            }
            $failures += $this->process($context, (int) $row->merchant_id, function () use ($row, $suspend): void {
                $sub = MerchantSubscription::query()->whereKey($row->id)->first();
                if ($sub !== null) {
                    $suspend->handle($sub);
                }
            });
        }

        // 3. Apply due scheduled plan changes.
        $dueChanges = DB::table('scheduled_plan_changes')
            ->where('status', 'scheduled')
            ->whereDate('effective_at', '<=', $now->toDateString())
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id', 'merchant_id']);
        foreach ($dueChanges as $row) {
            $failures += $this->process($context, (int) $row->merchant_id, function () use ($row, $applyChange): void {
                $change = ScheduledPlanChange::query()->whereKey($row->id)->first();
                if ($change !== null) {
                    $applyChange->handle($change);
                }
            });
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Bounded, scope-free scan of subscriptions in `$status` whose `trial_ends_at` has elapsed.
     *
     * @return Collection<int, \stdClass>
     */
    private function scan(string $table, string $status, CarbonImmutable $now): Collection
    {
        return DB::table($table)
            ->where('status', $status)
            ->where('trial_ends_at', '<=', $now)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id', 'merchant_id']);
    }

    /**
     * Run one item under its merchant tenant context, isolating failures into a bounded redacted
     * signal so one bad row never aborts the batch. Returns 1 on failure, 0 on success.
     */
    private function process(TenantContext $context, int $merchantId, \Closure $work): int
    {
        try {
            $merchant = Merchant::query()->whereKey($merchantId)->first();
            if ($merchant === null) {
                return 0;
            }
            $context->bindForJob($merchant);
            $work();

            return 0;
        } catch (\Throwable $e) {
            // §71 — one bounded, redacted failure signal (no payload/context/ids beyond the class).
            Log::warning('billing.subscription_lifecycle.item_failed', [
                'merchant_id' => $merchantId,
                'exception' => $e::class,
            ]);

            return 1;
        } finally {
            $context->reset();
        }
    }
}
