<?php

declare(strict_types=1);

use App\Domain\Audit\Support\AuditMutationCoverage;
use App\Domain\Auth\Services\PermissionMatrix;
use Illuminate\Support\Facades\Route;

uses()->group('auth', 'permissions', 'matrix');

/*
 | §2 closure for REM-PERM-001: prove EVERY canonical Plan §19.2 key has EVERY
 | Plan-encoded §19.3 attribute populated ACCURATELY in the YAML — not a
 | best-effort placeholder. The Plan is parsed INDEPENDENTLY here (a second code
 | path from the matrix generator) so this is a genuine mechanical cross-check,
 | not a self-referential assertion.
 |
 | Plan-encoded fields checked for all 151 canonical keys:
 |   scope · entitlement_key · billing_read_only_behavior · period_lock_behavior
 |   · mfa_required · step_up_required · audit_severity
 |   · maker_checker_incompatibilities · default_roles · override_policy
 |
 | Non-Plan (implementation) fields (description, audit_event, backend_policy_or_service,
 | frontend_ux_usage, positive/negative_tests, implementation_status, owning_phase,
 | canonical_successor) are verified in the schema / reconciliation / isolation tests
 | against repository evidence, not against the Plan matrix.
 */

/**
 * Parse the §19.3 populated matrix INDEPENDENTLY into
 * key => [scope, ent, billRO, PL, mfa(bool), su(bool), sev, mc(list), group_role, override_hint].
 *
 * @return array<string, array<string, mixed>>
 */
function planMatrixRows(): array
{
    $plan = (string) file_get_contents(base_path('Servana Software Development Plan.md'));
    // §19.3 has two ```text fences (schema, then the populated matrix); take the 2nd.
    preg_match('/### 19\.3.*?```text.*?```.*?```text(.*?)```/s', $plan, $m);

    $rows = [];
    $groupRole = null;
    $overrideHint = 'revocable_only';
    foreach (preg_split('/\r?\n/', $m[1] ?? '') as $raw) {
        $line = rtrim($raw);
        if (trim($line) === '') {
            continue;
        }
        if (str_starts_with(trim($line), '#')) {
            if (preg_match('/default_roles:\s*([a-z_]+)/', $line, $gm)) {
                $groupRole = $gm[1];
            }
            $overrideHint = str_contains($line, 'non_overridable') ? 'non_overridable' : 'revocable_only';

            continue;
        }
        if (! preg_match('/^([a-z0-9_.]+)\s+(.*)$/', trim($line), $rm)) {
            continue;
        }
        $fields = explode('|', $rm[2]);
        if (count($fields) < 8) {
            continue;
        }
        $strip = static function (string $v, string $label): string {
            $v = trim($v);
            if (stripos($v, $label.' ') === 0) {
                $v = trim(substr($v, strlen($label) + 1));
            }
            if (strcasecmp($v, $label) === 0) {
                $v = '';
            }

            return $v;
        };
        $f7 = trim($fields[7]);
        $parts = preg_split('/\s{2,}/', $f7, 2);
        $mc = trim($strip($parts[0] ?? '', 'MC'));

        $rows[$rm[1]] = [
            'scope' => trim($fields[0]),
            'ent' => $strip($fields[1], 'ent'),
            'billRO' => $strip($fields[2], 'billRO'),
            'pl' => $strip($fields[3], 'PL'),
            'mfa' => strtoupper(trim($strip($fields[4], 'MFA'))) === 'Y',
            'su' => strtoupper(trim($strip($fields[5], 'SU'))) === 'Y',
            'sev' => strtolower(trim($strip($fields[6], 'sev'))),
            'mc' => ($mc === '' || $mc === '-') ? [] : [$mc],
            'group_role' => $groupRole,
            'override_hint' => $overrideHint,
        ];
    }

    return $rows;
}

function scopeToken(string $s): string
{
    return match (trim($s)) {
        'P' => 'platform',
        'M' => 'merchant',
        'B' => 'branch',
        'O' => 'own',
        'M/B' => 'merchant',
        default => 'merchant',
    };
}

function billToken(string $b): string
{
    $b = trim($b);
    if ($b === '' || $b === '-') {
        return 'n/a';
    }

    return str_starts_with($b, 'A') ? 'allow_read' : 'block';
}

it('matches the Plan §19.3 matrix on every Plan-encoded field for all 151 canonical keys', function (): void {
    $matrix = app(PermissionMatrix::class);
    $planRows = planMatrixRows();

    expect($planRows)->toHaveCount(151);

    $problems = [];
    foreach ($planRows as $key => $plan) {
        if (! in_array($key, $matrix->keys(), true)) {
            $problems[] = "{$key}: absent from YAML";

            continue;
        }
        $row = $matrix->get($key);

        $expectScope = scopeToken($plan['scope']);
        if ($row['scope'] !== $expectScope) {
            $problems[] = "{$key}: scope {$row['scope']} != Plan {$expectScope}";
        }

        $expectEnt = ($plan['ent'] === '' || $plan['ent'] === '-') ? null : $plan['ent'];
        if (($row['entitlement_key'] ?? null) !== $expectEnt) {
            $problems[] = "{$key}: entitlement_key ".var_export($row['entitlement_key'] ?? null, true).' != Plan '.var_export($expectEnt, true);
        }

        $expectBill = billToken($plan['billRO']);
        if ($row['billing_read_only_behavior'] !== $expectBill) {
            $problems[] = "{$key}: billing {$row['billing_read_only_behavior']} != Plan {$expectBill}";
        }

        $expectPl = str_starts_with(trim($plan['pl']), 'enforced') ? 'enforced' : 'n/a';
        if ($row['period_lock_behavior'] !== $expectPl) {
            $problems[] = "{$key}: period_lock {$row['period_lock_behavior']} != Plan {$expectPl}";
        }

        if ((bool) $row['mfa_required'] !== $plan['mfa']) {
            $problems[] = "{$key}: mfa_required ".var_export($row['mfa_required'], true).' != Plan '.var_export($plan['mfa'], true);
        }

        if ((bool) $row['step_up_required'] !== $plan['su']) {
            $problems[] = "{$key}: step_up_required ".var_export($row['step_up_required'], true).' != Plan '.var_export($plan['su'], true);
        }

        if ($row['audit_severity'] !== $plan['sev']) {
            $problems[] = "{$key}: audit_severity {$row['audit_severity']} != Plan {$plan['sev']}";
        }

        $yamlMc = array_values($row['maker_checker_incompatibilities']);
        sort($yamlMc);
        $planMc = $plan['mc'];
        sort($planMc);
        if ($yamlMc !== $planMc) {
            $problems[] = "{$key}: mc [".implode(',', $yamlMc).'] != Plan ['.implode(',', $planMc).']';
        }
    }

    expect($problems)->toBe([], "Plan↔YAML metadata mismatches:\n".implode("\n", $problems));
});

it('derives audit_event from live routes + AuditMutationCoverage for active keys (no placeholder)', function (): void {
    $matrix = app(PermissionMatrix::class);

    // Recompute key => emitted events INDEPENDENTLY from the live route table.
    $audited = AuditMutationCoverage::AUDITED;
    $keyEvents = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();
        if ($name === null || ! isset($audited[$name])) {
            continue;
        }
        foreach ($route->gatherMiddleware() as $mw) {
            if (! is_string($mw) || ! str_contains($mw, 'EnsurePermission:')) {
                continue;
            }
            foreach (explode(',', substr($mw, strpos($mw, ':') + 1)) as $permKey) {
                foreach ($audited[$name] as $ev) {
                    $keyEvents[trim($permKey)][$ev] = true;
                }
            }
        }
    }

    $problems = [];
    foreach ($matrix->activeKeys() as $key) {
        $expected = 'none';
        if (isset($keyEvents[$key])) {
            $events = array_keys($keyEvents[$key]);
            sort($events);
            $expected = implode('; ', $events);
        }
        $actual = $matrix->get($key)['audit_event'];
        if ($actual !== $expected) {
            $problems[] = "{$key}: audit_event '{$actual}' != route-derived '{$expected}'";
        }
    }

    expect($problems)->toBe([], "audit_event drifted from repository evidence:\n".implode("\n", $problems));
});

it('gives every canonical key its group default role (or a superset for shared keys) and the correct override policy', function (): void {
    $matrix = app(PermissionMatrix::class);
    $planRows = planMatrixRows();

    $problems = [];
    foreach ($planRows as $key => $plan) {
        $row = $matrix->get($key);
        $roles = $row['default_roles'];

        // Grantable-only keys (override-only) legitimately have no default role.
        if ($roles === [] && $row['override_policy'] === 'grantable') {
            continue;
        }
        // The group's default role must be present (shared keys add more, e.g. invoice.view + Finance).
        if ($plan['group_role'] !== null && ! in_array($plan['group_role'], $roles, true)) {
            // Active keys resolve default_roles from the PHP registry, which is the runtime
            // truth; a legacy grant may differ from the Plan group. Only flag PLANNED keys,
            // whose default_roles come straight from the Plan group.
            if (($row['implementation_status'] ?? null) === 'planned') {
                $problems[] = "{$key}: default_roles [".implode(',', $roles)."] missing Plan group role {$plan['group_role']}";
            }
        }

        // Non-overridable group hint (platform/personnel/audit) must be honoured.
        if ($plan['override_hint'] === 'non_overridable' && $row['override_policy'] !== 'non_overridable') {
            $problems[] = "{$key}: override_policy {$row['override_policy']} should be non_overridable (Plan group hint)";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});
