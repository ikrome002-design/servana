<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune expired idempotency records daily (Plan §24.4 retention; Phase R4).
// Bounded + safe: never deletes an active processing lock.
Schedule::command('idempotency:prune')->daily()->withoutOverlapping();

// Verify the append-only audit_logs hash chains daily (Plan §67 scheduler, §70
// verification, §71 failure signal; Phase 19). Singleton (withoutOverlapping) and
// leader-only (onOneServer) per the §1610 scheduler convention; no explicit sub-daily
// cadence is pinned in the Plan, so the established daily integrity cadence is used
// (matching idempotency:prune). A failing run exits non-zero and emits ONE bounded,
// redacted AuditChainVerificationFailed signal; centralized transport is Phase 25.
Schedule::command('audit:verify-chain')->daily()->withoutOverlapping()->onOneServer();

// Drive the Phase 20B subscription lifecycle daily in Africa/Nairobi (Plan §22, §54, §67): trial
// expiry → read-only grace / expiry, trial-grace expiry → billing suspension, and due scheduled
// plan-change application. Orchestrates existing named actions only; singleton (withoutOverlapping)
// + leader-only (onOneServer); per-item bounded transactions with row locks; idempotent; one bounded
// redacted failure signal per bad item. Invoice-due-driven overdue/suspension lands with Increment 4.
Schedule::command('billing:process-subscription-lifecycle')
    ->daily()
    ->timezone('Africa/Nairobi')
    ->withoutOverlapping()
    ->onOneServer();

// Drive the Phase 20C promotion / free-period-offer lifecycle daily in Africa/Nairobi (Plan §53, §67):
// activate due scheduled offers (scheduled → active once effective_from is reached) and expire due
// active offers (active → expired once effective_to is reached). Platform-scoped (no tenant context);
// singleton (withoutOverlapping) + leader-only (onOneServer); per-item row-locked bounded transactions;
// idempotent; one bounded redacted failure signal per bad item. Never touches existing subscription/
// invoice snapshots.
Schedule::command('billing:process-promotion-lifecycle')
    ->daily()
    ->timezone('Africa/Nairobi')
    ->withoutOverlapping()
    ->onOneServer();

// Accrue the most recent CLOSED monthly/weekly salary pay period daily in Africa/Nairobi (Plan §60,
// §67; Phase 20G). Orchestrates the AccrueSalaryForPayPeriod action only; per-staff bounded
// transactions with the subject row lock; idempotent per (plan, staff, pay-period segment);
// daily/hourly/per_shift fail closed (no approved attendance source). Singleton (withoutOverlapping)
// + leader-only (onOneServer); one bounded redacted failure signal per bad item. Commission is NOT
// scheduled — it is earned by the commission_handoff_events consumer at Finance validation.
Schedule::command('compensation:accrue-salary')
    ->daily()
    ->timezone('Africa/Nairobi')
    ->withoutOverlapping()
    ->onOneServer();
