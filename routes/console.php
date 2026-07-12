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
