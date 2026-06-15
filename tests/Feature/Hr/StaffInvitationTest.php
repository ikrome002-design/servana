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

it('lets a merchant admin invite a branch manager and emails them', function (): void {
    Notification::fake();
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/staff-invitations', [
            'email' => 'manager@salon.co.ke',
            'branch_id' => $branch->ulid,
            'role' => 'branch_manager',
            'role_title' => 'Branch Manager',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.email', 'manager@salon.co.ke')
        ->assertJsonPath('data.status', 'pending');

    $invitation = StaffInvitation::query()->where('email', 'manager@salon.co.ke')->firstOrFail();
    expect($invitation->status)->toBe(StaffInvitationStatus::Pending)
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    Notification::assertSentOnDemand(StaffInvitationNotification::class);
});

it('stores only the token hash, never the raw token', function (): void {
    Notification::fake();
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/staff-invitations', [
        'email' => 'manager@salon.co.ke',
        'branch_id' => $branch->ulid,
        'role' => 'hr',
    ])->assertStatus(201);

    // Recover the raw token from the captured notification.
    $raw = null;
    Notification::assertSentOnDemand(
        StaffInvitationNotification::class,
        function (StaffInvitationNotification $n) use (&$raw): bool {
            $raw = (string) (new ReflectionProperty($n, 'rawToken'))->getValue($n);

            return true;
        },
    );

    $invitation = StaffInvitation::query()->where('email', 'manager@salon.co.ke')->firstOrFail();
    expect($raw)->not->toBeNull()
        ->and($invitation->token_hash)->toBe(hash('sha256', (string) $raw))
        ->and(strlen($invitation->token_hash))->toBe(64);

    foreach ($invitation->getAttributes() as $value) {
        expect((string) $value)->not->toContain((string) $raw);
    }
});

it('blocks a merchant admin from inviting a non branch-manager/hr role', function (): void {
    Notification::fake();
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    // Admin may add ONLY branch_manager + hr (Scope §3.2).
    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/staff-invitations', [
        'email' => 'cashier@salon.co.ke',
        'branch_id' => $branch->ulid,
        'role' => 'front_office',
    ])->assertStatus(403);
});

it('blocks a duplicate pending invitation for the same email/role/branch', function (): void {
    Notification::fake();
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    StaffInvitation::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'email' => 'manager@salon.co.ke',
        'role' => MerchantUserRole::Hr,
    ]);

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/staff-invitations', [
        'email' => 'manager@salon.co.ke',
        'branch_id' => $branch->ulid,
        'role' => 'hr',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects an invitation for a branch in another merchant', function (): void {
    Notification::fake();
    [$admin] = activeAdmin();
    $foreignBranch = MerchantBranch::factory()->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/staff-invitations', [
        'email' => 'x@salon.co.ke',
        'branch_id' => $foreignBranch->ulid,
        'role' => 'hr',
    ])->assertStatus(422); // branch_id exists rule scoped to merchant fails
});
