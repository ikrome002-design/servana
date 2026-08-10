<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class)->group('platform-feature-flags', 'ui08', 'ui08-feature-flags');

/*
 | COR-UI08-001 §12 — the platform feature-flag API (navigation map §5.4.20).
 |
 | The catalogue is CODE. The API can read it, propose changes to it and pause a flag — it can never
 | create a key, and it adds no permission of its own.
 */

function ui08ApiFlagAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);

    return $user;
}

/**
 * Register a catalogue definition for the duration of one test.
 *
 * NOTE: the whole `flags` array is replaced rather than `flags.{key}` being set, because a flag key
 * CONTAINS DOTS and `config()->set()` treats a dot as nesting — `flags.joined.flag` would create
 * `flags['joined']['flag']`, which the catalogue would never find.
 */
function ui08RegisterFlag(string $key, array $overrides = []): void
{
    $flags = config('platform-feature-flags.flags', []);

    $flags[$key] = array_merge([
        'owner' => 'platform',
        'description' => 'A flag registered for this test.',
        'risk_class' => 'low',
        'environments' => ['testing'],
        'target_types' => ['merchant'],
        'dependencies' => [],
        'affected_screen_keys' => ['platform-feature-flags'],
        'affected_operation_ids' => ['platform.feature-flags.index'],
        'health_metric_key' => null,
        'rollback_criterion' => 'Pause the flag and request a change back to inactive.',
        'external_gate' => null,
    ], $overrides);

    config()->set('platform-feature-flags.flags', $flags);
}

// --- Permission authority -----------------------------------------------------------------------

it('adds no feature-flag-specific permission key', function (): void {
    $matrix = Yaml::parseFile(base_path('docs/auth/permission-matrix.yaml'));

    foreach (array_keys($matrix['keys']) as $key) {
        expect(str_contains((string) $key, 'feature_flag'))
            ->toBeFalse('COR-UI08-001 authorizes no feature-flag permission key, but found '.$key);
    }

    // The matrix total is still the two internal-access keys and nothing else.
    expect($matrix['keys'])->toHaveCount(169);
});

it('gates every route on the existing platform settings keys', function (): void {
    foreach (RouteFacade::getRoutes() as $route) {
        $name = $route->getName() ?? '';

        if (! str_starts_with($name, 'platform.feature-flag')) {
            continue;
        }

        $gates = array_values(array_filter(
            app('router')->gatherRouteMiddleware($route),
            static fn (string $middleware): bool => str_contains($middleware, 'EnsurePermission'),
        ));

        expect($gates)->toHaveCount(1, $name);

        $expected = array_diff($route->methods(), ['GET', 'HEAD']) === []
            ? ':platform.settings.view'
            : ':platform.settings.update';

        expect(str_contains($gates[0], $expected))->toBeTrue($name.' must be gated by '.$expected);
    }
});

// --- Catalogue ----------------------------------------------------------------------------------

it('serves a truthful empty catalogue when no flag has been authorized', function (): void {
    // The shipped catalogue is empty on purpose: no platform flag has been authorized.
    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/feature-flags')
        ->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.catalogue_is_empty'))->toBeTrue()
        ->and($response->json('meta.catalogue_source'))->toBe('config/platform-feature-flags.php');
});

it('joins the code definition to the per-environment state', function (): void {
    ui08RegisterFlag('joined.flag');
    PlatformFeatureFlag::factory()->forKey('joined.flag')->active()->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/feature-flags/joined.flag')
        ->assertOk();

    expect($response->json('data.definition.key'))->toBe('joined.flag')
        ->and($response->json('data.definition.owner'))->toBe('platform')
        ->and($response->json('data.state.state'))->toBe('active')
        ->and($response->json('data.effective_state'))->toBe('active');
});

it('reports a definition with no state row as inactive, not as missing', function (): void {
    ui08RegisterFlag('unset.flag');

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/feature-flags/unset.flag')
        ->assertOk();

    expect($response->json('data.state'))->toBeNull()
        // Absence is the default, and the default is off.
        ->and($response->json('data.effective_state'))->toBe('inactive');
});

it('never invents a health metric', function (): void {
    ui08RegisterFlag('nometric.flag', ['health_metric_key' => null]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/feature-flags/nometric.flag')
        ->assertOk();

    // Null means "no health metric available" — never a fabricated zero.
    expect($response->json('data.definition.health_metric_key'))->toBeNull()
        ->and($response->json('data.definition.health_metric_available'))->toBeFalse();
});

it('rejects an unknown key everywhere', function (): void {
    $admin = ui08ApiFlagAdmin();

    foreach ([
        '/api/v1/platform/feature-flags/never.defined',
        '/api/v1/platform/feature-flags/never.defined/history',
    ] as $route) {
        test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
            ->getJson($route)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'feature_flag_not_found');
    }

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/feature-flags/never.defined/change-requests', [
            'state' => 'active',
            'rollout_basis_points' => 10000,
            'impact_statement' => 'Attempting to mint a flag key through the API.',
            'rollback_plan' => 'There is nothing to roll back.',
            'health_criterion' => 'Not applicable to a nonexistent flag.',
            'reason' => 'This must be refused.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertNotFound();
});

it('exposes no route that could create a flag key', function (): void {
    $creates = [];

    foreach (RouteFacade::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_contains($uri, 'feature-flag')) {
            continue;
        }

        // A bare POST to the collection would be a create endpoint. Only nested change-requests,
        // decisions and pause exist.
        if ($uri === 'api/v1/platform/feature-flags' && in_array('POST', $route->methods(), true)) {
            $creates[] = $uri;
        }
    }

    expect($creates)->toBe([], 'the catalogue is code; the API must never create a flag key');
});

// --- Authorization ------------------------------------------------------------------------------

it('denies a merchant user', function (): void {
    $scn = invoiceScenario();

    test()->statefulMfa(now()->getTimestamp())->actingAs($scn['actor'], 'sanctum')
        ->getJson('/api/v1/platform/feature-flags')
        ->assertForbidden();
});

it('denies an unauthenticated caller', function (): void {
    // Its own case on purpose: actingAs() leaves an authenticated session behind, so a second call
    // in the same test would be authenticated-but-forbidden (403), not unauthenticated (401).
    test()->getJson('/api/v1/platform/feature-flags')->assertUnauthorized();
});

it('refuses a mutation when the step-up is stale but still allows the read', function (): void {
    ui08RegisterFlag('stepup.flag');
    PlatformFeatureFlag::factory()->forKey('stepup.flag')->active()->create();

    $admin = ui08ApiFlagAdmin();
    $stale = now()->subHours(2)->getTimestamp();

    test()->statefulMfa($stale)->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/platform/feature-flags')
        ->assertOk();

    test()->statefulMfa($stale)->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/feature-flags/stepup.flag/pause', ['reason' => 'Stale step-up attempt.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertForbidden();

    expect(PlatformFeatureFlag::query()->where('flag_key', 'stepup.flag')->first()?->state)
        ->toBe(PlatformFeatureFlagState::Active);
});

// --- Pause --------------------------------------------------------------------------------------

it('pauses with a single actor, because pause only ever restricts', function (): void {
    ui08RegisterFlag('pause.flag');
    PlatformFeatureFlag::factory()->forKey('pause.flag')->active()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/feature-flags/pause.flag/pause', ['reason' => 'Error rate exceeded the rollback criterion.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk()
        ->assertJsonPath('data.state.state', 'paused');

    expect(AuditLog::query()->where('action', 'platform.feature_flag.paused')->exists())->toBeTrue();
});

it('requires a reason to pause', function (): void {
    ui08RegisterFlag('pause.reason');
    PlatformFeatureFlag::factory()->forKey('pause.reason')->active()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/feature-flags/pause.reason/pause', [],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422);

    expect(PlatformFeatureFlag::query()->where('flag_key', 'pause.reason')->first()?->state)
        ->toBe(PlatformFeatureFlagState::Active);
});

// --- Change requests ----------------------------------------------------------------------------

it('proposes a change and records it in the append-only history', function (): void {
    ui08RegisterFlag('propose.flag');

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/feature-flags/propose.flag/change-requests', [
            'state' => 'active',
            'rollout_basis_points' => 2500,
            'effective_from' => now()->addDay()->toIso8601String(),
            'impact_statement' => 'Enables the capability for a quarter of targeted merchants.',
            'rollback_plan' => 'Pause the flag, then request a change back to inactive.',
            'health_criterion' => 'Error rate stays below the current baseline for 24 hours.',
            'reason' => 'Beginning a staged rollout.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertCreated();

    expect($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.impact_statement'))->toBeString()
        ->and($response->json('data.proposed_configuration_hash'))->toHaveLength(64);

    expect(AuditLog::query()->where('action', 'platform.feature_flag.change_requested')->exists())->toBeTrue();

    $history = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/feature-flags/propose.flag/history')
        ->assertOk();

    expect($history->json('data.0.action'))->toBe('change_requested')
        ->and($history->json('meta.append_only'))->toBeTrue();
});

it('refuses a target type the definition does not support', function (): void {
    ui08RegisterFlag('targets.flag', ['target_types' => ['plan']]);

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/feature-flags/targets.flag/change-requests', [
            'state' => 'active',
            'rollout_basis_points' => 10000,
            'targets' => [['type' => 'merchant', 'value' => (string) Str::ulid()]],
            'impact_statement' => 'Targets a merchant the definition does not permit.',
            'rollback_plan' => 'Not applicable; this must be refused.',
            'health_criterion' => 'Not applicable; this must be refused.',
            'reason' => 'Unsupported target type.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'feature_flag.target_type_not_supported');
});

it('refuses a rollout outside 0 to 10000 basis points', function (): void {
    ui08RegisterFlag('bounds.flag');

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->postJson('/api/v1/platform/feature-flags/bounds.flag/change-requests', [
            'state' => 'active',
            'rollout_basis_points' => 10001,
            'impact_statement' => 'An out-of-range rollout must be refused.',
            'rollback_plan' => 'Not applicable.',
            'health_criterion' => 'Not applicable.',
            'reason' => 'Out of range.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422);
});

it('keeps environments isolated', function (): void {
    ui08RegisterFlag('isolated.flag', ['environments' => ['testing']]);

    // A production row for the same key must not affect the testing answer.
    PlatformFeatureFlag::factory()->forKey('isolated.flag', 'production')->active()->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiFlagAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/feature-flags/isolated.flag')
        ->assertOk();

    expect($response->json('data.state'))->toBeNull()
        ->and($response->json('data.effective_state'))->toBe('inactive');
});
