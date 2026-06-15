<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('security', 'hr');

/*
 | Duplicate Staff Prevention (Scope §3.4): the platform blocks duplicate ACTIVE
 | staff by phone (partial unique index) and by email (users.email unique).
 */

it('blocks a second active staff profile with the same phone', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    StaffProfile::factory()->create([
        'merchant_id' => $merchant->id,
        'primary_branch_id' => $branch->id,
        'phone' => '+254700123123',
        'is_active' => true,
    ]);

    expect(fn () => StaffProfile::factory()->create([
        'merchant_id' => $merchant->id,
        'primary_branch_id' => $branch->id,
        'phone' => '+254700123123',
        'is_active' => true,
    ]))->toThrow(QueryException::class);
});

it('allows reusing a phone once the prior staff profile is inactive', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    StaffProfile::factory()->create([
        'merchant_id' => $merchant->id,
        'primary_branch_id' => $branch->id,
        'phone' => '+254700555555',
        'is_active' => false, // deactivated staff frees the phone
    ]);

    $second = StaffProfile::factory()->create([
        'merchant_id' => $merchant->id,
        'primary_branch_id' => $branch->id,
        'phone' => '+254700555555',
        'is_active' => true,
    ]);

    expect($second->exists)->toBeTrue();
});

it('blocks a duplicate active email at the user level', function (): void {
    User::factory()->create(['email' => 'dupe@salon.co.ke']);

    expect(fn () => User::factory()->create(['email' => 'dupe@salon.co.ke']))
        ->toThrow(QueryException::class);
});

it('keeps merchant_user unique per merchant+user', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $user = User::factory()->create();
    MerchantUser::factory()->create(['merchant_id' => $merchant->id, 'user_id' => $user->id]);

    expect(fn () => MerchantUser::factory()->create(['merchant_id' => $merchant->id, 'user_id' => $user->id]))
        ->toThrow(QueryException::class);
});
