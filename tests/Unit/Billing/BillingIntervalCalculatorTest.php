<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Services\BillingIntervalCalculator;
use Carbon\CarbonImmutable;

uses()->group('billing', 'phase20b-date-math');

function nairobi(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, BillingIntervalCalculator::TIMEZONE)->startOfDay();
}

function calc(): BillingIntervalCalculator
{
    return new BillingIntervalCalculator;
}

it('adds 7 days for weekly', function (): void {
    expect(calc()->nextBoundary(nairobi('2026-03-01'), BillingInterval::Weekly)->toDateString())
        ->toBe('2026-03-08');
});

it('adds 14 days for bi_weekly', function (): void {
    expect(calc()->nextBoundary(nairobi('2026-03-01'), BillingInterval::BiWeekly)->toDateString())
        ->toBe('2026-03-15');
});

it('adds one calendar month with end-of-month clamp (Jan 31 -> Feb 28)', function (): void {
    expect(calc()->nextBoundary(nairobi('2026-01-31'), BillingInterval::Monthly)->toDateString())
        ->toBe('2026-02-28');
});

it('adds one calendar month clamping to Feb 29 in a leap year (Jan 31 -> Feb 29)', function (): void {
    expect(calc()->nextBoundary(nairobi('2028-01-31'), BillingInterval::Monthly)->toDateString())
        ->toBe('2028-02-29');
});

it('preserves the anchor day across a clamped month (Feb 28 with anchor 31 -> Mar 31)', function (): void {
    expect(calc()->nextBoundary(nairobi('2026-02-28'), BillingInterval::Monthly, 31)->toDateString())
        ->toBe('2026-03-31');
});

it('preserves the anchor across a full non-leap year without drift', function (): void {
    $anchor = 31;
    $dates = [];
    $cursor = nairobi('2026-01-31');
    for ($i = 0; $i < 12; $i++) {
        $cursor = calc()->nextBoundary($cursor, BillingInterval::Monthly, $anchor);
        $dates[] = $cursor->toDateString();
    }

    expect($dates)->toBe([
        '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31',
        '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31',
    ]);
});

it('adds three calendar months for quarterly with clamp (Nov 30 -> Feb 28)', function (): void {
    expect(calc()->nextBoundary(nairobi('2026-11-30'), BillingInterval::Quarterly)->toDateString())
        ->toBe('2027-02-28');
});

it('crosses the year boundary for quarterly (Dec 15 -> Mar 15)', function (): void {
    expect(calc()->nextBoundary(nairobi('2026-12-15'), BillingInterval::Quarterly)->toDateString())
        ->toBe('2027-03-15');
});

it('adds one year for annual (Mar 01 -> Mar 01)', function (): void {
    expect(calc()->nextBoundary(nairobi('2026-03-01'), BillingInterval::Annual)->toDateString())
        ->toBe('2027-03-01');
});

it('clamps Feb 29 to Feb 28 for annual into a non-leap year', function (): void {
    expect(calc()->nextBoundary(nairobi('2028-02-29'), BillingInterval::Annual)->toDateString())
        ->toBe('2029-02-28');
});

it('lands on Feb 29 for annual into a leap year when the anchor is 29', function (): void {
    // 2027-02-28 with anchor day 29 → next year 2028 is leap → Feb 29.
    expect(calc()->nextBoundary(nairobi('2027-02-28'), BillingInterval::Annual, 29)->toDateString())
        ->toBe('2028-02-29');
});

it('computes trial end as anchor + trial days in Africa/Nairobi', function (): void {
    expect(calc()->trialEnd(nairobi('2026-03-01'), 14)->toDateString())->toBe('2026-03-15');
});

it('produces the same calendar date regardless of the source timezone (no DST drift)', function (): void {
    $utc = CarbonImmutable::parse('2026-03-31 23:30:00', 'UTC');
    // 23:30 UTC on Mar 31 is 02:30 Apr 1 in Nairobi (UTC+3, no DST).
    $result = calc()->nextBoundary($utc, BillingInterval::Weekly);

    expect($result->timezone->getName())->toBe(BillingIntervalCalculator::TIMEZONE)
        ->and($result->toDateString())->toBe('2026-04-08');
});

it('handles every interval from a stable anchor', function (): void {
    $from = nairobi('2026-06-15');
    expect(calc()->nextBoundary($from, BillingInterval::Weekly)->toDateString())->toBe('2026-06-22')
        ->and(calc()->nextBoundary($from, BillingInterval::BiWeekly)->toDateString())->toBe('2026-06-29')
        ->and(calc()->nextBoundary($from, BillingInterval::Monthly)->toDateString())->toBe('2026-07-15')
        ->and(calc()->nextBoundary($from, BillingInterval::Quarterly)->toDateString())->toBe('2026-09-15')
        ->and(calc()->nextBoundary($from, BillingInterval::Annual)->toDateString())->toBe('2027-06-15');
});
