<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 | Ephemeral routes that throw the relevant exceptions. They live under /api/v1
 | so the renderer's shouldHandle() matches, and carry no middleware group
 | (global middleware — incl. correlation id — still runs).
 */
beforeEach(function (): void {
    Route::get('/api/v1/__test/boom', fn () => throw new RuntimeException('secret internal detail: db=postgres pwd=hunter2'));
    Route::get('/api/v1/__test/forbidden', fn () => abort(403));
    Route::get('/api/v1/__test/throttle', fn () => abort(429));
    Route::get('/api/v1/__test/unauth', fn () => throw new AuthenticationException);
    Route::post('/api/v1/__test/validate', function (Request $request) {
        $request->validate(['email' => 'required|email']);

        return response()->json(['ok' => true]);
    });
});

it('returns a not_found envelope for unknown api routes', function (): void {
    $this->getJson('/api/v1/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'meta']]);
});

it('returns a validation_failed envelope with field errors', function (): void {
    $this->postJson('/api/v1/__test/validate', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['email'], 'meta']]);
});

it('maps authentication exceptions to a 401 unauthenticated envelope', function (): void {
    $this->getJson('/api/v1/__test/unauth')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('maps forbidden to a 403 permission_denied envelope', function (): void {
    $this->getJson('/api/v1/__test/forbidden')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('maps too-many-requests to a 429 rate_limited envelope', function (): void {
    $this->getJson('/api/v1/__test/throttle')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');
});

it('returns a generic 500 envelope that leaks no internals and carries a correlation id', function (): void {
    $response = $this->getJson('/api/v1/__test/boom');

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonPath('error.message', 'An unexpected error occurred.');

    expect($response->json('error.meta.correlation_id'))->not->toBeNull();
    expect($response->getContent())
        ->not->toContain('secret internal detail')
        ->not->toContain('hunter2');
    expect($response->headers->get('X-Correlation-ID'))
        ->toBe($response->json('error.meta.correlation_id'));
});
