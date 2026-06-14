<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Notifications\MagicLoginLinkNotification;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth');

it('stores only the SHA-256 hash, never the raw token', function (): void {
    $service = app(MagicLinkTokenService::class);
    $raw = $service->issue('owner@salon.co.ke');

    $row = MagicLoginToken::query()->firstOrFail();

    // The hash matches sha256(raw)…
    expect($row->token_hash)->toBe(hash('sha256', $raw))
        ->and(strlen($row->token_hash))->toBe(64);

    // …and the raw token does not appear in ANY stored attribute.
    foreach ($row->getAttributes() as $value) {
        expect((string) $value)->not->toContain($raw);
    }
});

it('rejects an expired token at the service level', function (): void {
    $service = app(MagicLinkTokenService::class);
    $raw = $service->issue('owner@salon.co.ke');

    MagicLoginToken::query()->update(['expires_at' => now()->subMinute()]);

    expect($service->consume($raw))->toBeNull();
});

it('never writes the raw token to the application log', function (): void {
    Notification::fake();

    $lines = collect();
    Log::listen(function (MessageLogged $event) use ($lines): void {
        $lines->push($event->message.' '.json_encode($event->context));
    });

    $user = User::factory()->create(['email' => 'owner@salon.co.ke']);
    $this->postJson('/api/v1/auth/magic-link', ['email' => 'owner@salon.co.ke'])->assertStatus(202);

    // Recover the raw token from the captured notification (it never persists).
    $raw = null;
    Notification::assertSentTo(
        $user,
        MagicLoginLinkNotification::class,
        function (MagicLoginLinkNotification $notification) use (&$raw): bool {
            $property = new ReflectionProperty($notification, 'rawToken');
            $raw = (string) $property->getValue($notification);

            return true;
        },
    );

    expect($raw)->not->toBeNull();
    expect($lines)->not->toBeEmpty(); // the request WAS audited…
    foreach ($lines as $line) {
        expect($line)->not->toContain($raw); // …but never with the raw token.
    }
});
