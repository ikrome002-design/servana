<?php

declare(strict_types=1);

use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Billing\Queries\ResolveEffectivePreferredPersonnelFee;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Invoicing\Services\RuleBasedPreferredPersonnelFeeResolver;
use App\Domain\Invoicing\ValueObjects\PreferredPersonnelFeeResolution;
use App\Domain\Scheduling\Models\ServiceSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('billing', 'preferred-fee-resolver');

/*
 | Phase 20A preferred-personnel-fee resolution (Plan §13.10; ADR-005). The Billing query
 | resolves the effective rule (service over platform_default, effective-date window, round-half-
 | up) and the Invoicing resolver applies session honoured-gating unchanged.
 */

function resolveEffective(): ResolveEffectivePreferredPersonnelFee
{
    return app(ResolveEffectivePreferredPersonnelFee::class);
}

it('prefers a service-scoped active rule over the platform default', function (): void {
    $service = Service::factory()->create();
    PreferredPersonnelFeeRule::factory()->create(['fixed_amount_minor' => 10000, 'scope' => 'platform_default', 'service_id' => null]);
    PreferredPersonnelFeeRule::factory()->service($service)->create(['fixed_amount_minor' => 25000]);

    $resolved = resolveEffective()->resolve($service->id, CarbonImmutable::parse('2026-07-15'), 100000);

    expect($resolved->found())->toBeTrue()
        ->and($resolved->amountMinor)->toBe(25000);
});

it('falls back to the platform default when no service rule exists', function (): void {
    $service = Service::factory()->create();
    PreferredPersonnelFeeRule::factory()->create(['fixed_amount_minor' => 8000, 'scope' => 'platform_default', 'service_id' => null]);

    $resolved = resolveEffective()->resolve($service->id, CarbonImmutable::parse('2026-07-15'), 100000);

    expect($resolved->amountMinor)->toBe(8000);
});

it('returns none when no effective rule applies', function (): void {
    $service = Service::factory()->create();

    $resolved = resolveEffective()->resolve($service->id, CarbonImmutable::parse('2026-07-15'), 100000);

    expect($resolved->found())->toBeFalse()
        ->and($resolved->amountMinor)->toBeNull();
});

it('computes a percentage rule round-half-up on the item basis (ADR-005)', function (): void {
    // 750 bp of 33_333 = 2499.975 → round-half-up → 2500.
    PreferredPersonnelFeeRule::factory()->percentage(750)->create(['scope' => 'platform_default', 'service_id' => null]);
    $service = Service::factory()->create();

    $resolved = resolveEffective()->resolve($service->id, CarbonImmutable::parse('2026-07-15'), 33_333);

    expect($resolved->amountMinor)->toBe(2500);
});

it('rounds a percentage exactly at the half up', function (): void {
    // 500 bp (5%) of 10_010 = 500.5 → 501.
    PreferredPersonnelFeeRule::factory()->percentage(500)->create(['scope' => 'platform_default', 'service_id' => null]);
    $service = Service::factory()->create();

    $resolved = resolveEffective()->resolve($service->id, CarbonImmutable::parse('2026-07-15'), 10_010);

    expect($resolved->amountMinor)->toBe(501);
});

it('selects the rule effective on the resolution date', function (): void {
    PreferredPersonnelFeeRule::factory()->create([
        'scope' => 'platform_default', 'service_id' => null, 'fixed_amount_minor' => 1000,
        'effective_from' => '2026-01-01', 'effective_to' => '2026-07-01', 'status' => 'superseded',
    ]);
    PreferredPersonnelFeeRule::factory()->create([
        'scope' => 'platform_default', 'service_id' => null, 'fixed_amount_minor' => 2000,
        'effective_from' => '2026-07-01', 'effective_to' => null, 'status' => 'active',
    ]);
    $service = Service::factory()->create();

    expect(resolveEffective()->resolve($service->id, CarbonImmutable::parse('2026-07-15'), 100000)->amountMinor)->toBe(2000);
});

// --- Invoicing resolver honoured-gating (unchanged semantics) ---

function ruleResolver(): RuleBasedPreferredPersonnelFeeResolver
{
    return app(RuleBasedPreferredPersonnelFeeResolver::class);
}

it('charges no fee when the session did not request preferred personnel', function (): void {
    $service = Service::factory()->create();
    PreferredPersonnelFeeRule::factory()->service($service)->create(['fixed_amount_minor' => 5000]);
    $session = ServiceSession::factory()->create(['preferred_personnel_honored' => null]);

    $resolution = ruleResolver()->resolve($session, $service);

    expect($resolution->honoured)->toBeFalse()
        ->and($resolution->amountMinor)->toBeNull()
        ->and($resolution->source)->toBe(PreferredPersonnelFeeResolution::SOURCE_NOT_REQUESTED);
});

it('charges no fee when the preferred request was not honoured', function (): void {
    $service = Service::factory()->create();
    PreferredPersonnelFeeRule::factory()->service($service)->create(['fixed_amount_minor' => 5000]);
    $session = ServiceSession::factory()->create(['preferred_personnel_honored' => false]);

    $resolution = ruleResolver()->resolve($session, $service);

    expect($resolution->amountMinor)->toBeNull()
        ->and($resolution->source)->toBe(PreferredPersonnelFeeResolution::SOURCE_NOT_HONOURED);
});

it('resolves the fixed rule fee for an honoured session', function (): void {
    $service = Service::factory()->create();
    PreferredPersonnelFeeRule::factory()->service($service)->create(['fixed_amount_minor' => 5000]);
    $session = ServiceSession::factory()->create(['preferred_personnel_honored' => true]);

    $resolution = ruleResolver()->resolve($session, $service);

    expect($resolution->honoured)->toBeTrue()
        ->and($resolution->amountMinor)->toBe(5000)
        ->and($resolution->source)->toBe(PreferredPersonnelFeeResolution::SOURCE_RULE_FIXED);
});

it('returns rule_none for an honoured session with no effective rule', function (): void {
    $service = Service::factory()->create();
    $session = ServiceSession::factory()->create(['preferred_personnel_honored' => true]);

    $resolution = ruleResolver()->resolve($session, $service);

    expect($resolution->honoured)->toBeTrue()
        ->and($resolution->amountMinor)->toBeNull()
        ->and($resolution->source)->toBe(PreferredPersonnelFeeResolution::SOURCE_RULE_NONE);
});
