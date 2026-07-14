<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Queries\ResolveEffectivePlatformFeeConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-resolution');

/*
 | Phase 20E effective-configuration resolution (Plan §13.10, §51). PostgreSQL 16.
 */

function pfConfigResolver(): ResolveEffectivePlatformFeeConfiguration
{
    return new ResolveEffectivePlatformFeeConfiguration;
}

it('resolves the active configuration whose window contains the date', function (): void {
    $config = PlatformFeeConfiguration::factory()->percentage()->active()->create([
        'currency' => 'KES',
        'effective_from' => today()->subDays(5),
        'effective_to' => null,
    ]);

    $resolved = pfConfigResolver()->require(BillingMode::PercentageOnMerchantClientInvoice, 'KES', today());

    expect($resolved->id)->toBe($config->id);
});

it('returns null (inert) for fixed_amount mode without touching configuration', function (): void {
    PlatformFeeConfiguration::factory()->percentage()->active()->create(['currency' => 'KES']);

    expect(pfConfigResolver()->find(BillingMode::FixedAmount, 'KES', today()))->toBeNull();
});

it('fails closed when no active percentage configuration exists', function (): void {
    pfConfigResolver()->require(BillingMode::PercentageOnMerchantClientInvoice, 'KES', today());
})->throws(PlatformFeeException::class);

it('does not resolve a superseded configuration', function (): void {
    PlatformFeeConfiguration::factory()->percentage()->superseded()->create([
        'currency' => 'KES',
        'effective_from' => today()->subDays(10),
        'effective_to' => null,
    ]);

    expect(pfConfigResolver()->find(BillingMode::PercentageOnMerchantClientInvoice, 'KES', today()))->toBeNull();
});

it('does not resolve a configuration for a different currency', function (): void {
    PlatformFeeConfiguration::factory()->percentage()->active()->create([
        'currency' => 'KES',
        'effective_from' => today()->subDay(),
        'effective_to' => null,
    ]);

    expect(pfConfigResolver()->find(BillingMode::PercentageOnMerchantClientInvoice, 'USD', today()))->toBeNull();
});

it('does not resolve a configuration whose window has not started', function (): void {
    PlatformFeeConfiguration::factory()->percentage()->active()->create([
        'currency' => 'KES',
        'effective_from' => today()->addDays(3),
        'effective_to' => null,
    ]);

    expect(pfConfigResolver()->find(BillingMode::PercentageOnMerchantClientInvoice, 'KES', today()))->toBeNull();
});
