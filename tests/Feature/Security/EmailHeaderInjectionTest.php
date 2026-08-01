<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth', 'security');

/*
 | Regression for GHSA-5vg9-5847-vvmq / CVE-2026-48019 — "CRLF injection in
 | Laravel's default email rule" (high). Laravel <12.60 accepted addresses
 | containing CR/LF, enabling mail-header injection. Fixed by the Laravel 12
 | upgrade (resolved 12.62.0). Servana validates every public email through a
 | FormRequest using `email:rfc` (Plan §9.1, RequestMagicLinkRequest), so an
 | address with an EMBEDDED CR/LF must be rejected with the structured 422
 | envelope and no Magic Link may be sent.
 |
 | Only embedded CR/LF is asserted: the request normalizer trims surrounding
 | whitespace, so a purely trailing newline is a different (benign) case.
 */
it('rejects an email containing embedded CR/LF with a validation error', function (string $payload): void {
    Notification::fake();

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => $payload])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['email'], 'meta']]);

    Notification::assertNothingSent();
})->with([
    'CRLF header injection' => ["owner@salon.co.ke\r\nBcc: attacker@evil.test"],
    'LF after address' => ["owner@salon.co.ke\nattacker@evil.test"],
    'CR after address' => ["owner@salon.co.ke\rattacker@evil.test"],
    'CRLF inside local part' => ["ow\r\nner@salon.co.ke"],
]);
