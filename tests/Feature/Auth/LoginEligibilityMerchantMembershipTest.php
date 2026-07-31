<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Notifications\MagicLoginLinkNotification;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth', 'onboarding');

/*
 | Phase 6 turns on Scope §2.3 checks 2 & 4: a Magic Link is issued only to a user
 | with an active merchant membership (or platform staff). Denied requests send no
 | email and the response is uniform (no enumeration). Check 6 (branch assignment)
 | stays deferred to Phase 7 — a branch_manager with an active membership but no
 | branch assignment is still eligible today.
 */

it('sends a link to a user with an active merchant membership', function (): void {
    Notification::fake();
    $user = eligibleOwner('owner@salon.co.ke');

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => 'owner@salon.co.ke'])->assertStatus(202);

    Notification::assertSentTo($user, MagicLoginLinkNotification::class);
});

it('sends a link to a pending_setup merchant owner', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'pending@salon.co.ke']);
    $merchant = Merchant::factory()->create(); // pending_setup
    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => 'pending@salon.co.ke'])->assertStatus(202);

    Notification::assertSentTo($user, MagicLoginLinkNotification::class);
});

it('sends a link to platform staff with no merchant', function (): void {
    Notification::fake();
    $user = User::factory()->platformStaff()->create(['email' => 'super@servana.africa']);

    // Platform staff hold the `super_administrator` account and no merchant account. Asking on the
    // Merchant Administrator host is correctly answered with the uniform 202 and no email.
    postOnHost('super_administrator', '/api/v1/auth/magic-link', ['email' => 'super@servana.africa'])->assertStatus(202);

    Notification::assertSentTo($user, MagicLoginLinkNotification::class);
});

it('sends no link to a user without any merchant membership', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'orphan@salon.co.ke']);

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => 'orphan@salon.co.ke'])->assertStatus(202);

    Notification::assertNothingSent();
    expect(MagicLoginToken::query()->count())->toBe(0);
});

it('sends no link when the only membership is suspended', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'suspended-member@salon.co.ke']);
    $merchant = Merchant::factory()->active()->create();
    MerchantUser::factory()->suspended()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
    ]);

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => 'suspended-member@salon.co.ke'])->assertStatus(202);

    Notification::assertNothingSent();
});

it('sends no link when the only membership is deactivated', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'gone-member@salon.co.ke']);
    $merchant = Merchant::factory()->active()->create();
    MerchantUser::factory()->deactivated()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
    ]);

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => 'gone-member@salon.co.ke'])->assertStatus(202);

    Notification::assertNothingSent();
});

it('denies consume when the membership is suspended after the link was issued', function (): void {
    $user = User::factory()->create(['email' => 'owner@salon.co.ke']);
    $merchant = Merchant::factory()->active()->create();
    $membership = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
    ]);

    $raw = issueBoundMagicLink('owner@salon.co.ke');

    // Membership suspended between issue and consume → consume re-check denies.
    $membership->update(['status' => MerchantUserStatus::Suspended]);

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_token');

    $this->assertGuest();
});

it('sends no link to a branch_manager without a branch assignment (check 6, Phase 7)', function (): void {
    // Phase 7 enforces eligibility check 6: a branch-scoped role with no active
    // branch_user_assignment is not eligible. Detailed coverage lives in
    // BranchAssignmentEligibilityTest; this guards the Phase 6→7 contract change.
    Notification::fake();
    $user = User::factory()->create(['email' => 'bm@salon.co.ke']);
    $merchant = Merchant::factory()->active()->create();
    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::BranchManager,
    ]);

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => 'bm@salon.co.ke'])->assertStatus(202);

    Notification::assertNothingSent();
});
