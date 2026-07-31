<?php

declare(strict_types=1);

use App\Domain\Auth\Mfa\MfaSession;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| A regenerated session id must not orphan its host session (Phase UI-03)
|--------------------------------------------------------------------------
|
| REGRESSION, found by the UI-03 deployed-origin browser proof.
|
| `host_sessions` is keyed by the Laravel session id. `MfaController::assertSession()` regenerates
| that id at the MFA privilege boundary — correctly — but did not follow the host_sessions row onto
| the new id. The row was then orphaned: the user stayed signed in, but `findBySessionId()` no
| longer recognised the session, so
|
|   * `POST /api/v1/auth/account-contexts/switch` answered 409 — a mandatory-MFA user (Super
|     Administrator, Merchant Admin, Finance) could never switch account contexts after
|     satisfying their challenge, which is exactly when they are most likely to want to;
|   * `GET /api/v1/auth/sessions` lost the current session from the user's own list;
|   * `DELETE /api/v1/auth/sessions/{hostSession}` could not target it.
|
| Magic Link verify and handoff consume regenerate and then BIND, so they were never affected —
| which is why no existing test covered the pattern.
*/

uses(RefreshDatabase::class)->group('auth');

it('follows a host session onto a regenerated session id', function (): void {
    $families = app(SessionFamilyService::class);
    $user = User::factory()->create();
    $family = $families->startFamily($user);

    $hostSession = HostSession::factory()->create([
        'session_family_id' => $family->id,
        'user_id' => $user->id,
        'session_id' => 'old-session-id',
    ]);

    expect($families->rebindSessionId('old-session-id', 'new-session-id'))->toBeTrue();

    expect($families->findBySessionId('new-session-id')?->id)->toBe($hostSession->id)
        ->and($families->findBySessionId('old-session-id'))->toBeNull();
});

it('is a no-op when the previous id owned no host session', function (): void {
    expect(app(SessionFamilyService::class)->rebindSessionId('nothing-here', 'new-id'))->toBeFalse();
});

it('is a no-op when the id did not actually change', function (): void {
    $families = app(SessionFamilyService::class);
    $user = User::factory()->create();
    $family = $families->startFamily($user);

    HostSession::factory()->create([
        'session_family_id' => $family->id,
        'user_id' => $user->id,
        'session_id' => 'same-id',
    ]);

    expect($families->rebindSessionId('same-id', 'same-id'))->toBeFalse()
        ->and($families->findBySessionId('same-id'))->not->toBeNull();
});

it('never resurrects a revoked host session onto a new id', function (): void {
    $families = app(SessionFamilyService::class);
    $user = User::factory()->create();
    $family = $families->startFamily($user);

    HostSession::factory()->create([
        'session_family_id' => $family->id,
        'user_id' => $user->id,
        'session_id' => 'revoked-id',
        'revoked_at' => now(),
        'revoked_reason' => 'global_logout',
    ]);

    expect($families->rebindSessionId('revoked-id', 'new-id'))->toBeFalse()
        ->and($families->findBySessionId('new-id'))->toBeNull();
});

it('keeps the current session switchable after an MFA challenge', function (): void {
    // The end-to-end shape of the defect: assert MFA, then confirm the session is still a known
    // host session. Before the fix the row was orphaned and this resolved to null.
    $families = app(SessionFamilyService::class);
    $session = app('session.store');
    $session->start();

    $user = User::factory()->create();
    $family = $families->startFamily($user);
    HostSession::factory()->create([
        'session_family_id' => $family->id,
        'user_id' => $user->id,
        'session_id' => $session->getId(),
    ]);

    $previous = $session->getId();
    app(MfaSession::class)->markVerified($session);
    $session->regenerate();
    $families->rebindSessionId($previous, $session->getId());

    expect($session->getId())->not->toBe($previous)
        ->and($families->findBySessionId($session->getId()))->not->toBeNull()
        ->and(app(MfaSession::class)->isAsserted($session))->toBeTrue();
});
