<?php

declare(strict_types=1);

use App\Support\OpenApi\OpenApiGenerator;
use Dedoc\Scramble\Generator as ScrambleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;

// dedoc/scramble infers attribute and route-key types by introspecting the live
// database schema (ULID keys → string, booleans, integer counters, nullability).
// The "byte-current" test below regenerates the document, so it MUST run against a
// fully migrated schema — otherwise Scramble reads an empty schema and emits
// fallback types (integer ids, string booleans), making generation non-deterministic
// across environments and parallel workers (the PR #21 CI failure). RefreshDatabase
// guarantees the migrated schema in serial Pest, parallel Pest and fresh CI alike.
uses(RefreshDatabase::class)->group('api', 'openapi');

/*
 | OpenAPI contract (Plan §23, §24; Phase 10 REM-ROUTE-001). The maintained
 | dedoc/scramble generator is authoritative for schema generation; the Servana
 | wrapper applies determinism, full paths, testing exclusion, operationId=route
 | name, the security scheme, the error envelope and the financial Idempotency-Key.
 | The committed docs/api/openapi.json must be byte-current with that pipeline,
 | cover every production endpoint, and leak no test-only or future operation.
 */

it('keeps docs/api/openapi.json byte-current with the generator', function (): void {
    $generated = app(OpenApiGenerator::class)->toJson();
    $committed = (string) file_get_contents(base_path('docs/api/openapi.json'));

    // Normalize line endings so a CRLF checkout does not cause a false failure.
    expect(str_replace("\r\n", "\n", $committed))
        ->toBe(str_replace(
            "\r\n",
            "\n",
            $generated,
        ), 'docs/api/openapi.json is stale — run `composer api:openapi` and commit it.');
});

it('documents every production route as an operation', function (): void {
    $ids = specOperationIds(committedSpec());
    $missing = [];

    foreach (app(OpenApiGenerator::class)->productionRoutes() as $route) {
        $name = $route->getName();

        if ($name !== null && ! in_array($name, $ids, true)) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([]);
});

it('includes both health probes', function (): void {
    $paths = array_keys(committedSpec()['paths'] ?? []);

    expect($paths)->toContain('/health')->toContain('/health/deep');
});

it('contains no test-only or future operations', function (): void {
    $spec = committedSpec();
    $leaks = [];

    foreach (array_keys($spec['paths'] ?? []) as $path) {
        if (str_contains($path, '/testing/')) {
            $leaks[] = $path;
        }
    }

    foreach (specOperationIds($spec) as $id) {
        if (str_starts_with($id, 'testing.')) {
            $leaks[] = $id;
        }
    }

    // No path may describe a route that does not actually exist.
    $realNames = [];

    foreach (RouteFacade::getRoutes() as $route) {
        if ($name = $route->getName()) {
            $realNames[$name] = true;
        }
    }

    foreach (specOperationIds($spec) as $id) {
        if (! isset($realNames[$id])) {
            $leaks[] = 'nonexistent:'.$id;
        }
    }

    expect($leaks)->toBe([]);
});

it('has no duplicate operation ids', function (): void {
    $ids = specOperationIds(committedSpec());
    $dupes = array_values(array_unique(array_diff_assoc($ids, array_unique($ids))));

    expect($dupes)->toBe([]);
});

it('declares the error envelope and the session security scheme', function (): void {
    $spec = committedSpec();

    expect($spec['components']['schemas']['ErrorEnvelope'] ?? null)->not->toBeNull()
        ->and($spec['components']['securitySchemes']['sanctumSession'] ?? null)->not->toBeNull();
});

it('declares dedoc/scramble as a direct production dependency', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['dedoc/scramble'] ?? null)->not->toBeNull();
});

it('uses the maintained scramble generator as the authoritative schema engine', function (): void {
    // The wrapper depends on Scramble's Generator (not a hand-rolled engine).
    $constructor = (new ReflectionClass(OpenApiGenerator::class))->getConstructor();
    $dependsOnScramble = false;

    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && $type->getName() === ScrambleGenerator::class) {
            $dependsOnScramble = true;
        }
    }

    expect($dependsOnScramble)->toBeTrue();

    // And the committed document carries schemas only Scramble infers from the
    // Resources / Form Requests — proving it (not the wrapper) generated them.
    $schemas = committedSpec()['components']['schemas'] ?? [];

    expect($schemas)->toHaveKey('BranchResource')
        ->and($schemas)->toHaveKey('CreateBranchRequest');
});

it('preserves the scramble-inferred pagination/filter/sort query parameters', function (): void {
    $params = committedSpec()['paths']['/api/v1/branches']['get']['parameters'] ?? [];
    $names = array_column($params, 'name');

    // per_page (pagination), sort (allowlisted), status (validated filter) — all
    // inferred by Scramble from BranchIndexRequest and preserved through the wrapper.
    expect($names)->toContain('per_page')->toContain('sort')->toContain('status');
});
