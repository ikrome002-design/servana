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
