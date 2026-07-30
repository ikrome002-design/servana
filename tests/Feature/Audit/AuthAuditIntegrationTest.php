<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth', 'audit');

/*
 | R2: authentication events are recorded to the hash-chained audit_logs via
 | AuditRecorder (AuthEventLogger is gone). Pre-auth events carry a null actor and
 | null merchant, the email is stored ONLY masked, and no raw token / session id
 | ever appears in a row (Plan §9.1, §70).
 */

it('audits a magic link request with a masked email and no actor/merchant', function (): void {
    Notification::fake();
    eligibleOwner('owner@salon.co.ke');

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => 'owner@salon.co.ke'])->assertStatus(202);

    $log = AuditLog::query()->where('action', 'login_link_requested')->latest('id')->firstOrFail();

    expect($log->severity)->toBe(AuditSeverity::Info)
        ->and($log->actor_id)->toBeNull()
        ->and($log->merchant_id)->toBeNull()
        ->and($log->context['email'])->toContain('***')
        ->and($log->context['email'])->not->toBe('owner@salon.co.ke')
        ->and($log->context)->toHaveKey('user_ulid');
});

it('audits a denied request for an unknown email without leaking it', function (): void {
    postOnHost('merchant_administrator', '/api/v1/auth/magic-link', ['email' => 'ghost@nowhere.test'])->assertStatus(202);

    $log = AuditLog::query()->where('action', 'login_link_denied')->latest('id')->firstOrFail();

    expect($log->severity)->toBe(AuditSeverity::Warning)
        ->and($log->context['email'])->toContain('***')
        ->and($log->context['email'])->not->toContain('ghost@nowhere.test');
});

it('audits a failed verify for an invalid token and stores no token value', function (): void {
    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => 'totally-invalid-token'])->assertStatus(422);

    $log = AuditLog::query()->where('action', 'login_link_failed')->latest('id')->firstOrFail();

    expect($log->severity)->toBe(AuditSeverity::Warning)
        ->and($log->actor_id)->toBeNull()
        // The raw token is never persisted anywhere in the row.
        ->and(json_encode($log->getAttributes()))->not->toContain('totally-invalid-token');
});

it('audits a successful login on token consume', function (): void {
    eligibleOwner('login@salon.co.ke');
    $raw = issueBoundMagicLink('login@salon.co.ke');

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(200);

    $log = AuditLog::query()->where('action', 'login_success')->latest('id')->firstOrFail();

    expect($log->severity)->toBe(AuditSeverity::Info)
        ->and($log->context['email'])->toContain('***')
        ->and(json_encode($log->getAttributes()))->not->toContain($raw);
});

it('audits a logout', function (): void {
    $user = eligibleOwner('out@salon.co.ke');

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(AuditLog::query()->where('action', 'logout')->exists())->toBeTrue();
});
