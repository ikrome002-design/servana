<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Notifications\MagicLoginLinkNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth');

it('returns a uniform 202 for an eligible user and sends the link', function (): void {
    Notification::fake();
    $user = eligibleOwner('owner@salon.co.ke');

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'owner@salon.co.ke'])
        ->assertStatus(202)
        ->assertJsonPath('message', 'If the email exists and is active, a link was sent.');

    expect(MagicLoginToken::query()->where('email', 'owner@salon.co.ke')->count())->toBe(1);
    Notification::assertSentTo($user, MagicLoginLinkNotification::class);
});

it('returns the same 202 and sends nothing when the email does not exist', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'ghost@nowhere.co.ke'])
        ->assertStatus(202)
        ->assertJsonPath('message', 'If the email exists and is active, a link was sent.');

    expect(MagicLoginToken::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('sends nothing for a suspended user but still returns 202', function (): void {
    Notification::fake();
    User::factory()->suspended()->create(['email' => 'suspended@salon.co.ke']);

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'suspended@salon.co.ke'])
        ->assertStatus(202);

    expect(MagicLoginToken::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('normalizes the email before lookup and storage', function (): void {
    Notification::fake();
    $user = eligibleOwner('owner@salon.co.ke');

    $this->postJson('/api/v1/auth/magic-link', ['email' => '  Owner@Salon.CO.KE '])
        ->assertStatus(202);

    expect(MagicLoginToken::query()->where('email', 'owner@salon.co.ke')->count())->toBe(1);
    Notification::assertSentTo($user, MagicLoginLinkNotification::class);
});

it('rejects an invalid email with a structured validation envelope', function (): void {
    $this->postJson('/api/v1/auth/magic-link', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['email'], 'meta']]);
});

it('throttles repeated requests for the same email with a structured 429', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'busy@salon.co.ke']);

    // Limiter: 3/min per email.
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/auth/magic-link', ['email' => 'busy@salon.co.ke'])
            ->assertStatus(202);
    }

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'busy@salon.co.ke'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');
});
