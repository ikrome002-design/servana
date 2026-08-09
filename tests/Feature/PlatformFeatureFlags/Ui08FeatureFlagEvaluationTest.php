<?php

declare(strict_types=1);

use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagTarget;
use App\Domain\PlatformFeatureFlags\Services\PlatformFeatureFlagEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('platform-feature-flags', 'ui08', 'ui08-feature-flags');

/*
 | COR-UI08-001 §12.4 — the evaluator fails closed, in order, and can only ever RESTRICT.
 |
 | The single most important property proven here: a flag may turn an otherwise-authorized
 | capability off, and may NEVER turn an unauthorized capability on. Everything else follows.
 */

/**
 * Register a catalogue definition for the duration of one test.
 *
 * The whole `flags` array is replaced rather than `flags.{key}` being set, because a flag key
 * CONTAINS DOTS and `config()->set()` treats a dot as nesting.
 */
function ui08DefineFlag(string $key, array $overrides = []): void
{
    $flags = config('platform-feature-flags.flags', []);

    $flags[$key] = array_merge([
        'owner' => 'platform',
        'description' => 'A flag defined for this test.',
        'risk_class' => 'low',
        'environments' => ['testing'],
        'target_types' => ['merchant'],
        'dependencies' => [],
        'affected_screen_keys' => [],
        'affected_operation_ids' => [],
        'health_metric_key' => null,
        'rollback_criterion' => 'Pause the flag.',
        'external_gate' => null,
    ], $overrides);

    config()->set('platform-feature-flags.flags', $flags);
}

function ui08Evaluator(): PlatformFeatureFlagEvaluator
{
    return app(PlatformFeatureFlagEvaluator::class);
}

// --- Fail-closed order --------------------------------------------------------------------------

it('denies a key the code allowlist does not contain', function (): void {
    // The catalogue ships EMPTY, so any key is unknown until a test defines one.
    $decision = ui08Evaluator()->decide('never.defined');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe('unknown_flag_key');
});

it('denies a flag whose definition does not support this environment', function (): void {
    ui08DefineFlag('env.bound', ['environments' => ['production']]);
    PlatformFeatureFlag::factory()->forKey('env.bound')->active()->create();

    expect(ui08Evaluator()->decide('env.bound')->reason)->toBe('environment_not_supported');
});

it('denies a flag with no state row for this environment', function (): void {
    ui08DefineFlag('no.state');

    // Absence is the default, and the default is OFF.
    expect(ui08Evaluator()->decide('no.state')->reason)->toBe('no_state_for_environment');
});

it('denies every state except active', function (): void {
    ui08DefineFlag('states.check');

    foreach (['inactive', 'scheduled', 'paused', 'retired'] as $state) {
        PlatformFeatureFlag::query()->where('flag_key', 'states.check')->delete();

        PlatformFeatureFlag::factory()->forKey('states.check')->create([
            'state' => $state,
            'rollout_basis_points' => 10000,
            'effective_from' => now()->subDay(),
        ]);

        expect(ui08Evaluator()->decide('states.check')->allowed)
            ->toBeFalse('state '.$state.' must not allow');
    }
});

it('denies outside the effective window and allows inside it', function (): void {
    ui08DefineFlag('window.check');

    $flag = PlatformFeatureFlag::factory()->forKey('window.check')->active()->create([
        'effective_from' => now()->addDay(),
    ]);
    expect(ui08Evaluator()->decide('window.check')->reason)->toBe('not_yet_effective');

    $flag->forceFill(['effective_from' => now()->subDay(), 'effective_to' => now()->subHour()])->save();
    expect(ui08Evaluator()->decide('window.check')->reason)->toBe('no_longer_effective');

    $flag->forceFill(['effective_to' => now()->addDay()])->save();
    expect(ui08Evaluator()->decide('window.check')->allowed)->toBeTrue();
});

it('denies an untargeted subject when targets exist', function (): void {
    ui08DefineFlag('targeted.check');
    $flag = PlatformFeatureFlag::factory()->forKey('targeted.check')->active()->create();

    $targeted = (string) Str::ulid();
    PlatformFeatureFlagTarget::factory()->for($flag, 'flag')->forSubject($targeted)->create();

    expect(ui08Evaluator()->decide('targeted.check', $targeted)->allowed)->toBeTrue()
        ->and(ui08Evaluator()->decide('targeted.check', (string) Str::ulid())->reason)->toBe('subject_not_targeted')
        // No subject at all is denied rather than quietly included.
        ->and(ui08Evaluator()->decide('targeted.check')->reason)->toBe('no_subject_for_targeted_flag');
});

it('buckets a rollout deterministically and never randomly', function (): void {
    ui08DefineFlag('rollout.check');
    PlatformFeatureFlag::factory()->forKey('rollout.check')->active(5000)->create();

    $subject = (string) Str::ulid();

    // The same subject lands in the same bucket every time — a rollout only ever widens.
    $first = ui08Evaluator()->decide('rollout.check', $subject)->allowed;

    for ($i = 0; $i < 5; $i++) {
        expect(ui08Evaluator()->decide('rollout.check', $subject)->allowed)->toBe($first);
    }

    expect(ui08Evaluator()->bucket('rollout.check', $subject))
        ->toBe(ui08Evaluator()->bucket('rollout.check', $subject))
        ->toBeLessThan(10000)
        ->toBeGreaterThanOrEqual(0);
});

it('denies a zero rollout and allows a full one', function (): void {
    ui08DefineFlag('rollout.bounds');

    $flag = PlatformFeatureFlag::factory()->forKey('rollout.bounds')->active(0)->create();
    expect(ui08Evaluator()->decide('rollout.bounds', (string) Str::ulid())->reason)->toBe('rollout_zero');

    $flag->forceFill(['rollout_basis_points' => 10000])->save();
    expect(ui08Evaluator()->decide('rollout.bounds')->allowed)->toBeTrue();
});

it('denies a partial rollout with no subject rather than flipping a coin', function (): void {
    ui08DefineFlag('rollout.nosubject');
    PlatformFeatureFlag::factory()->forKey('rollout.nosubject')->active(5000)->create();

    expect(ui08Evaluator()->decide('rollout.nosubject')->reason)->toBe('no_subject_for_partial_rollout');
});

// --- The non-bypass properties ------------------------------------------------------------------

it('cannot open or bypass an external gate', function (): void {
    // A flag that is active, fully rolled out and correctly targeted STILL denies behind a gate.
    ui08DefineFlag('gated.capability', ['external_gate' => 'W']);
    PlatformFeatureFlag::factory()->forKey('gated.capability')->active()->create();

    $decision = ui08Evaluator()->decide('gated.capability');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe('external_gate_closed');

    // And there is no way to open it: the gate is evidence-based, not configuration.
    expect(ui08Evaluator()->externalGateIsOpen('W'))->toBeFalse();
});

it('exposes no route or column that could open a gate', function (): void {
    // Gate state is not persisted anywhere, so no write path can exist for it.
    expect(Schema::hasTable('external_gates'))->toBeFalse()
        ->and(Schema::hasColumn('platform_feature_flags', 'external_gate'))->toBeFalse()
        ->and(Schema::hasColumn('platform_feature_flags', 'gate_open'))->toBeFalse();
});

it('grants nothing: the evaluator answers only about rollout', function (): void {
    /*
     | The structural argument for "a flag can never grant an unauthorized capability": the
     | evaluator has no access to permissions, entitlements, billing state or account context, and
     | returns a boolean about ROLLOUT alone. There is no method on it that could authorize anything.
     */
    $methods = get_class_methods(PlatformFeatureFlagEvaluator::class);

    expect($methods)->toBe(['__construct', 'allows', 'decide', 'bucket', 'externalGateIsOpen']);

    foreach ($methods as $method) {
        foreach (['permission', 'entitlement', 'billing', 'authorize', 'can'] as $forbidden) {
            expect(str_contains(strtolower($method), $forbidden))
                ->toBeFalse('the evaluator must not expose an authorization-shaped method: '.$method);
        }
    }

    $source = (string) file_get_contents(base_path('app/Domain/PlatformFeatureFlags/Services/PlatformFeatureFlagEvaluator.php'));

    // It never consults an authorization authority, so it cannot substitute for one.
    foreach (['TenantContext', 'EnsurePermission', 'PermissionResolver', 'Gate::'] as $forbidden) {
        expect(str_contains($source, $forbidden))->toBeFalse('the evaluator referenced '.$forbidden);
    }
});

it('is never evaluated in the browser', function (): void {
    // No client-side counterpart exists; the frontend receives resulting booleans as UX hints only.
    $spa = base_path('resources/spa/src');

    $matches = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($spa));

    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'vue'], true)) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (str_contains($contents, 'rollout_basis_points') && str_contains($contents, 'crc32')) {
            $matches[] = $file->getPathname();
        }
    }

    expect($matches)->toBe([], 'rollout bucketing must never be reimplemented client-side');
});
