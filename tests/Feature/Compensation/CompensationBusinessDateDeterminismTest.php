<?php

declare(strict_types=1);

use App\Domain\Compensation\Services\CompensationBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase23', 'determinism');

/*
 | Phase 23 — defect PH23-DET-001: the compensation suite depended on the WALL CLOCK.
 |
 | CLAUDE.md §1 and Plan §59: timestamps are UTC, but every business-DAY decision is made in
 | `Africa/Nairobi`. `app.timezone` is `UTC`, so Laravel's global `today()` helper is three hours
 | behind the business day. Between **21:00 and 23:59 UTC** the UTC calendar date is still yesterday
 | while Nairobi has already rolled over, so a fixture built from `today()` was evaluated by the
 | domain as YESTERDAY: `CompensationBusinessDate::isBackdated()` returned true for a plan the test
 | meant to take effect today, and approval then failed closed with
 | "A backdated compensation change requires an impact preview before approval."
 |
 | That made 22 compensation tests fail for three hours of every day — reproduced on pristine
 | `origin/main`, so it is PRE-EXISTING and not introduced by Phase 23. The product is CORRECT: every
 | production business-date decision routes through `CompensationBusinessDate`. The FIXTURES were
 | wrong, and now use the `businessToday()` helper.
 |
 | These cases pin the clock INSIDE the divergent window so the guard holds year-round instead of
 | depending on when the suite happens to run. No sleeps, no retries, no weakened assertion.
 */

/** 22:30 UTC on 2026-07-26 == 01:30 on 2026-07-27 in Africa/Nairobi — squarely inside the window. */
const DIVERGENT_INSTANT = '2026-07-26 22:30:00';

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reproduces the UTC/Nairobi divergence that broke the fixtures', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse(DIVERGENT_INSTANT, 'UTC'));
    $businessDate = app(CompensationBusinessDate::class);

    // The two clocks genuinely disagree at this instant — otherwise the rest proves nothing.
    expect(today()->toDateString())->toBe('2026-07-26')
        ->and($businessDate->today()->toDateString())->toBe('2026-07-27')
        ->and(businessToday()->toDateString())->toBe('2026-07-27');

    // The exact defect: a fixture dated with the UTC helper is treated as BACKDATED…
    expect($businessDate->isBackdated(today()->toDateString()))->toBeTrue();

    // …while the business-date helper the fixtures now use is correctly NOT backdated.
    expect($businessDate->isBackdated(businessToday()->toDateString()))->toBeFalse();
});

it('keeps businessToday() aligned with the domain business date outside the window too', function (): void {
    // Midday UTC — the two clocks agree on the calendar date, so the helper must be a no-op change.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-26 12:00:00', 'UTC'));
    $businessDate = app(CompensationBusinessDate::class);

    expect(today()->toDateString())->toBe('2026-07-26')
        ->and(businessToday()->toDateString())->toBe('2026-07-26')
        ->and($businessDate->today()->toDateString())->toBe('2026-07-26')
        ->and($businessDate->isBackdated(businessToday()->toDateString()))->toBeFalse();
});

// The END-TO-END proof (create → submit → approve at the divergent instant) lives in
// CompensationPlanApiTest.php, beside the plan-API helpers it needs.
