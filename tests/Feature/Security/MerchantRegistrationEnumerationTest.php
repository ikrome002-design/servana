<?php

declare(strict_types=1);

use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('onboarding', 'security');

/*
 | Self-registration must not let an attacker discover which emails already have
 | an account (Plan §9.1 enumeration rule applied to onboarding). A new email and
 | an existing email must produce byte-identical responses, and the existing email
 | must create no new state.
 */

it('returns identical responses for a new email and an existing email', function (): void {
    Notification::fake();

    // Seed an existing user/merchant via a first registration.
    $this->postJson('/api/v1/merchant-registration/self-register', [
        'owner_name' => 'Existing Owner',
        'email' => 'taken@example.com',
        'business_name' => 'Existing Salon',
    ])->assertStatus(202);

    $existing = $this->postJson('/api/v1/merchant-registration/self-register', [
        'owner_name' => 'Attacker Guess',
        'email' => 'taken@example.com',
        'business_name' => 'Probe Business',
    ]);

    $fresh = $this->postJson('/api/v1/merchant-registration/self-register', [
        'owner_name' => 'New Owner',
        'email' => 'brand-new@example.com',
        'business_name' => 'New Salon',
    ]);

    expect($existing->status())->toBe($fresh->status())->toBe(202);
    expect($existing->json())->toEqual($fresh->json());
});

it('creates no duplicate user or merchant when the email already exists', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', [
        'owner_name' => 'Existing Owner',
        'email' => 'taken@example.com',
        'business_name' => 'Existing Salon',
    ])->assertStatus(202);

    $merchantsBefore = Merchant::query()->count();

    $this->postJson('/api/v1/merchant-registration/self-register', [
        'owner_name' => 'Probe',
        'email' => 'taken@example.com',
        'business_name' => 'Probe Business',
    ])->assertStatus(202);

    expect(User::query()->where('email', 'taken@example.com')->count())->toBe(1)
        ->and(Merchant::query()->count())->toBe($merchantsBefore);
});

it('sends no Magic Link on a duplicate-email registration attempt', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', [
        'owner_name' => 'Existing Owner',
        'email' => 'taken@example.com',
        'business_name' => 'Existing Salon',
    ])->assertStatus(202);

    $user = User::query()->where('email', 'taken@example.com')->firstOrFail();
    Notification::fake(); // reset captured notifications

    $this->postJson('/api/v1/merchant-registration/self-register', [
        'owner_name' => 'Probe',
        'email' => 'taken@example.com',
        'business_name' => 'Probe Business',
    ])->assertStatus(202);

    Notification::assertNothingSent();
});
