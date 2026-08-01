<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Notifications\MagicLoginLinkNotification;
use App\Domain\Auth\Services\MagicLinkTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth');

it('stores only the SHA-256 hash, never the raw token', function (): void {
    eligibleOwner('owner@salon.co.ke');
    $raw = issueBoundMagicLink('owner@salon.co.ke');

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
    eligibleOwner('owner@salon.co.ke');
    $service = app(MagicLinkTokenService::class);
    $raw = issueBoundMagicLink('owner@salon.co.ke');

    MagicLoginToken::query()->update(['expires_at' => now()->subMinute()]);

    expect($service->consume($raw, 'merchant_administrator', accountHostName('merchant_administrator'), 'testing'))->toBeNull();
});

it('audits the request to audit_logs and never writes the raw token to the log or the audit row', function (): void {
    Notification::fake();

    $lines = collect();
    Log::listen(function (MessageLogged $event) use ($lines): void {
        $lines->push($event->message.' '.json_encode($event->context));
    });

    $user = eligibleOwner('owner@salon.co.ke');
    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => 'owner@salon.co.ke'])->assertStatus(202);

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

    // R2: the request is audited to the hash-chained audit_logs table (no longer
    // the application log). The raw token must appear in NEITHER the audit row…
    $audit = AuditLog::query()->where('action', 'login_link_requested')->latest('id')->firstOrFail();
    expect(json_encode($audit->getAttributes()))->not->toContain($raw);

    // …NOR any application log line that happened to be emitted.
    foreach ($lines as $line) {
        expect($line)->not->toContain($raw);
    }
});
