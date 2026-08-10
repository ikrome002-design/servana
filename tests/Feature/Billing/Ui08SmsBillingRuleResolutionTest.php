<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PlatformSmsBillingRuleState;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\PlatformSmsBillingRule;
use App\Domain\Billing\Queries\ResolveEffectiveSmsBillingRule;
use App\Domain\Messaging\Sms\Support\SmsCostCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('billing', 'ui08', 'ui08-sms-billing');

/*
 | COR-UI08-001 §9 — effective-date resolution for the SMS pricing series.
 |
 | The rule in force at an instant is the greatest UNCANCELLED effective_from <= that instant.
 | This suite proves the arithmetic and the ordering, and — the point of the whole corrective
 | change — that a scheduled rule ALWAYS beats deployment configuration.
 */

function ui08Resolver(): ResolveEffectiveSmsBillingRule
{
    return app(ResolveEffectiveSmsBillingRule::class);
}

it('resolves the greatest effective_from at or before the instant', function (): void {
    $old = PlatformSmsBillingRule::factory()->create(['unit_cost_minor' => 100, 'effective_from' => CarbonImmutable::parse('2026-01-01T00:00:00Z')]);
    $new = PlatformSmsBillingRule::factory()->create(['unit_cost_minor' => 200, 'effective_from' => CarbonImmutable::parse('2026-06-01T00:00:00Z')]);
    PlatformSmsBillingRule::factory()->create(['unit_cost_minor' => 300, 'effective_from' => CarbonImmutable::parse('2027-01-01T00:00:00Z')]);

    expect(ui08Resolver()->at(CarbonImmutable::parse('2026-03-15T00:00:00Z'))?->id)->toBe($old->id)
        ->and(ui08Resolver()->at(CarbonImmutable::parse('2026-08-15T00:00:00Z'))?->id)->toBe($new->id)
        // Exactly at the boundary the new rule already applies.
        ->and(ui08Resolver()->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))?->id)->toBe($new->id);
});

it('returns nothing before the series begins', function (): void {
    PlatformSmsBillingRule::factory()->create(['effective_from' => CarbonImmutable::parse('2026-06-01T00:00:00Z')]);

    expect(ui08Resolver()->at(CarbonImmutable::parse('2026-01-01T00:00:00Z')))->toBeNull();
});

it('fails closed rather than pricing at zero when no rule reaches back that far', function (): void {
    expect(fn (): PlatformSmsBillingRule => ui08Resolver()->requireCurrent())
        ->toThrow(RuntimeException::class);
});

it('excludes a cancelled rule from resolution entirely', function (): void {
    $live = PlatformSmsBillingRule::factory()->create([
        'unit_cost_minor' => 100,
        'effective_from' => CarbonImmutable::now()->subMonth(),
    ]);

    // Withdrawn while still pending — the only state the guard permits cancelling.
    $cancelled = PlatformSmsBillingRule::factory()->create([
        'unit_cost_minor' => 999,
        'effective_from' => CarbonImmutable::now()->addMonth(),
    ]);
    $cancelled->forceFill([
        'cancelled_at' => CarbonImmutable::now(),
        'cancelled_by_user_id' => $cancelled->created_by_user_id,
        'cancellation_reason' => 'Withdrawn before taking effect.',
    ])->saveQuietly();

    // Even ONCE ITS INSTANT HAS PASSED, a withdrawn rule never becomes effective.
    expect(ui08Resolver()->at(CarbonImmutable::now()->addMonths(2))?->id)->toBe($live->id);
});

it('finds the next scheduled rule and ignores a cancelled one', function (): void {
    PlatformSmsBillingRule::factory()->create(['effective_from' => CarbonImmutable::now()->subMonth()]);
    $next = PlatformSmsBillingRule::factory()->create(['effective_from' => CarbonImmutable::now()->addMonth()]);
    $later = PlatformSmsBillingRule::factory()->create(['effective_from' => CarbonImmutable::now()->addMonths(2)]);

    expect(ui08Resolver()->next()?->id)->toBe($next->id);

    $next->forceFill([
        'cancelled_at' => CarbonImmutable::now(),
        'cancelled_by_user_id' => $next->created_by_user_id,
        'cancellation_reason' => 'Withdrawn.',
    ])->saveQuietly();

    expect(ui08Resolver()->next()?->id)->toBe($later->id);
});

it('derives pending, effective, superseded and cancelled without storing a status', function (): void {
    $now = CarbonImmutable::now();

    $future = PlatformSmsBillingRule::factory()->create(['effective_from' => $now->addMonth()]);
    $current = PlatformSmsBillingRule::factory()->create(['effective_from' => $now->subDay()]);
    $older = PlatformSmsBillingRule::factory()->create(['effective_from' => $now->subMonth()]);

    expect($future->stateAt($now))->toBe(PlatformSmsBillingRuleState::Pending)
        ->and($current->stateAt($now, false))->toBe(PlatformSmsBillingRuleState::Effective)
        ->and($older->stateAt($now, true))->toBe(PlatformSmsBillingRuleState::Superseded);

    // Only a pending rule can be cancelled; an effective or superseded one is permanent history,
    // which is itself asserted in Ui08SmsBillingSnapshotImmutabilityTest.
    $future->forceFill([
        'cancelled_at' => $now,
        'cancelled_by_user_id' => $future->created_by_user_id,
        'cancellation_reason' => 'Withdrawn.',
    ])->saveQuietly();

    expect($future->refresh()->stateAt($now))->toBe(PlatformSmsBillingRuleState::Cancelled);

    // There is no status column to disagree with the dates.
    expect(Schema::hasColumn('platform_sms_billing_rules', 'status'))->toBeFalse();
});

// --- The point of the corrective change -------------------------------------------------------

it('always prefers a scheduled rule over deployment configuration', function (): void {
    config()->set('sms.pricing.unit_cost_minor', 100);

    PlatformSmsBillingRule::factory()->create([
        'unit_cost_minor' => 777,
        'effective_from' => CarbonImmutable::now()->subDay(),
    ]);

    // The versioned, audited authority wins. Config cannot shadow a price anyone scheduled.
    expect(app(SmsCostCalculator::class)->unitCostMinor())->toBe(777);
});

it('uses configuration only as the genesis bootstrap when the series is empty', function (): void {
    config()->set('sms.pricing.unit_cost_minor', 100);

    expect(PlatformSmsBillingRule::query()->count())->toBe(0)
        ->and(app(SmsCostCalculator::class)->unitCostMinor())->toBe(100);
});

it('reads currency from the platform billing settings version, not a second SMS setting', function (): void {
    config()->set('sms.pricing.currency', 'USD');
    PlatformBillingSettings::factory()->create(['currency' => 'KES', 'effective_from' => CarbonImmutable::now()->subYear()]);

    // Before UI-08 these were two authorities that could disagree; now there is one.
    expect(app(SmsCostCalculator::class)->currency()->value)->toBe('KES');
});

it('multiplies with integers only and never a float', function (): void {
    PlatformBillingSettings::factory()->create(['currency' => 'KES', 'effective_from' => CarbonImmutable::now()->subYear()]);
    PlatformSmsBillingRule::factory()->create(['unit_cost_minor' => 250, 'effective_from' => CarbonImmutable::now()->subDay()]);

    $calculator = app(SmsCostCalculator::class);

    expect($calculator->quantity(40, 2))->toBe(80)
        ->and($calculator->totalMinor(40, 2))->toBe(20000)
        ->and($calculator->totalMinor(40, 2))->toBeInt();
});
