<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('hr');

it('revokes sessions and unused magic links when suspending a staff member', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    // Seed a DB session row + an unconsumed Magic Link for the staff user.
    DB::table('sessions')->insert([
        'id' => 'sess-'.$staffUser->id,
        'user_id' => $staffUser->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => 'x',
        'last_activity' => now()->getTimestamp(),
    ]);
    app(MagicLinkTokenService::class)->issue($staffUser->email);

    app(StaffLifecycleService::class)->suspend($membership, $admin);

    expect(DB::table('sessions')->where('user_id', $staffUser->id)->count())->toBe(0);
    expect(MagicLoginToken::query()->where('email', $staffUser->email)->whereNull('invalidated_at')->count())->toBe(0);
});

it('revokes branch assignments and pending invitations on deactivation', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    // A still-pending invitation for the same email in the merchant.
    StaffInvitation::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'email' => $staffUser->email,
        'role' => MerchantUserRole::FrontOffice,
    ]);

    app(StaffLifecycleService::class)->deactivate($membership, $admin);

    expect($membership->branchAssignments()->where('status', BranchUserAssignmentStatus::Active->value)->count())->toBe(0);
    expect(StaffInvitation::query()
        ->where('merchant_id', $merchant->id)
        ->where('email', $staffUser->email)
        ->where('status', StaffInvitationStatus::Pending->value)
        ->count())->toBe(0);
});
