<?php

declare(strict_types=1);

use App\Domain\Auth\Notifications\MagicLoginLinkNotification;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use App\Domain\Merchants\Models\MerchantStatusHistory;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('onboarding');

function selfRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'owner_name' => 'Paul Nderitu',
        'email' => 'owner@example.com',
        'business_name' => 'Servana Demo Salon',
    ], $overrides);
}

it('creates user + merchant + profile + owner membership transactionally', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', selfRegisterPayload())
        ->assertStatus(202)
        ->assertJsonStructure(['message']);

    $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
    expect($user->status)->toBe(User::STATUS_ACTIVE);

    $merchant = Merchant::query()->where('created_by', $user->id)->firstOrFail();
    expect($merchant->status)->toBe(MerchantStatus::PendingSetup)
        ->and($merchant->name)->toBe('Servana Demo Salon')
        ->and($merchant->slug)->not->toBeEmpty()
        ->and($merchant->service_fee_tier)->toBeNull()
        ->and($merchant->setup_completed_at)->toBeNull();

    // Shell profile exists (1:1).
    expect(MerchantProfile::query()->where('merchant_id', $merchant->id)->count())->toBe(1);

    // Owner membership: merchant_admin + active.
    $membership = MerchantUser::query()->where('merchant_id', $merchant->id)->firstOrFail();
    expect($membership->role)->toBe(MerchantUserRole::MerchantAdmin)
        ->and($membership->status)->toBe(MerchantUserStatus::Active)
        ->and($membership->user_id)->toBe($user->id);

    // Status history records the initial → pending_setup transition.
    expect(MerchantStatusHistory::query()->where('merchant_id', $merchant->id)
        ->where('to_status', 'pending_setup')->count())->toBe(1);
});

it('sends the owner a Magic Link to begin setup', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', selfRegisterPayload())
        ->assertStatus(202);

    $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
    Notification::assertSentTo($user, MagicLoginLinkNotification::class);
});

it('normalizes the owner email', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', selfRegisterPayload([
        'email' => '  Owner@Example.COM ',
    ]))->assertStatus(202);

    expect(User::query()->where('email', 'owner@example.com')->count())->toBe(1);
});

it('validates required fields with the structured envelope', function (): void {
    $this->postJson('/api/v1/merchant-registration/self-register', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'meta']]);
});

it('does not create a second merchant for an existing email', function (): void {
    Notification::fake();

    // First registration succeeds.
    $this->postJson('/api/v1/merchant-registration/self-register', selfRegisterPayload())
        ->assertStatus(202);

    // Second registration with the same email: identical response, no new rows.
    $this->postJson('/api/v1/merchant-registration/self-register', selfRegisterPayload([
        'owner_name' => 'Someone Else',
        'business_name' => 'Different Business',
    ]))->assertStatus(202);

    expect(User::query()->where('email', 'owner@example.com')->count())->toBe(1)
        ->and(Merchant::query()->count())->toBe(1);
});

it('is rate limited by the registration limiter', function (): void {
    Notification::fake();

    // Limiter: 3/hour per IP.
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/merchant-registration/self-register', selfRegisterPayload([
            'email' => "owner{$i}@example.com",
        ]))->assertStatus(202);
    }

    $this->postJson('/api/v1/merchant-registration/self-register', selfRegisterPayload([
        'email' => 'owner4@example.com',
    ]))->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});
