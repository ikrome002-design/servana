<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Actions\ResendStaffInvitation;
use App\Domain\Hr\Actions\RevokeStaffInvitation;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Exceptions\StaffLifecycleException;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'hr', 'security');

/*
 | Invitation revocation (Plan §79 R6, Scope §3.4). A pending invitation cannot be
 | accepted after it is revoked (explicitly, or by suspension/deactivation); an
 | accepted invitation is never retroactively modified; a replacement invitation
 | cannot reuse the previous token; enumeration resistance is unchanged.
 */

/** A pending invitation in a fresh active merchant bound to a known raw token. */
function r6Invitation(string $rawToken, string $email = 'invitee@salon.co.ke'): StaffInvitation
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    return StaffInvitation::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'email' => $email,
        'role' => MerchantUserRole::FrontOffice,
        'token_hash' => hash('sha256', $rawToken),
    ]);
}

function r6AcceptPayload(string $token): array
{
    return ['token' => $token, 'first_name' => 'In', 'last_name' => 'Vitee', 'phone' => '+254700000111'];
}

it('cannot accept a revoked invitation', function (): void {
    $invitation = r6Invitation('raw-1');
    app(RevokeStaffInvitation::class)->handle($invitation);

    test()->postJson('/api/v1/staff-invitations/accept', r6AcceptPayload('raw-1'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_invitation');
});

it('cannot accept an invitation revoked by membership suspension', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    // A still-pending re-invitation for the same email in the same merchant.
    StaffInvitation::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'email' => $staffUser->email,
        'role' => MerchantUserRole::FrontOffice,
        'token_hash' => hash('sha256', 'raw-suspend'),
    ]);

    app(StaffLifecycleService::class)->suspend($membership);

    test()->postJson('/api/v1/staff-invitations/accept', r6AcceptPayload('raw-suspend'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_invitation');
});

it('does not retroactively modify an accepted invitation', function (): void {
    $invitation = r6Invitation('raw-accept');

    test()->postJson('/api/v1/staff-invitations/accept', r6AcceptPayload('raw-accept'))
        ->assertStatus(201);

    expect($invitation->fresh()->status)->toBe(StaffInvitationStatus::Accepted);

    // An accepted invitation can no longer be revoked (only pending ones can).
    expect(fn () => app(RevokeStaffInvitation::class)->handle($invitation->fresh()))
        ->toThrow(StaffLifecycleException::class);

    expect($invitation->fresh()->status)->toBe(StaffInvitationStatus::Accepted);
});

it('cannot reuse the previous token after a replacement invitation is issued', function (): void {
    $invitation = r6Invitation('raw-old');

    // Resend rotates the token hash (issues a new token, invalidating the old).
    app(ResendStaffInvitation::class)->handle($invitation);

    test()->postJson('/api/v1/staff-invitations/accept', r6AcceptPayload('raw-old'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_invitation');
});

it('keeps enumeration resistance — an unknown token returns the uniform 422', function (): void {
    r6Invitation('raw-real');

    test()->postJson('/api/v1/staff-invitations/accept', r6AcceptPayload('raw-unknown'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_invitation');
});
