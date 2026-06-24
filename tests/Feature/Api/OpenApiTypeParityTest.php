<?php

declare(strict_types=1);

uses()->group('api', 'openapi');

/*
 | OpenAPI ⇄ TypeScript parity (Plan §12, §23; Phase 10). The committed generated
 | client types must describe every path/operation in the OpenAPI document, with
 | no test-only leakage. Byte-level TS staleness is additionally enforced by
 | `npm run api:contract:check` (frontend CI).
 */

function generatedTypes(): string
{
    return (string) file_get_contents(base_path('resources/spa/src/types/generated/api.ts'));
}

it('has a generated TypeScript contract committed', function (): void {
    expect(file_exists(base_path('resources/spa/src/types/generated/api.ts')))->toBeTrue();
    expect(generatedTypes())->toContain('export interface paths');
});

it('represents every OpenAPI path in the generated types', function (): void {
    $spec = committedSpec();
    $types = generatedTypes();
    $missing = [];

    foreach (array_keys($spec['paths'] ?? []) as $path) {
        if (! str_contains($types, '"'.$path.'"')) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
});

it('represents every OpenAPI operation id in the generated types', function (): void {
    $types = generatedTypes();
    $missing = [];

    foreach (specOperationIds(committedSpec()) as $id) {
        if (! str_contains($types, 'operations["'.$id.'"]')) {
            $missing[] = $id;
        }
    }

    expect($missing)->toBe([]);
});

it('leaks no test-only route into the generated types', function (): void {
    $types = generatedTypes();

    expect($types)->not->toContain('/testing/')
        ->and($types)->not->toContain('operations["testing.');
});

it('carries the scramble-generated component schemas into the types', function (): void {
    // The TS is generated from the Scramble-authored spec, so its component schemas
    // (e.g. BranchResource) flow through to the generated `components["schemas"]`.
    $types = generatedTypes();

    expect($types)->toContain('BranchResource');
});
