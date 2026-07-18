<?php

declare(strict_types=1);

use App\Domain\Compensation\Services\SalaryProrationCalculator;
use App\Domain\Compensation\ValueObjects\SalarySegment;
use Carbon\CarbonImmutable;

uses()->group('compensation', 'phase20g', 'phase20g-salary');

/**
 * G8 Actual/Actual calendar-day salary proration (Plan §60; product-owner decision). Pure integer
 * arithmetic; no database.
 */
function seg(string $key, int $salaryMinor, int $payableDays, int $denominator, string $start, int $planId = 1, string $planUlid = 'AAAA'): SalarySegment
{
    $startDate = CarbonImmutable::parse($start, 'Africa/Nairobi');

    return new SalarySegment(
        segmentKey: $key,
        compensationPlanId: $planId,
        compensationPlanUlid: $planUlid,
        salaryMinor: $salaryMinor,
        currency: 'KES',
        payableDays: $payableDays,
        denominator: $denominator,
        payableStart: $startDate,
        payableEnd: $startDate->addDays($payableDays - 1),
    );
}

$calc = new SalaryProrationCalculator;

it('accrues exactly one full monthly salary regardless of month length', function () use ($calc): void {
    foreach ([28, 29, 30, 31] as $days) {
        $result = $calc->allocate([seg("m{$days}", 5000000, $days, $days, '2026-02-01')]);
        expect(array_sum($result))->toBe(5000000);
        expect($result["m{$days}"])->toBe(5000000);
    }
});

it('accrues one full weekly salary for a full 7-day week', function () use ($calc): void {
    $result = $calc->allocate([seg('w', 700000, 7, 7, '2026-07-06')]);
    expect($result['w'])->toBe(700000);
});

it('prorates a partial monthly segment by calendar days', function () use ($calc): void {
    // 15 of 30 days at KES 30,000.00 => KES 15,000.00.
    $result = $calc->allocate([seg('p', 3000000, 15, 30, '2026-06-01')]);
    expect($result['p'])->toBe(1500000);
});

it('splits a mid-period plan change into two segments summing to the period total', function () use ($calc): void {
    // Plan A KES 30,000.00 days 1-15; Plan B KES 42,000.00 days 16-30; 30-day month.
    $result = $calc->allocate([
        seg('a', 3000000, 15, 30, '2026-06-01', 1, 'AAAA'),
        seg('b', 4200000, 15, 30, '2026-06-16', 2, 'BBBB'),
    ]);
    expect($result['a'])->toBe(1500000);
    expect($result['b'])->toBe(2100000);
    expect(array_sum($result))->toBe(3600000);
});

it('rounds the period total once and assigns the residual by largest remainder + ascending tie-break', function () use ($calc): void {
    // Two equal 1-day segments of KES 1,000.00 over a 30-day month: each exact 33.33; rounded total 66.67 -> 6667.
    $result = $calc->allocate([
        seg('early', 100000, 1, 30, '2026-06-01', 1, 'AAAA'),
        seg('late', 100000, 1, 30, '2026-06-15', 2, 'BBBB'),
    ]);
    expect(array_sum($result))->toBe(6667);
    // Equal remainders -> the earlier payable-start segment takes the residual minor unit.
    expect($result['early'])->toBe(3334);
    expect($result['late'])->toBe(3333);
});

it('never uses floating-point and always reconciles to the rounded total', function () use ($calc): void {
    // Three uneven segments in a 31-day month.
    $segments = [
        seg('s1', 5555500, 10, 31, '2026-07-01', 1, 'AAAA'),
        seg('s2', 4444400, 11, 31, '2026-07-11', 2, 'BBBB'),
        seg('s3', 3333300, 10, 31, '2026-07-22', 3, 'CCCC'),
    ];
    $result = $calc->allocate($segments);

    $exactNumerator = (5555500 * 10) + (4444400 * 11) + (3333300 * 10);
    $expectedTotal = intdiv(2 * $exactNumerator + 31, 62);
    expect(array_sum($result))->toBe($expectedTotal);
    foreach ($result as $amount) {
        expect($amount)->toBeInt();
    }
});

it('rejects mismatched denominators in one pay period', function () use ($calc): void {
    expect(fn () => $calc->allocate([
        seg('a', 100, 1, 30, '2026-06-01'),
        seg('b', 100, 1, 31, '2026-06-02'),
    ]))->toThrow(InvalidArgumentException::class);
});
