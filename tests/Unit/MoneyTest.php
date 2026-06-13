<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Support\CurrencyMismatchException;
use App\Support\Money;

it('constructs from integer minor units', function (): void {
    $money = Money::ofMinor(53500);

    expect($money->minorUnits)->toBe(53500)
        ->and($money->currency)->toBe(Currency::KES);
});

it('rejects float amounts at construction (no floats, ever)', function (): void {
    // @phpstan-ignore-next-line — intentional wrong type under strict_types.
    Money::ofMinor(5.5);
})->throws(TypeError::class);

it('rejects float factors in multiplication', function (): void {
    // @phpstan-ignore-next-line — intentional wrong type under strict_types.
    Money::ofMinor(100)->multiply(1.5);
})->throws(TypeError::class);

it('adds and subtracts amounts in the same currency', function (): void {
    $a = Money::ofMinor(50000);
    $b = Money::ofMinor(3500);

    expect($a->add($b)->minorUnits)->toBe(53500)
        ->and($a->subtract($b)->minorUnits)->toBe(46500);
});

it('multiplies by an integer factor', function (): void {
    expect(Money::ofMinor(70)->multiply(3)->minorUnits)->toBe(210);
});

it('throws on currency mismatch in arithmetic', function (): void {
    Money::ofMinor(100, Currency::KES)->add(Money::ofMinor(100, Currency::USD));
})->throws(CurrencyMismatchException::class);

it('throws on currency mismatch in comparison', function (): void {
    Money::ofMinor(100, Currency::KES)->greaterThan(Money::ofMinor(100, Currency::USD));
})->throws(CurrencyMismatchException::class);

it('compares amounts within the same currency', function (): void {
    $low = Money::ofMinor(100);
    $high = Money::ofMinor(200);

    expect($low->lessThan($high))->toBeTrue()
        ->and($high->greaterThan($low))->toBeTrue()
        ->and($low->lessThanOrEqual(Money::ofMinor(100)))->toBeTrue()
        ->and($high->greaterThanOrEqual(Money::ofMinor(200)))->toBeTrue()
        ->and($low->equals(Money::ofMinor(100)))->toBeTrue()
        ->and($low->equals($high))->toBeFalse();
});

it('treats different currencies as not equal without throwing', function (): void {
    expect(Money::ofMinor(100, Currency::KES)->equals(Money::ofMinor(100, Currency::USD)))->toBeFalse();
});

it('reports zero, positive and negative', function (): void {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::ofMinor(1)->isPositive())->toBeTrue()
        ->and(Money::ofMinor(-1)->isNegative())->toBeTrue();
});

it('formats for display using integers only', function (): void {
    expect(Money::ofMinor(153500)->format())->toBe('KES 1,535.00')
        ->and(Money::ofMinor(53500)->format())->toBe('KES 535.00')
        ->and(Money::ofMinor(-5099)->format())->toBe('KES -50.99')
        ->and(Money::ofMinor(0)->format())->toBe('KES 0.00');
});

it('exposes the API money shape (Plan §11.4)', function (): void {
    expect(Money::ofMinor(53500)->toArray())->toBe([
        'amount' => 53500,
        'currency' => 'KES',
        'formatted' => 'KES 535.00',
    ]);
});

it('detects integer overflow instead of silently becoming a float', function (): void {
    Money::ofMinor(PHP_INT_MAX)->add(Money::ofMinor(1));
})->throws(RangeException::class);
