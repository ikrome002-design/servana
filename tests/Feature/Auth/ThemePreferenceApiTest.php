<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\ThemePreference;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('auth', 'ui04', 'theme', 'authorization');

/*
 |==============================================================================
 | Phase UI-04 — authenticated theme preference (ADR-021 §3; UI/UX plan §12.2).
 |
 | The smallest self-owned preference contract the phase needs: one nullable, CHECK-constrained
 | column on `users`, read through /me and written through PATCH /auth/preferences.
 |
 | The properties that matter are not "it stores a string". They are:
 |   - absence of a choice is DISTINCT from choosing light, and resolves to light;
 |   - the vocabulary is closed, so "follow the operating system" is unrepresentable;
 |   - the write is OWN SCOPE — there is no request shape that addresses another user, which is
 |     why UI-04 adds no permission key.
 */

it('reports no preference and a light resolution for a user who has never chosen', function (): void {
    $user = User::factory()->create(['theme_preference' => null]);

    $response = $this->actingAs($user)->getJson('/api/v1/me');

    $response->assertOk();
    // Null is "never chose" — NOT "chose light". Collapsing the two would make a future default
    // change silently rewrite everybody's unexpressed preference.
    expect($response->json('data.user.theme_preference'))->toBeNull();
    expect($response->json('data.user.resolved_theme'))->toBe('light');
});

it('reports a stored dark preference through the bootstrap', function (): void {
    $user = User::factory()->create(['theme_preference' => ThemePreference::Dark]);

    $response = $this->actingAs($user)->getJson('/api/v1/me');

    $response->assertOk();
    expect($response->json('data.user.theme_preference'))->toBe('dark');
    expect($response->json('data.user.resolved_theme'))->toBe('dark');
});

it('persists an explicit dark choice to the user record', function (): void {
    $user = User::factory()->create(['theme_preference' => null]);

    $response = $this->actingAs($user)->patchJson('/api/v1/auth/preferences', [
        'theme_preference' => 'dark',
    ]);

    $response->assertOk();
    expect($response->json('data.theme_preference'))->toBe('dark');
    expect($response->json('data.resolved_theme'))->toBe('dark');
    expect($user->fresh()?->theme_preference)->toBe(ThemePreference::Dark);
});

it('lets a user clear their preference back to no explicit choice', function (): void {
    $user = User::factory()->create(['theme_preference' => ThemePreference::Dark]);

    $response = $this->actingAs($user)->patchJson('/api/v1/auth/preferences', [
        'theme_preference' => null,
    ]);

    $response->assertOk();
    expect($response->json('data.theme_preference'))->toBeNull();
    // Cleared means "no choice", which resolves to light.
    expect($response->json('data.resolved_theme'))->toBe('light');
    expect($user->fresh()?->theme_preference)->toBeNull();
});

it('rejects any value outside the closed light/dark vocabulary', function (string $value): void {
    $user = User::factory()->create(['theme_preference' => null]);

    $this->actingAs($user)
        ->patchJson('/api/v1/auth/preferences', ['theme_preference' => $value])
        ->assertStatus(422)
        // Plan §11.5 structured envelope: field errors live under `error.fields`.
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['theme_preference'], 'meta']]);

    expect($user->fresh()?->theme_preference)->toBeNull();
})->with([
    // `system` and `auto` are the values ADR-021 rule 2 exists to forbid: they would mean
    // "let the operating system choose", which is exactly UI01-THEME-001.
    'system' => ['system'],
    'auto' => ['auto'],
    'os' => ['os'],
    'arbitrary' => ['midnight'],
    'uppercase' => ['DARK'],
]);

it('treats an empty string as clearing the preference, matching the app-wide convention', function (): void {
    // Laravel's global ConvertEmptyStringsToNull middleware turns `''` into null before validation
    // on EVERY endpoint in this application. Documenting that here rather than pretending `''` is
    // a rejected value: it clears the preference, which is a legitimate action, and the result is
    // "no explicit choice" — light.
    $user = User::factory()->create(['theme_preference' => ThemePreference::Dark]);

    $response = $this->actingAs($user)->patchJson('/api/v1/auth/preferences', [
        'theme_preference' => '',
    ]);

    $response->assertOk();
    expect($response->json('data.theme_preference'))->toBeNull();
    expect($response->json('data.resolved_theme'))->toBe('light');
    expect($user->fresh()?->theme_preference)->toBeNull();
});

it('requires the field to be present, so an empty body cannot silently no-op', function (): void {
    $user = User::factory()->create(['theme_preference' => ThemePreference::Dark]);

    $this->actingAs($user)
        ->patchJson('/api/v1/auth/preferences', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['theme_preference'], 'meta']]);

    expect($user->fresh()?->theme_preference)->toBe(ThemePreference::Dark);
});

it('refuses an unauthenticated write', function (): void {
    $this->patchJson('/api/v1/auth/preferences', ['theme_preference' => 'dark'])
        ->assertUnauthorized();
});

it('writes only the caller\'s own record, whatever the payload claims', function (): void {
    // OWN SCOPE proof. There is no user identifier in the request contract at all, so the test
    // supplies every plausible smuggling shape and proves none of them redirects the write.
    $caller = User::factory()->create(['theme_preference' => null]);
    $victim = User::factory()->create(['theme_preference' => null]);

    $this->actingAs($caller)
        ->patchJson('/api/v1/auth/preferences', [
            'theme_preference' => 'dark',
            'user_id' => $victim->id,
            'user' => $victim->ulid,
            'ulid' => $victim->ulid,
            'email' => $victim->email,
        ])
        ->assertOk();

    expect($caller->fresh()?->theme_preference)->toBe(ThemePreference::Dark);
    expect($victim->fresh()?->theme_preference)->toBeNull();
});

it('refuses a value the database vocabulary does not permit', function (): void {
    // The CHECK is the last line of defence: even a direct write that bypassed validation and the
    // enum cast cannot store "follow the OS".
    $user = User::factory()->create(['theme_preference' => null]);

    expect(static fn () => DB::table('users')->where('id', $user->id)->update(['theme_preference' => 'syste']))
        ->toThrow(QueryException::class);
});

it('adds no permission key for changing your own preference', function (): void {
    // UI-04 makes NO permission-matrix change. This is the assertion that would fail if someone
    // later "tidied" the route by gating it on an invented key instead of ownership.
    $route = app('router')->getRoutes()->getByName('auth.preferences.update');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();
    $permissionMiddleware = array_values(array_filter(
        array_map(static fn (mixed $m): string => is_string($m) ? $m : '', $middleware),
        static fn (string $m): bool => str_contains($m, 'EnsurePermission') || str_starts_with($m, 'can:'),
    ));

    expect($permissionMiddleware)->toBe([]);
});

it('resolves a malformed stored value to light rather than throwing', function (): void {
    // Fail-safe: a display preference must never be able to break a bootstrap.
    expect(ThemePreference::resolve(null))->toBe(ThemePreference::Light);
    expect(ThemePreference::resolve(''))->toBe(ThemePreference::Light);
    expect(ThemePreference::resolve('system'))->toBe(ThemePreference::Light);
    expect(ThemePreference::resolve('dark'))->toBe(ThemePreference::Dark);
});

it('offers no way to express "follow the operating system"', function (): void {
    // ADR-021 rule 2, asserted against the enum itself so a future case addition fails here.
    expect(array_map(
        static fn (ThemePreference $case): string => $case->value,
        ThemePreference::cases(),
    ))->toBe(['light', 'dark']);
});
