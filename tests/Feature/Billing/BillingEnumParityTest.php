<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\BillingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('billing', 'billing-enum-parity');

/*
 | Canonical billing-mode / billing-interval parity (Plan §2.1.9, §13.9, §47; Phase 20A).
 | The single vocabulary must not drift. Increment 2 proves PHP enum ↔ PostgreSQL CHECK;
 | later increments extend the same guard to API validation, OpenAPI, generated TypeScript,
 | and screen options. No second billing-mode or interval vocabulary may exist.
 */

/**
 * The set of single-quoted literals allowed by a table's CHECK constraint (PostgreSQL
 * normalizes `IN (...)` to `= ANY (ARRAY[...])`; both render the values as quoted literals).
 *
 * @return list<string>
 */
function checkConstraintLiterals(string $constraint): array
{
    $row = DB::selectOne(
        'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
        [$constraint],
    );

    expect($row)->not->toBeNull("constraint {$constraint} must exist");

    preg_match_all("/'([^']+)'/", (string) $row->def, $matches);
    $values = array_values(array_unique($matches[1]));
    sort($values);

    return $values;
}

/** @return list<string> */
function sortedValues(array $values): array
{
    sort($values);

    return $values;
}

it('BillingMode PHP enum exactly matches the platform_billing_settings billing_mode CHECK', function (): void {
    expect(checkConstraintLiterals('platform_billing_settings_billing_mode_check'))
        ->toBe(sortedValues(BillingMode::values()));
});

it('BillingMode has exactly the three canonical values', function (): void {
    expect(BillingMode::values())->toBe([
        'fixed_amount',
        'percentage_on_merchant_client_invoice',
        'fixed_amount_plus_percentage_on_merchant_client_invoice',
    ]);
});

it('BillingInterval PHP enum exactly matches the subscription_plan_prices billing_interval CHECK', function (): void {
    expect(checkConstraintLiterals('subscription_plan_prices_billing_interval_check'))
        ->toBe(sortedValues(BillingInterval::values()));
});

it('BillingInterval has exactly the five canonical values', function (): void {
    expect(BillingInterval::values())->toBe([
        'weekly',
        'bi_weekly',
        'monthly',
        'quarterly',
        'annual',
    ]);
});

it('exposes fixed_amount as the default launch billing mode (§50)', function (): void {
    expect(BillingMode::default())->toBe(BillingMode::FixedAmount);
});
