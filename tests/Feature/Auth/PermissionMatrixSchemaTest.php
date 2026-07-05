<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionMatrix;

uses()->group('auth', 'permissions', 'matrix');

/*
 | §19.5 schema-validation: docs/auth/permission-matrix.yaml carries a COMPLETE,
 | schema-valid entry for every key. The build fails if any key is missing any
 | §19.3 attribute or any attribute is unset / out of its enumerated domain.
 */

it('populates every required §19.3 attribute for every key (no nulls except the nullable ones)', function (): void {
    $matrix = app(PermissionMatrix::class);
    $nullable = ['entitlement_key', 'owning_phase', 'canonical_successor'];

    $problems = [];
    foreach ($matrix->all() as $key => $row) {
        foreach (PermissionMatrix::REQUIRED_ATTRIBUTES as $attr) {
            if (! array_key_exists($attr, $row)) {
                $problems[] = "{$key}: missing attribute {$attr}";

                continue;
            }
            $value = $row[$attr];
            if (in_array($attr, $nullable, true)) {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                // List attributes may legitimately be []: no MC pairs, or an
                // override-only (grantable) key that has no DEFAULT role. Scalars
                // may not be null/empty. positive/negative_tests are always populated.
                if (in_array($attr, ['maker_checker_incompatibilities', 'default_roles'], true)) {
                    continue;
                }
                $problems[] = "{$key}: attribute {$attr} is unset";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('constrains every enumerated attribute to its §19.3 domain', function (): void {
    $matrix = app(PermissionMatrix::class);

    $problems = [];
    foreach ($matrix->all() as $key => $row) {
        $checks = [
            'implementation_status' => PermissionMatrix::IMPLEMENTATION_STATUSES,
            'override_policy' => PermissionMatrix::OVERRIDE_POLICIES,
            'scope' => PermissionMatrix::SCOPES,
            'billing_read_only_behavior' => PermissionMatrix::BILLING_BEHAVIORS,
            'period_lock_behavior' => PermissionMatrix::PERIOD_LOCK_BEHAVIORS,
            'audit_severity' => PermissionMatrix::SEVERITIES,
        ];
        foreach ($checks as $attr => $domain) {
            if (! in_array($row[$attr] ?? null, $domain, true)) {
                $problems[] = sprintf('%s: %s=%s not in {%s}', $key, $attr, var_export($row[$attr] ?? null, true), implode('|', $domain));
            }
        }
        foreach (['mfa_required', 'step_up_required'] as $boolAttr) {
            if (! is_bool($row[$boolAttr] ?? null)) {
                $problems[] = "{$key}: {$boolAttr} must be boolean";
            }
        }
        // The key attribute must equal the map key.
        if (($row['key'] ?? null) !== $key) {
            $problems[] = "{$key}: key attribute mismatch";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('names a positive and negative test for every key per the §19.3 convention', function (): void {
    $matrix = app(PermissionMatrix::class);

    foreach ($matrix->all() as $key => $row) {
        expect($row['positive_tests'])->toContain("PermissionMatrix/{$key}_allows");
        expect($row['negative_tests'])->toContain("PermissionMatrix/{$key}_denies");
    }
});
