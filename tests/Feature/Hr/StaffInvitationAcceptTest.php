<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Models\StaffHistory;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('hr');

/** Create a pending invitation bound to a known raw token. */
function pendingInvitation(string $rawToken, MerchantUserRole $role = MerchantUserRole::FrontOffice): StaffInvitation
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    return StaffInvitation::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'email' => 'amina@salon.co.ke',
        'role' => $role,
        'token_hash' => hash('sha256', $rawToken),
    ]);
}

function acceptPayload(string $token): array
{
    return [
        'token' => $token,
        'first_name' => 'Amina',
        'last_name' => 'Mwangi',
        'phone' => '+254700111222',
    ];
}

it('accepts a valid invitation and provisions the staff member atomically', function (): void {
    $invitation = pendingInvitation('good-raw-token');

    $this->postJson('/api/v1/staff-invitations/accept', acceptPayload('good-raw-token'))
        ->assertStatus(201)
        ->assertJsonStructure(['message']);

    $user = User::query()->where('email', 'amina@salon.co.ke')->firstOrFail();
    $membership = MerchantUser::query()->where('user_id', $user->id)->firstOrFail();
    expect($membership->status)->toBe(MerchantUserStatus::Active)
        ->and($membership->role)->toBe(MerchantUserRole::FrontOffice);

    $profile = StaffProfile::query()->where('merchant_user_id', $membership->id)->firstOrFail();
    expect($profile->phone)->toBe('+254700111222')
        ->and($profile->is_active)->toBeTrue();

    expect(BranchUserAssignment::query()
        ->where('merchant_user_id', $membership->id)
        ->where('status', BranchUserAssignmentStatus::Active->value)
        ->count())->toBe(1);

    expect($invitation->fresh()->status)->toBe(StaffInvitationStatus::Accepted);

    // Initial append-only history (status + branch).
    expect(StaffHistory::query()->where('staff_profile_id', $profile->id)->count())->toBe(2);
});

it('rejects an unknown token uniformly', function (): void {
    pendingInvitation('good-raw-token');

    $this->postJson('/api/v1/staff-invitations/accept', acceptPayload('totally-wrong'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_invitation');

    expect(User::query()->where('email', 'amina@salon.co.ke')->exists())->toBeFalse();
});

it('rejects an expired invitation', function (): void {
    $invitation = pendingInvitation('good-raw-token');
    $invitation->update(['expires_at' => now()->subHour()]);

    $this->postJson('/api/v1/staff-invitations/accept', acceptPayload('good-raw-token'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_invitation');
});

it('rejects a revoked invitation', function (): void {
    $invitation = pendingInvitation('good-raw-token');
    $invitation->update(['status' => StaffInvitationStatus::Revoked]);

    $this->postJson('/api/v1/staff-invitations/accept', acceptPayload('good-raw-token'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_invitation');
});

it('cannot be accepted twice', function (): void {
    pendingInvitation('good-raw-token');

    $this->postJson('/api/v1/staff-invitations/accept', acceptPayload('good-raw-token'))->assertStatus(201);

    // Second attempt uses a different phone so it passes validation and reaches
    // the (already-accepted) invitation claim, which fails uniformly.
    $this->postJson('/api/v1/staff-invitations/accept', [
        'token' => 'good-raw-token',
        'first_name' => 'Amina',
        'last_name' => 'Mwangi',
        'phone' => '+254700333444',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_invitation');
});
