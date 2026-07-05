<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use RuntimeException;

/**
 * Loader for the source-controlled canonical permission security contract
 * (`docs/auth/permission-matrix.yaml`, Plan §19.2/§19.3). This is the machine
 * side of the §19.5 parity harness: the schema/completeness/parity tests and the
 * TypeScript generator all read the matrix through this one loader so the four
 * projections (YAML ↔ PHP registry ↔ DB ↔ TypeScript) can never silently drift.
 *
 * A dependency-free reader is used deliberately: the file is emitted in a small,
 * fixed 2-space-indented subset of YAML (scalars + simple string lists), so a
 * bespoke parser keeps the contract self-contained (no new runtime dependency)
 * and its grammar under our control.
 *
 * `implementation_status`:
 *   - `active`  → enforced at runtime NOW; MUST equal a PermissionRegistry key.
 *   - `planned` → canonical (Plan §19.2) but owned by a future phase; never a
 *                 runtime grant, never projected to the DB, never in the TS set.
 */
final class PermissionMatrix
{
    /** The required §19.3 schema attributes for every key (CI fails if any is unset). */
    public const REQUIRED_ATTRIBUTES = [
        'key',
        'description',
        'implementation_status',
        'default_roles',
        'override_policy',
        'scope',
        'billing_read_only_behavior',
        'period_lock_behavior',
        'entitlement_key',
        'mfa_required',
        'step_up_required',
        'audit_event',
        'audit_severity',
        'maker_checker_incompatibilities',
        'backend_policy_or_service',
        'frontend_ux_usage',
        'positive_tests',
        'negative_tests',
    ];

    public const IMPLEMENTATION_STATUSES = ['active', 'planned'];

    public const OVERRIDE_POLICIES = ['grantable', 'revocable_only', 'non_overridable'];

    public const SCOPES = ['platform', 'merchant', 'branch', 'own'];

    public const BILLING_BEHAVIORS = ['allow_read', 'block', 'n/a'];

    public const PERIOD_LOCK_BEHAVIORS = ['enforced', 'n/a'];

    public const SEVERITIES = ['info', 'warn', 'high', 'crit'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? base_path('docs/auth/permission-matrix.yaml');
    }

    /** @return array<string, array<string, mixed>> key => attributes */
    public function all(): array
    {
        return $this->cache ??= $this->parse(file_get_contents($this->path()) ?: '');
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return list<string> keys enforced at runtime now */
    public function activeKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            static fn (array $row): bool => ($row['implementation_status'] ?? null) === 'active',
        ));
    }

    /** @return list<string> canonical keys owned by a future phase */
    public function plannedKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            static fn (array $row): bool => ($row['implementation_status'] ?? null) === 'planned',
        ));
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        $all = $this->all();
        if (! isset($all[$key])) {
            throw new RuntimeException("Unknown permission-matrix key: {$key}");
        }

        return $all[$key];
    }

    /**
     * Minimal deterministic parser for the fixed matrix grammar:
     *
     *   version: <int>
     *   keys:
     *     <key>:
     *       <attr>: <scalar>
     *       <listAttr>: []            # empty list
     *       <listAttr>:
     *         - <item>
     *
     * @return array<string, array<string, mixed>>
     */
    private function parse(string $yaml): array
    {
        /** @var array<string, array<string, mixed>> $out */
        $out = [];
        $currentKey = null;
        $currentAttr = null;
        $inKeys = false;

        foreach (preg_split('/\r?\n/', $yaml) ?: [] as $line) {
            if ($line === '' || preg_match('/^\s*#/', $line)) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $trimmed = trim($line);

            if ($indent === 0) {
                $inKeys = ($trimmed === 'keys:');

                continue;
            }

            if (! $inKeys) {
                continue;
            }

            if ($indent === 2 && str_ends_with($trimmed, ':')) {
                // "<key>:"
                $currentKey = (string) $this->scalar(rtrim($trimmed, ':'));
                $out[$currentKey] = [];
                $currentAttr = null;

                continue;
            }

            if ($indent === 4 && $currentKey !== null) {
                if (preg_match('/^([a-z_]+):\s*(.*)$/', $trimmed, $m)) {
                    $attr = $m[1];
                    $value = $m[2];
                    if ($value === '') {
                        // opens a list block
                        $out[$currentKey][$attr] = [];
                        $currentAttr = $attr;
                    } elseif ($value === '[]') {
                        $out[$currentKey][$attr] = [];
                        $currentAttr = null;
                    } else {
                        $out[$currentKey][$attr] = $this->scalar($value);
                        $currentAttr = null;
                    }
                }

                continue;
            }

            if ($indent === 6 && $currentKey !== null && $currentAttr !== null && str_starts_with($trimmed, '- ')) {
                $list = $out[$currentKey][$currentAttr] ?? [];
                if (is_array($list)) {
                    $list[] = $this->scalar(substr($trimmed, 2));
                    $out[$currentKey][$currentAttr] = $list;
                }
            }
        }

        return $out;
    }

    private function scalar(string $raw): string|int|bool|null
    {
        $raw = trim($raw);
        if ($raw === 'null') {
            return null;
        }
        if ($raw === 'true') {
            return true;
        }
        if ($raw === 'false') {
            return false;
        }
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($raw, 1, -1));
        }

        return $raw;
    }
}
