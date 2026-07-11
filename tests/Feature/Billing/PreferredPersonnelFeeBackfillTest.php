<?php

declare(strict_types=1);

use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Catalogue\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class)->group('billing', 'preferred-fee-backfill');

/*
 | Phase 20A legacy expand-and-contract backfill (Plan §13.10). Under RefreshDatabase the
 | backfill migration runs against empty tables (no-op); these tests seed services with legacy
 | fee values and invoke the migration's up() to prove exact-value equivalence, the immutable
 | 2026-07-10 cutover, null-skip, and idempotency — without recalculating any finalized invoice.
 */

function runPreferredFeeBackfill(): object
{
    /** @var object $migration */
    $migration = require database_path('migrations/2026_07_10_000006_backfill_preferred_personnel_fee_rules_from_services.php');
    $migration->up();

    return $migration;
}

it('backfills one fixed active service rule per service with a non-null legacy fee, copying the exact value', function (): void {
    $service = Service::factory()->create(['preferred_personnel_fee_minor' => 12345, 'currency' => 'KES']);

    runPreferredFeeBackfill();

    $rule = PreferredPersonnelFeeRule::query()->where('service_id', $service->id)->sole();

    expect($rule->calculation_type->value)->toBe('fixed_amount')
        ->and($rule->fixed_amount_minor)->toBe(12345)
        ->and($rule->currency)->toBe('KES')
        ->and($rule->scope->value)->toBe('service')
        ->and($rule->calculation_basis->value)->toBe('service_item_net_amount')
        ->and($rule->status->value)->toBe('active')
        ->and($rule->created_by)->toBeNull()
        ->and($rule->percentage_basis_points)->toBeNull();
});

it('sets every backfilled rule effective_from to exactly 2026-07-10', function (): void {
    Service::factory()->count(3)->create(['preferred_personnel_fee_minor' => 5000]);

    runPreferredFeeBackfill();

    $dates = PreferredPersonnelFeeRule::query()->pluck('effective_from')
        ->map(fn ($d): string => Carbon::parse($d)->toDateString())
        ->unique()
        ->values()
        ->all();

    expect($dates)->toBe(['2026-07-10']);
});

it('does not create a rule for a service whose legacy fee is null', function (): void {
    $withFee = Service::factory()->create(['preferred_personnel_fee_minor' => 7000]);
    $withoutFee = Service::factory()->create(['preferred_personnel_fee_minor' => null]);

    runPreferredFeeBackfill();

    expect(PreferredPersonnelFeeRule::query()->where('service_id', $withFee->id)->exists())->toBeTrue()
        ->and(PreferredPersonnelFeeRule::query()->where('service_id', $withoutFee->id)->exists())->toBeFalse();
});

it('backfills a rule for a legacy fee of exactly 0', function (): void {
    $service = Service::factory()->create(['preferred_personnel_fee_minor' => 0]);

    runPreferredFeeBackfill();

    $rule = PreferredPersonnelFeeRule::query()->where('service_id', $service->id)->sole();
    expect($rule->fixed_amount_minor)->toBe(0);
});

it('is idempotent — re-running the backfill creates no duplicate rules', function (): void {
    Service::factory()->count(2)->create(['preferred_personnel_fee_minor' => 9000]);

    $migration = runPreferredFeeBackfill();
    $countAfterFirst = PreferredPersonnelFeeRule::query()->count();

    $migration->up();

    expect(PreferredPersonnelFeeRule::query()->count())->toBe($countAfterFirst)
        ->and($countAfterFirst)->toBe(2);
});
