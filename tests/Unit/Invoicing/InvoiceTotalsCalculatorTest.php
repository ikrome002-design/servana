<?php

declare(strict_types=1);

use App\Domain\Invoicing\Services\InvoiceTotalsCalculator;
use App\Enums\Currency;

uses()->group('invoicing', 'invoice-totals');

it('sums line totals into the subtotal and leaves preferred fee null when no item has one', function (): void {
    $totals = (new InvoiceTotalsCalculator)->compute(
        [
            ['line_total_minor' => 500000, 'preferred_personnel_fee_minor' => null],
            ['line_total_minor' => 250000, 'preferred_personnel_fee_minor' => null],
        ],
        taxMinor: 0,
        discountMinor: 0,
        currency: Currency::KES,
    );

    expect($totals->subtotalMinor)->toBe(750000)
        ->and($totals->preferredFeeTotalMinor)->toBeNull()
        ->and($totals->totalMinor)->toBe(750000);
});

it('adds the preferred fee, tax, and subtracts the discount using integer minor units', function (): void {
    $totals = (new InvoiceTotalsCalculator)->compute(
        [
            ['line_total_minor' => 500000, 'preferred_personnel_fee_minor' => 20000],
            ['line_total_minor' => 300000, 'preferred_personnel_fee_minor' => null],
        ],
        taxMinor: 16000,
        discountMinor: 5000,
        currency: Currency::KES,
    );

    // subtotal 800000 + preferred 20000 + tax 16000 - discount 5000 = 831000
    expect($totals->subtotalMinor)->toBe(800000)
        ->and($totals->preferredFeeTotalMinor)->toBe(20000)
        ->and($totals->taxMinor)->toBe(16000)
        ->and($totals->discountMinor)->toBe(5000)
        ->and($totals->totalMinor)->toBe(831000);
});

it('distinguishes a zero preferred fee from no preferred fee', function (): void {
    $totals = (new InvoiceTotalsCalculator)->compute(
        [['line_total_minor' => 100000, 'preferred_personnel_fee_minor' => 0]],
        taxMinor: 0,
        discountMinor: 0,
        currency: Currency::KES,
    );

    expect($totals->preferredFeeTotalMinor)->toBe(0)
        ->and($totals->totalMinor)->toBe(100000);
});

it('produces a coherent total matching the DB arithmetic CHECK', function (): void {
    $totals = (new InvoiceTotalsCalculator)->compute(
        [['line_total_minor' => 123456, 'preferred_personnel_fee_minor' => 7890]],
        taxMinor: 1000,
        discountMinor: 456,
        currency: Currency::KES,
    );

    $expected = $totals->subtotalMinor + ($totals->preferredFeeTotalMinor ?? 0) + $totals->taxMinor - $totals->discountMinor;
    expect($totals->totalMinor)->toBe($expected);
});
