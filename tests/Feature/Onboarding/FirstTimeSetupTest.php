<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Onboarding\Notifications\StaffWelcomeNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('onboarding');

/** Create a pending_setup merchant + its active merchant_admin owner. */
function pendingOwner(string $email = 'owner@demo.co.ke'): array
{
    $user = User::factory()->create(['email' => $email]);
    $merchant = Merchant::factory()->create(); // pending_setup by default
    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
        'status' => MerchantUserStatus::Active,
    ]);

    return [$user, $merchant];
}

function setupPayload(array $overrides = []): array
{
    return array_merge([
        'service_fee_tier' => 'split_tier',
        'business_category' => 'Salon',
        'contact_phone' => '+254700000000',
        'contact_email' => 'info@demo.co.ke',
        'receipt_display_name' => 'Demo Salon',
        'address' => '123 Biashara St',
        'town' => 'Nairobi',
        'timezone' => 'Africa/Nairobi',
        'branch' => [
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'town' => 'Nairobi',
            'address' => '123 Biashara St',
            'phone' => '+254700000111',
            'email' => 'branch@demo.co.ke',
        ],
        'branch_manager_email' => 'bm@demo.co.ke',
        'hr_email' => 'hr@demo.co.ke',
    ], $overrides);
}

it('shows current setup progress to the pending owner', function (): void {
    [$user] = pendingOwner();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/merchant-registration/first-time-setup')
        ->assertStatus(200)
        ->assertJsonPath('data.setup.required', true)
        ->assertJsonPath('data.setup.current_step', 'service_fee_tier')
        ->assertJsonStructure(['data' => ['merchant', 'setup', 'options' => ['service_fee_tiers']]]);
});

it('completes setup transactionally: tier, profile, branch, invited staff, active', function (): void {
    Notification::fake();
    [$user, $merchant] = pendingOwner();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/merchant-registration/first-time-setup', setupPayload())
        ->assertStatus(200)
        ->assertJsonPath('data.merchant.status', 'active')
        ->assertJsonPath('data.redirect', 'merchant.dashboard');

    $merchant->refresh();
    expect($merchant->status)->toBe(MerchantStatus::Active)
        ->and($merchant->service_fee_tier->value)->toBe('split_tier')
        ->and($merchant->setup_completed_at)->not->toBeNull();

    // Profile persisted.
    $profile = $merchant->profile()->firstOrFail();
    expect($profile->business_category)->toBe('Salon')
        ->and($profile->contact_phone)->toBe('+254700000000')
        ->and($profile->timezone)->toBe('Africa/Nairobi');

    // Exactly one branch created.
    $branch = MerchantBranch::query()->where('merchant_id', $merchant->id)->firstOrFail();
    expect(MerchantBranch::query()->where('merchant_id', $merchant->id)->count())->toBe(1)
        ->and($branch->code)->toBe('MAIN');

    // Two invited memberships (branch_manager + hr), each auto-assigned the branch.
    $invited = MerchantUser::query()
        ->where('merchant_id', $merchant->id)
        ->whereIn('role', [MerchantUserRole::BranchManager->value, MerchantUserRole::Hr->value])
        ->get();
    expect($invited)->toHaveCount(2);
    $invited->each(function (MerchantUser $m) use ($branch): void {
        expect($m->status)->toBe(MerchantUserStatus::Invited)
            ->and($m->last_branch_id)->toBe($branch->id);
    });

    // Welcome emails sent to the two new staff users (Scope §3.2 step 6).
    $bm = User::query()->where('email', 'bm@demo.co.ke')->firstOrFail();
    $hr = User::query()->where('email', 'hr@demo.co.ke')->firstOrFail();
    Notification::assertSentTo($bm, StaffWelcomeNotification::class);
    Notification::assertSentTo($hr, StaffWelcomeNotification::class);
});

it('requires a service fee tier before completion', function (): void {
    [$user] = pendingOwner();

    $payload = setupPayload();
    unset($payload['service_fee_tier']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/merchant-registration/first-time-setup', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['service_fee_tier']]]);
});

it('rejects an invalid service fee tier', function (): void {
    [$user] = pendingOwner();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/merchant-registration/first-time-setup', setupPayload([
            'service_fee_tier' => 'premium_tier',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects identical Branch Manager and HR emails', function (): void {
    [$user] = pendingOwner();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/merchant-registration/first-time-setup', setupPayload([
            'branch_manager_email' => 'same@demo.co.ke',
            'hr_email' => 'same@demo.co.ke',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a staff email that equals the owner email', function (): void {
    [$user] = pendingOwner('owner@demo.co.ke');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/merchant-registration/first-time-setup', setupPayload([
            'branch_manager_email' => 'owner@demo.co.ke',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('blocks a second setup once the merchant is active', function (): void {
    Notification::fake();
    [$user] = pendingOwner();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/merchant-registration/first-time-setup', setupPayload())
        ->assertStatus(200);

    // Merchant is now active — setup is gated off.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/merchant-registration/first-time-setup', setupPayload())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'setup_already_completed');
});
