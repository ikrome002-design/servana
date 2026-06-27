<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('tenancy');

/*
 | Upgrade-path backfill (Plan §79 R5; ADR-002). Rolls back the three R5 migrations
 | to the pre-R5 schema, seeds representative legacy rows across two merchants,
 | then re-applies R5 and proves every row is backfilled with the CORRECT merchant
 | (never another branch's) and no null/orphan remains. Postgres DDL is
 | transactional, so this runs inside the RefreshDatabase test transaction.
 */

it('backfills merchant_id from the parent on the upgrade path without cross-contamination', function (): void {
    // Two merchants + branches BEFORE rolling back (parents are untouched by R5 down()).
    [, $merchantA] = activeAdmin();
    [, $merchantB] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchantA->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchantB->id]);

    $membershipA = MerchantUser::factory()->create(['merchant_id' => $merchantA->id, 'role' => MerchantUserRole::FrontOffice]);
    $profileA = StaffProfile::factory()->create([
        'merchant_user_id' => $membershipA->id, 'merchant_id' => $merchantA->id, 'primary_branch_id' => $branchA->id,
    ]);

    // Simulate the pre-R5 schema by rolling back the three R5 migrations AND every
    // migration applied after them — computed dynamically so this stays correct as
    // later phases append migrations (e.g. Phase 10F's file tables); a fixed --step
    // would otherwise stop short of the R5 migrations and leave merchant_id NOT NULL.
    $rollbackSteps = DB::table('migrations')
        ->where('migration', '>=', '2026_06_23_000001_add_composite_unique_to_tenant_parents')
        ->count();
    Artisan::call('migrate:rollback', ['--step' => $rollbackSteps, '--force' => true]);

    // Legacy rows carry only the parent FK (no merchant_id column exists now).
    DB::table('branch_day_records')->insert([
        ['ulid' => (string) Str::ulid(), 'branch_id' => $branchA->id, 'business_date' => '2026-06-01', 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => (string) Str::ulid(), 'branch_id' => $branchB->id, 'business_date' => '2026-06-01', 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('staff_history')->insert([
        'ulid' => (string) Str::ulid(), 'staff_profile_id' => $profileA->id, 'field' => 'status', 'approval_status' => 'n/a', 'created_at' => now(),
    ]);

    // Re-apply R5 (expand → backfill → constrain).
    $exit = Artisan::call('migrate', ['--force' => true]);
    expect($exit)->toBe(0);

    // Branch rows backfilled with the CORRECT merchant (no cross-contamination).
    expect(DB::table('branch_day_records')->where('branch_id', $branchA->id)->value('merchant_id'))->toBe($merchantA->id)
        ->and(DB::table('branch_day_records')->where('branch_id', $branchB->id)->value('merchant_id'))->toBe($merchantB->id)
        ->and(DB::table('branch_day_records')->whereNull('merchant_id')->count())->toBe(0);

    // History row backfilled from its staff profile's merchant.
    expect(DB::table('staff_history')->where('staff_profile_id', $profileA->id)->value('merchant_id'))->toBe($merchantA->id)
        ->and(DB::table('staff_history')->whereNull('merchant_id')->count())->toBe(0);
});
