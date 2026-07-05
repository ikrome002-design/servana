<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionMatrix;

uses()->group('auth', 'permissions', 'matrix');

/*
 | §19.5 parity (TypeScript leg): the committed frontend permission metadata is
 | GENERATED from the YAML contract and must not drift. This test is the CI
 | equivalent of `php artisan servana:permission-types --check`.
 */

function generatedPermissionsTs(): string
{
    return (string) file_get_contents(base_path('resources/spa/src/types/generated/permissions.ts'));
}

it('is up to date with the canonical contract (servana:permission-types --check)', function (): void {
    $exit = Artisan::call('servana:permission-types', ['--check' => true]);

    expect($exit)->toBe(0, "resources/spa/src/types/generated/permissions.ts is stale — run `php artisan servana:permission-types`.\n".Artisan::output());
});

it('exports exactly the active runtime keys — no planned key leaks to the frontend', function (): void {
    $matrix = app(PermissionMatrix::class);
    $ts = generatedPermissionsTs();

    // Extract the PERMISSION_KEYS tuple entries.
    preg_match('/export const PERMISSION_KEYS = \[(.*?)\] as const;/s', $ts, $m);
    preg_match_all("/'([a-z0-9_.]+)'/", $m[1] ?? '', $km);
    $tsKeys = collect($km[1])->unique()->sort()->values()->all();

    $active = collect($matrix->activeKeys())->sort()->values()->all();

    expect($tsKeys)->toBe($active, 'TypeScript key set drifted from the active YAML set');

    foreach ($matrix->plannedKeys() as $planned) {
        expect($tsKeys)->not->toContain($planned, "planned key {$planned} must not appear in the frontend metadata");
    }
});

it('carries the mfa/step-up metadata for the audit surface keys', function (): void {
    $ts = generatedPermissionsTs();

    expect($ts)->toContain("'audit.export': { key: 'audit.export', scope: 'branch', mfaRequired: false, stepUpRequired: true }");
    expect($ts)->toContain("'finance.audit.view': { key: 'finance.audit.view', scope: 'branch', mfaRequired: true, stepUpRequired: false }");
});
