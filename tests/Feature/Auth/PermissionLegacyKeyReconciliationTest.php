<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;

uses()->group('auth', 'permissions', 'matrix');

/*
 | §2 item 5: the 17 legacy-active runtime keys (active, but not yet renamed to
 | their canonical §19.2 form) are reconciled honestly — a real PLANNED successor
 | (or null where §19.2 has no equivalent), a valid owning phase, no duplicate
 | successor, and no two names for the same CURRENTLY-active authority.
 */

/** @return list<string> the canonical §19.2 keys, parsed independently from the Plan. */
function reconCanonicalKeys(): array
{
    $plan = (string) file_get_contents(base_path('Servana Software Development Plan.md'));
    preg_match('/### 19\.2.*?```text(.*?)```/s', $plan, $m);
    $keys = [];
    foreach (preg_split('/\r?\n/', $m[1] ?? '') as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        foreach (explode('|', $line) as $tok) {
            $tok = trim($tok);
            if (preg_match('/^[a-z0-9_.]+$/', $tok)) {
                $keys[$tok] = true;
            }
        }
    }

    return array_keys($keys);
}

/** @return list<string> active keys that are NOT canonical §19.2 keys. */
function legacyActiveKeys(PermissionMatrix $matrix): array
{
    $canonical = array_fill_keys(reconCanonicalKeys(), true);

    return array_values(array_filter(
        $matrix->activeKeys(),
        static fn (string $k): bool => ! isset($canonical[$k]),
    ));
}

it('carries exactly the 12 known legacy-active keys', function (): void {
    // Phase 20A retired 3 legacy platform keys (platform.settings.manage,
    // platform.billing.configure, platform.fee_rules.manage) by activating their canonical
    // successors and deleting the legacy rows — 17 → 14. Phase 20B retired 2 more
    // (merchant.tier.update → merchant.subscription.plan_change; platform.merchants.govern,
    // truthfully SPLIT into platform.merchant.suspend/reactivate/deactivate) — 14 → 12.
    $legacy = legacyActiveKeys(app(PermissionMatrix::class));

    expect($legacy)->toHaveCount(12);
});

it('reconciles every legacy key to a PLANNED successor (or null) and a valid owning phase', function (): void {
    $matrix = app(PermissionMatrix::class);
    $registry = app(PermissionRegistry::class);
    $planned = array_fill_keys($matrix->plannedKeys(), true);
    $active = array_fill_keys($registry->permissionKeys(), true);

    $problems = [];
    $seenSuccessors = [];
    foreach (legacyActiveKeys($matrix) as $key) {
        $row = $matrix->get($key);

        // The legacy key is a real runtime key.
        if (! isset($active[$key])) {
            $problems[] = "{$key}: not in the PHP registry";
        }

        $successor = $row['canonical_successor'] ?? null;
        if ($successor !== null) {
            // A successor must be a PLANNED canonical key — never one that is already
            // active (that would be two names for the same current authority).
            if (! isset($planned[$successor])) {
                $problems[] = "{$key}: successor {$successor} is not a planned canonical key";
            }
            if (isset($active[$successor])) {
                $problems[] = "{$key}: successor {$successor} is already active (duplicate authority)";
            }
            // No two legacy keys may silently reconcile to the same successor.
            if (isset($seenSuccessors[$successor])) {
                $problems[] = "{$key}: successor {$successor} already claimed by {$seenSuccessors[$successor]}";
            }
            $seenSuccessors[$successor] = $key;
        }

        // Owning phase is required and must be a recognised phase token.
        $phase = $row['owning_phase'] ?? null;
        if ($phase === null || ! preg_match('/^Phase (V|R\d|\d+[A-Z]?N?S?)$/', (string) $phase)) {
            $problems[] = "{$key}: owning_phase ".var_export($phase, true).' is not a valid phase token';
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('never marks an active canonical key with a legacy successor or owning phase', function (): void {
    $matrix = app(PermissionMatrix::class);
    $canonical = array_fill_keys(reconCanonicalKeys(), true);

    foreach ($matrix->activeKeys() as $key) {
        if (! isset($canonical[$key])) {
            continue; // legacy — handled above
        }
        $row = $matrix->get($key);
        // An active canonical key is already in its final form: no successor, no owning phase.
        expect($row['canonical_successor'] ?? null)->toBeNull("active canonical {$key} must not carry a successor");
        expect($row['owning_phase'] ?? null)->toBeNull("active canonical {$key} must not carry an owning phase");
    }
});
