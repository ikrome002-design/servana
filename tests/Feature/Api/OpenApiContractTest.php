<?php

declare(strict_types=1);

use App\Support\OpenApi\OpenApiGenerator;
use Illuminate\Support\Facades\Route as RouteFacade;

uses()->group('api', 'openapi');

/*
 | OpenAPI contract (Plan §23, §24; Phase 10 REM-ROUTE-001). The committed
 | docs/api/openapi.json must be byte-current with the route-derived generator,
 | cover every production endpoint, and leak no test-only or future operation.
 */

function committedSpec(): array
{
    return json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

function specOperationIds(array $spec): array
{
    $ids = [];

    foreach ($spec['paths'] ?? [] as $methods) {
        foreach ($methods as $operation) {
            if (isset($operation['operationId'])) {
                $ids[] = $operation['operationId'];
            }
        }
    }

    return $ids;
}

it('keeps docs/api/openapi.json byte-current with the generator', function (): void {
    $generated = app(OpenApiGenerator::class)->toJson();
    $committed = (string) file_get_contents(base_path('docs/api/openapi.json'));

    // Normalize line endings so a CRLF checkout does not cause a false failure.
    expect(str_replace("\r\n", "\n", $committed))
        ->toBe(str_replace("\r\n", "\n", $generated),
            'docs/api/openapi.json is stale — run `composer api:openapi` and commit it.');
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
