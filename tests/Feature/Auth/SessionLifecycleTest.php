<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

uses(RefreshDatabase::class)->group('auth');

it('rejects /me for a guest with a 401 envelope', function (): void {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('returns the bootstrap payload for an authenticated user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(200)
        ->assertJsonPath('data.id', $user->ulid)
        ->assertJsonPath('data.memberships', [])
        ->assertJsonPath('data.permissions', []);
});

it('logs out an authenticated user with 204', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();
});

it('rejects logout for a guest', function (): void {
    $this->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('rejects a session idle beyond the timeout (middleware)', function (): void {
    $middleware = new EnforceIdleTimeout;

    $request = Request::create('/api/v1/me', 'GET');
    $session = app('session')->driver('array');
    $session->put('last_activity_at', now()->subMinutes(61)->getTimestamp());
    $request->setLaravelSession($session);

    expect(fn () => $middleware->handle($request, fn (Request $r): Response => new Response('ok')))
        ->toThrow(AuthenticationException::class);
});

it('allows and refreshes an active (non-idle) session (middleware)', function (): void {
    $middleware = new EnforceIdleTimeout;

    $request = Request::create('/api/v1/me', 'GET');
    $session = app('session')->driver('array');
    $session->put('last_activity_at', now()->subMinutes(1)->getTimestamp());
    $request->setLaravelSession($session);

    $response = $middleware->handle($request, fn (Request $r): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
    // The activity clock was advanced toward "now".
    expect($session->get('last_activity_at'))->toBeGreaterThan(now()->subMinutes(1)->getTimestamp());
});
