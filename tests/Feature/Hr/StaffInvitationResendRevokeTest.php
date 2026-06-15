<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Notifications\StaffInvitationNotification;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('hr');

it('resends a pending invitation, rotating the token and incrementing the count', function (): void {
    Notification::fake();
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $invitation = StaffInvitation::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'role' => MerchantUserRole::Hr,
        'token_hash' => hash('sha256', 'original-token'),
        'resend_count' => 0,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/staff-invitations/{$invitation->ulid}/resend")
        ->assertStatus(200)
        ->assertJsonPath('data.resend_count', 1);

    $fresh = $invitation->fresh();
    expect($fresh->token_hash)->not->toBe(hash('sha256', 'original-token'))
        ->and($fresh->status)->toBe(StaffInvitationStatus::Pending);

    // No duplicate invitation row is created on resend.
    expect(StaffInvitation::query()->where('merchant_id', $merchant->id)->count())->toBe(1);
    Notification::assertSentOnDemand(StaffInvitationNotification::class);
});

it('revokes a pending invitation so it can no longer be accepted', function (): void {
    Notification::fake();
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $invitation = StaffInvitation::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'email' => 'temp@salon.co.ke',
        'role' => MerchantUserRole::Hr,
        'token_hash' => hash('sha256', 'revoke-me'),
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/staff-invitations/{$invitation->ulid}/revoke")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'revoked');

    // A revoked invitation cannot be accepted.
    $this->postJson('/api/v1/staff-invitations/accept', [
        'token' => 'revoke-me',
        'first_name' => 'Temp',
        'last_name' => 'User',
        'phone' => '+254700999888',
    ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_or_expired_invitation');
});

it('cannot resend an accepted invitation', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $invitation = StaffInvitation::factory()->accepted()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'role' => MerchantUserRole::Hr,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/staff-invitations/{$invitation->ulid}/resend")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_staff_transition');
});

it('returns 404 when managing an invitation from another merchant', function (): void {
    [$admin] = activeAdmin();
    $foreignBranch = MerchantBranch::factory()->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);
    $foreign = StaffInvitation::factory()->create([
        'merchant_id' => $foreignBranch->merchant_id,
        'branch_id' => $foreignBranch->id,
        'role' => MerchantUserRole::Hr,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/staff-invitations/{$foreign->ulid}/revoke")
        ->assertStatus(404);
});
