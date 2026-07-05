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
