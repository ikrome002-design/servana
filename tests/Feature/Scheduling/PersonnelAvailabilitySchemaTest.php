<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('scheduling', 'availability-schema');

/*
 | personnel_availability structural invariants live in PostgreSQL (Plan §13.7,
 | §80 Phase 15B): CHECK polarity/weekday/interval, GiST same-polarity exclusion,
 | and composite-FK tenant/branch consistency — all proven by bypassing Eloquent.
 */

/** A merchant + same-merchant branch + staff (composite-FK consistent). */
function schemaStaff(): StaffProfile
{
    $merchant = Merchant::factory()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    return StaffProfile::factory()->create([
        'merchant_id' => $merchant->id,
        'primary_branch_id' => $branch->id,
    ]);
}

/** Raw query-builder insert (bypasses Eloquent casts/scopes/events). */
function rawInsert(StaffProfile $staff, array $overrides): void
{
    DB::table('personnel_availability')->insert(array_merge([
        'merchant_id' => $staff->merchant_id,
        'branch_id' => $staff->primary_branch_id,
        'staff_profile_id' => $staff->id,
        'weekday' => 1,
        'date' => null,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'type' => 'recurring',
        'available' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('applies the migration and registers it in the manifest', function (): void {
    expect(Schema::hasTable('personnel_availability'))->toBeTrue()
        ->and(Schema::hasColumns('personnel_availability', [
            'merchant_id', 'branch_id', 'staff_profile_id', 'weekday', 'date',
            'start_time', 'end_time', 'type', 'available',
        ]))->toBeTrue();

    $manifest = file_get_contents(base_path('docs/architecture/migrations/manifest.yaml'));
    expect($manifest)->toContain('2026_06_29_000001_create_personnel_availability_table.php');
});

it('classifies the table as branch-owned with a composite consistency constraint', function (): void {
    expect(TenantOwnership::BRANCH_OWNED)->toContain('personnel_availability')
        ->and(TenantOwnership::COMPOSITE_CONSISTENCY)->toHaveKey('personnel_availability')
        ->and(TenantOwnership::MODELS[PersonnelAvailability::class])->toBe('branch');
});

it('uses the BelongsToMerchant + BelongsToBranch traits', function (): void {
    $traits = class_uses(PersonnelAvailability::class);
    expect($traits)->toContain(BelongsToMerchant::class)
        ->and($traits)->toContain(BelongsToBranch::class);
});

it('has merchant-first and staff-schedule indexes', function (): void {
    $indexes = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'personnel_availability'"))
        ->pluck('indexdef')->implode("\n");

    expect($indexes)->toContain('merchant_id, branch_id')
        ->and($indexes)->toContain('staff_profile_id, weekday')
        ->and($indexes)->toContain('staff_profile_id, date');
});

it('requires weekday and forbids date on a recurring row', function (): void {
    $staff = schemaStaff();
    expect(fn () => rawInsert($staff, ['type' => 'recurring', 'weekday' => null, 'date' => null]))->toThrow(QueryException::class);
    expect(fn () => rawInsert($staff, ['type' => 'recurring', 'weekday' => 1, 'date' => '2026-07-06']))->toThrow(QueryException::class);
});

it('requires date and forbids weekday on an exception row', function (): void {
    $staff = schemaStaff();
    expect(fn () => rawInsert($staff, ['type' => 'exception', 'weekday' => null, 'date' => null]))->toThrow(QueryException::class);
    expect(fn () => rawInsert($staff, ['type' => 'exception', 'weekday' => 1, 'date' => '2026-07-06']))->toThrow(QueryException::class);
});

it('rejects an invalid weekday', function (): void {
    expect(fn () => rawInsert(schemaStaff(), ['weekday' => 7]))->toThrow(QueryException::class);
});

it('rejects an invalid type', function (): void {
    expect(fn () => rawInsert(schemaStaff(), ['type' => 'holiday']))->toThrow(QueryException::class);
});

it('rejects start equal to end', function (): void {
    expect(fn () => rawInsert(schemaStaff(), ['start_time' => '09:00:00', 'end_time' => '09:00:00']))->toThrow(QueryException::class);
});

it('rejects start later than end (also covers cross-midnight)', function (): void {
    expect(fn () => rawInsert(schemaStaff(), ['start_time' => '17:00:00', 'end_time' => '09:00:00']))->toThrow(QueryException::class);
});

it('rejects a same-polarity overlapping recurring interval', function (): void {
    $staff = schemaStaff();
    rawInsert($staff, ['weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '13:00:00', 'available' => true]);
    expect(fn () => rawInsert($staff, ['weekday' => 1, 'start_time' => '12:00:00', 'end_time' => '15:00:00', 'available' => true]))
        ->toThrow(QueryException::class);
});

it('rejects a same-polarity overlapping exception interval', function (): void {
    $staff = schemaStaff();
    rawInsert($staff, ['type' => 'exception', 'weekday' => null, 'date' => '2026-07-06', 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'available' => false]);
    expect(fn () => rawInsert($staff, ['type' => 'exception', 'weekday' => null, 'date' => '2026-07-06', 'start_time' => '11:00:00', 'end_time' => '13:00:00', 'available' => false]))
        ->toThrow(QueryException::class);
});

it('permits an available shift plus an overlapping unavailable break (opposite polarity)', function (): void {
    $staff = schemaStaff();
    rawInsert($staff, ['weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'available' => true]);
    rawInsert($staff, ['weekday' => 1, 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'available' => false]);

    expect(PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->count())->toBe(2);
});

it('permits back-to-back half-open same-polarity intervals (no overlap)', function (): void {
    $staff = schemaStaff();
    rawInsert($staff, ['weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '13:00:00', 'available' => true]);
    rawInsert($staff, ['weekday' => 1, 'start_time' => '13:00:00', 'end_time' => '17:00:00', 'available' => true]);

    expect(PersonnelAvailability::query()->where('staff_profile_id', $staff->id)->count())->toBe(2);
});

it('rejects a cross-tenant staff reference via the composite FK', function (): void {
    $staff = schemaStaff();
    $otherMerchant = Merchant::factory()->create();

    // merchant_id no longer matches the staff profile's merchant.
    expect(fn () => rawInsert($staff, ['merchant_id' => $otherMerchant->id]))->toThrow(QueryException::class);
});

it('rejects a cross-tenant branch reference via the composite FK', function (): void {
    $staff = schemaStaff();
    $otherMerchant = Merchant::factory()->create();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $otherMerchant->id]);

    // branch belongs to a different merchant than merchant_id.
    expect(fn () => rawInsert($staff, ['branch_id' => $otherBranch->id]))->toThrow(QueryException::class);
});
