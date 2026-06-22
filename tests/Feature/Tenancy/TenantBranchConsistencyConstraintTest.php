<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('tenancy', 'isolation');

/*
 | DB consistency (Plan §2.1, §13.1; ADR-002; R5). The composite foreign keys must
 | reject a row whose merchant_id disagrees with its parent — application checks
 | alone are insufficient. Inserts go through DB::table to bypass model auto-fill,
 | proving the database is the boundary.
 */

it('rejects a branch-owned row whose merchant_id is not the branch owner', function (): void {
    [, $merchantA] = activeAdmin();
    [, $merchantB] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchantA->id]);

    // branch_id belongs to merchant A, but we claim merchant B → composite FK reject.
    expect(fn () => DB::table('branch_day_records')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $merchantB->id,
        'branch_id' => $branchA->id,
        'business_date' => now()->toDateString(),
        'status' => 'closed',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('accepts a branch-owned row whose merchant_id matches the branch', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    DB::table('branch_day_records')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'business_date' => now()->toDateString(),
        'status' => 'closed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('branch_day_records')->where('branch_id', $branch->id)->count())->toBe(1);
});

it('rejects staff_history whose merchant_id is not the staff profile owner', function (): void {
    [, $merchantA] = activeAdmin();
    [, $merchantB] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchantA->id]);
    $membershipA = MerchantUser::factory()->create(['merchant_id' => $merchantA->id, 'role' => MerchantUserRole::FrontOffice]);
    $profileA = StaffProfile::factory()->create([
        'merchant_user_id' => $membershipA->id, 'merchant_id' => $merchantA->id, 'primary_branch_id' => $branchA->id,
    ]);

    expect(fn () => DB::table('staff_history')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $merchantB->id, // wrong merchant
        'staff_profile_id' => $profileA->id,
        'field' => 'status',
        'approval_status' => 'n/a',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a permission override whose merchant_id is not the membership owner', function (): void {
    $this->seed(PermissionSeeder::class);
    [, $merchantA] = activeAdmin();
    [, $merchantB] = activeAdmin();
    $membershipA = MerchantUser::factory()->create(['merchant_id' => $merchantA->id, 'role' => MerchantUserRole::FrontOffice]);
    $permissionId = DB::table('permissions')->value('id');

    expect(fn () => DB::table('merchant_user_permission_overrides')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $merchantB->id, // wrong merchant
        'merchant_user_id' => $membershipA->id,
        'permission_id' => $permissionId,
        'effect' => 'grant',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
