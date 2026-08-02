<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionMatrix;
use Illuminate\Support\Facades\Route;

uses()->group('traceability', 'phase23', 'contracts');

/*
 |==============================================================================
 | Phase 23 Increment 5 — requirement-traceability enforcement (Plan §85; REM-TRACE-001).
 |
 | `docs/traceability/servana-requirements.csv` was maintained by hand and never gated, so
 | it drifted: statuses went stale when an owning phase merged, one launch requirement sat at
 | `not_implemented`, and two rows carried narrative PROSE in the `status` column. This guard
 | makes the CSV a checked contract instead of a document.
 |
 | It is deliberately OFFLINE and deterministic: it reads the CSV, the live route table, the
 | screen inventory and the filesystem. It never calls a network service, and it never
 | requires a route that a documented external gate keeps deliberately absent.
 */

/** The closed status vocabulary (Plan §85; documented in docs/traceability/README.md). */
const P23_TRACE_STATUSES = [
    // Owning phase merged with green CI and phase-completion evidence.
    'verified_complete',
    // Implemented and green locally; the owning phase's PR is not merged yet.
    'local_complete',
    // Code is present but the owning phase has produced no completion evidence yet.
    'implemented',
    // Only the architecture/contract is adopted; no runtime exists by design.
    'architecture_adopted',
    // Deliberately absent behind a NAMED external gate (Gate W and its dependents).
    'blocked_external_gate',
    // Deliberately deferred to a NAMED later phase.
    'deferred_future_phase',
    // Genuinely not applicable.
    'not_applicable',
];

/** Every §85 column, in order. */
const P23_TRACE_COLUMNS = [
    'scope_section', 'requirement_id', 'description', 'phase', 'db_objects', 'service_or_action',
    'controller_or_endpoint', 'policy_and_permission', 'frontend_route_and_component',
    'queue_or_scheduler', 'audit_event', 'automated_tests', 'manual_verification', 'status', 'evidence',
];

/**
 * Phases whose work is MERGED and verified, so a `verified_complete` row may name them.
 * Sourced from docs/PROGRESS.md phase lifecycle entries; a phase absent here may not carry
 * `verified_complete`, which is what caught the stale Phase 19/20F/20G rows.
 *
 * @var list<string>
 */
const P23_VERIFIED_PHASES = [
    '3', '4', '5', '5-7', '6', '7', '7-9', '8', '8/R2', '9',
    'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'gate', 'v4-adoption',
    '10', '10F', '11', '15A', '15B', '16A', '16B', '16C', '17',
    '18A', '18B', '19', '20A', '20B', '20C', '20E', '20F', '20G', '20H',
    '21R-A', '21S', '22', '23', '24',
    // UI-00 merged as PR #50, squash d3f6e10, parent db3827b, CI run 30425113792 (five checks
    // SUCCESS). Reconciled live on the phase-ui-01-as-built-browser-audit branch.
    'UI-00',
    // UI-01 merged as PR #51, squash 413c146, sole parent d3f6e10, final head 5c52372, source
    // tree == merge tree e00866f, merged 2026-07-29T12:33:28Z, CI run 30450612654 (five checks
    // SUCCESS), governance comment 5117766612, reviewDecision blank with 0 submitted reviews.
    // Reconciled live on the phase-ui-02-multi-host-foundation branch.
    'UI-01',
    // UI-02 merged as PR #52, squash fb64ba6, sole parent 413c146, implementation commit
    // db3ace4, final head 5add80c (`ci: build SPA before backend shell tests` — a tested
    // CI-contract correction touching only .github/workflows/ci.yml, NOT a governance-only
    // commit), source tree == merge tree 442ed1d, merged 2026-07-30T10:38:01Z, CI run
    // 30532318808 attempt 1 (five checks SUCCESS), governance comment 5129527972,
    // reviewDecision blank with 0 submitted reviews. Reconciled live on the
    // phase-ui-03-auth-session-account-switching branch.
    'UI-02',
    // UI-03 merged as PR #53 — a REGULAR merge commit 00c9c1e0025e3979464691be662915ada872cc18
    // deliberately preserving four reviewed commits (64ca7cc implementation, 415d2f5
    // deployed-origin browser proof, 5bd6e12 and 182f2cc fixture-only payout-test corrections),
    // parents in order fb64ba67… then 182f2cca…, merged 2026-08-01T07:08:07Z, CI run 30688440846
    // attempt 1 (five checks SUCCESS, backend 3108 passed / 5 skipped / 0 failed), governance
    // comment 5150328091, reviewDecision blank with 0 submitted reviews. Reconciled live on the
    // phase-ui-04-design-system-shared-components branch.
    'UI-03',
    // UI-04 merged as PR #54 — a SQUASH commit e6afe832fa9b45c4f452bcd43e19338ac87bfd9a with the
    // single parent 00c9c1e0025e3979464691be662915ada872cc18, reviewed head
    // cf36cee837fa5f724a9e0d8b3018c9c868ce6697 whose tree bd6728fb… is identical to the merge tree,
    // merged 2026-08-02T13:37:16Z. Final CI run 30748616089 (five checks SUCCESS); the earlier run
    // 30746233065 failed ONLY on gitleaks against one exact fingerprint — a reproducible SHA-256
    // design-token digest, verified false positive, closed by a single historical fingerprint with
    // no rule, path, entropy or workflow weakening. Governance comment 5158172398 records the
    // product-owner approval of the #FDBA74 hover value; reviewDecision blank with 0 submitted
    // reviews. Reconciled live on the phase-ui-05-content-asset-pipeline branch.
    'UI-04',
];

/**
 * The corrective UI/UX programme (Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md
 * §25). UI-00 … UI-04 are merged and verified; UI-05 is in flight; UI-06 … UI-17 have
 * not started. They are listed here so a UI requirement can be deferred to a NAMED owner phase
 * instead of disappearing from the matrix.
 *
 * @var list<string>
 */
const P23_UI_PHASES_VERIFIED = ['UI-00', 'UI-01', 'UI-02', 'UI-03', 'UI-04'];

/** @var list<string> */
const P23_UI_PHASES_UNVERIFIED = [
    'UI-05', 'UI-06', 'UI-07', 'UI-08',
    'UI-09', 'UI-10', 'UI-11', 'UI-12', 'UI-13', 'UI-14', 'UI-15', 'UI-16', 'UI-17',
];

/** Every UI phase, verified or not — the known-phase set a UI row may name. */
const P23_UI_PHASES = [...P23_UI_PHASES_VERIFIED, ...P23_UI_PHASES_UNVERIFIED];

/** Phases that exist but are NOT verified complete — the only phases a non-verified row may name. */
const P23_UNVERIFIED_PHASES = ['20D-W', '21R-B', '21N', '25', ...P23_UI_PHASES_UNVERIFIED];

/**
 * The phase currently IN FLIGHT — its rows may never claim `verified_complete`, because a phase is
 * only verified once its PR is merged with green CI and recorded governance evidence. Advance this
 * constant when the in-flight phase merges and the next phase's branch reconciles it (the same
 * convention that promoted Phase 23 after PR #48 merged as 13f54a4, Phase 24 after PR #49 merged
 * as db3827b, Phase UI-00 after PR #50 merged as d3f6e10, and Phase UI-01 after PR #51 merged
 * as 413c146, Phase UI-02 after PR #52 merged as fb64ba6, Phase UI-03 after PR #53 merged as
 * the regular merge commit 00c9c1e, and Phase UI-04 after PR #54 merged as the squash commit
 * e6afe832).
 */
const P23_IN_FLIGHT_PHASE = 'UI-05';

/**
 * Phases a `deferred_future_phase` row may name: the remaining backend phases plus every UI phase
 * that has not started. The in-flight phase is excluded — its own work is not "deferred".
 *
 * @var list<string>
 */
const P23_DEFERRABLE_PHASES = ['21N', '25', 'UI-05', 'UI-06',
    'UI-07', 'UI-08', 'UI-09', 'UI-10', 'UI-11', 'UI-12', 'UI-13', 'UI-14', 'UI-15', 'UI-16', 'UI-17'];

/** @return list<array<string, string>> */
function p23TraceRows(): array
{
    $path = base_path('docs/traceability/servana-requirements.csv');
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the traceability CSV.');
    }

    $header = fgetcsv($handle);
    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        if ($line === [null] || $line === []) {
            continue;
        }
        /** @var list<string> $header */
        $rows[] = array_combine($header, $line);
    }
    fclose($handle);

    return $rows;
}

/** @return list<string> the CSV header, read exactly as written */
function p23TraceHeader(): array
{
    $handle = fopen(base_path('docs/traceability/servana-requirements.csv'), 'r');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the traceability CSV.');
    }
    $header = fgetcsv($handle);
    fclose($handle);

    /** @var list<string> $header */
    return $header;
}

it('carries every Plan §85 column, in order', function (): void {
    expect(p23TraceHeader())->toBe(P23_TRACE_COLUMNS);
});

it('gives every row a unique, non-blank requirement id', function (): void {
    $ids = [];
    $problems = [];

    foreach (p23TraceRows() as $index => $row) {
        $id = trim($row['requirement_id']);
        if ($id === '') {
            $problems[] = 'row '.($index + 2).': blank requirement_id';

            continue;
        }
        if (isset($ids[$id])) {
            $problems[] = "duplicate requirement_id: {$id}";
        }
        $ids[$id] = true;
    }

    expect($problems)->toBe([], implode("\n", $problems));
    expect(count($ids))->toBeGreaterThan(50);
});

it('fills every required cell — a blank is an unproven claim', function (): void {
    // Every column must carry SOMETHING (an explicit `n/a` where genuinely not applicable);
    // `automated_tests` and `evidence` may never be blank because they are the proof.
    $problems = [];

    foreach (p23TraceRows() as $row) {
        $id = $row['requirement_id'];
        foreach (P23_TRACE_COLUMNS as $column) {
            if (trim((string) $row[$column]) === '') {
                $problems[] = "{$id}: {$column} is blank";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('uses only the closed status vocabulary — no prose, no invented status', function (): void {
    $problems = [];

    foreach (p23TraceRows() as $row) {
        $id = $row['requirement_id'];
        $status = $row['status'];

        if (preg_match('/[\r\n]/', $status) === 1) {
            $problems[] = "{$id}: status contains a line break (narrative belongs in `evidence`)";

            continue;
        }
        if ($status !== trim($status)) {
            $problems[] = "{$id}: status has surrounding whitespace";
        }
        if (! in_array($status, P23_TRACE_STATUSES, true)) {
            $problems[] = sprintf('%s: status %s is not in the closed vocabulary', $id, var_export($status, true));
        }
    }

    expect($problems)->toBe([], implode("\n", array_merge(
        $problems,
        ['', 'Allowed: '.implode(' · ', P23_TRACE_STATUSES)],
        ['Evidence detail belongs in the `evidence` column, never in `status`.'],
    )));
});

it('never leaves a launch requirement `not_implemented` at the Phase 23 gate', function (): void {
    // Phase 23 is the release-audit gate. A launch requirement is either delivered, locally
    // complete, deliberately blocked behind a NAMED gate, deliberately deferred to a NAMED
    // phase, or not applicable. `not_implemented` / `partially_implemented` say nothing about
    // ownership and were how SRV-AUDIT-004 stayed wrong while the work was actually shipped.
    $offenders = [];

    foreach (p23TraceRows() as $row) {
        if (in_array($row['status'], ['not_implemented', 'partially_implemented'], true)) {
            $offenders[] = $row['requirement_id'];
        }
    }

    expect($offenders)->toBe([], 'Rows still using a rejected status: '.implode(', ', $offenders));
});

it('names a known phase on every row', function (): void {
    $known = array_merge(P23_VERIFIED_PHASES, P23_UNVERIFIED_PHASES);
    $problems = [];

    foreach (p23TraceRows() as $row) {
        $phase = trim($row['phase']);
        if ($phase === '') {
            $problems[] = "{$row['requirement_id']}: blank phase";

            continue;
        }
        if (! in_array($phase, $known, true)) {
            $problems[] = "{$row['requirement_id']}: unknown phase '{$phase}'";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('never claims `verified_complete` for a phase that is not verified complete', function (): void {
    $problems = [];

    foreach (p23TraceRows() as $row) {
        if ($row['status'] !== 'verified_complete') {
            continue;
        }
        if (! in_array(trim($row['phase']), P23_VERIFIED_PHASES, true)) {
            $problems[] = sprintf(
                '%s: verified_complete but owning phase %s is not verified complete',
                $row['requirement_id'],
                $row['phase'],
            );
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('keeps the in-flight phase honest — never verified_complete before its PR merges', function (): void {
    $inFlight = array_values(array_filter(
        p23TraceRows(),
        static fn (array $row): bool => trim($row['phase']) === P23_IN_FLIGHT_PHASE,
    ));

    // Rows appear as the in-flight phase's work lands; the invariant that matters is that none of
    // them may claim verification before the PR merges with green CI and governance evidence.
    foreach ($inFlight as $row) {
        expect($row['status'])->not->toBe(
            'verified_complete',
            $row['requirement_id'].': Phase '.P23_IN_FLIGHT_PHASE
                .' cannot be verified_complete before PR merge and CI/governance verification',
        );
    }
});

it('holds the in-flight phase out of the verified-phase list', function (): void {
    expect(P23_VERIFIED_PHASES)->not->toContain(P23_IN_FLIGHT_PHASE);
    expect(P23_UNVERIFIED_PHASES)->toContain(P23_IN_FLIGHT_PHASE);
});

it('proves a blocked requirement is blocked by a NAMED gate with real absence evidence', function (): void {
    $problems = [];

    foreach (p23TraceRows() as $row) {
        if ($row['status'] !== 'blocked_external_gate') {
            continue;
        }
        $id = $row['requirement_id'];

        // The owning phase must be one of the genuinely blocked phases.
        if (! in_array(trim($row['phase']), ['20D-W', '21R-B', '21N'], true)) {
            $problems[] = "{$id}: blocked_external_gate but phase {$row['phase']} is not a gate-blocked phase";
        }

        // The block must be named, not implied.
        $haystack = $row['evidence'].' '.$row['manual_verification'];
        if (! preg_match('/Gate W|External Gate|§80\.1|§80\.2/u', $haystack)) {
            $problems[] = "{$id}: does not name the blocking gate in evidence/manual_verification";
        }

        // And it must carry an ABSENCE proof, not a promise.
        if (trim($row['automated_tests']) === '' || ! preg_match('/Test|guard/i', $row['automated_tests'])) {
            $problems[] = "{$id}: a blocked requirement must still name the absence/non-regression test";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));

    // Gate W is genuinely closed right now — the rows above must not be a stale claim.
    expect(is_dir(base_path('docs/integrations/wallet')))->toBeFalse(
        'docs/integrations/wallet/ now exists — Gate W may have opened; re-evaluate every blocked_external_gate row.',
    );
    expect(file_exists(base_path('docs/proof/phase-20d-w.md')))->toBeFalse(
        'docs/proof/phase-20d-w.md now exists — re-evaluate every blocked_external_gate row.',
    );
});

it('names a real later phase on every deferred requirement', function (): void {
    $problems = [];

    foreach (p23TraceRows() as $row) {
        if ($row['status'] !== 'deferred_future_phase') {
            continue;
        }
        if (! in_array(trim($row['phase']), P23_DEFERRABLE_PHASES, true)) {
            $problems[] = sprintf(
                '%s: deferred_future_phase must name a later phase, found %s (allowed: %s)',
                $row['requirement_id'],
                $row['phase'],
                implode(', ', P23_DEFERRABLE_PHASES),
            );
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('resolves every referenced test that looks like a suite name', function (): void {
    // sourceFilesUnder() (never RecursiveDirectoryIterator — PH23-SCAN-001) so a truncated
    // listing can never make a real suite look missing.
    $existing = [];
    foreach ([base_path('tests'), base_path('resources/spa/src')] as $root) {
        foreach (sourceFilesUnder($root, ['php', 'ts']) as $path) {
            $filename = basename($path);
            if (str_ends_with($filename, '.spec.ts')) {
                // Vitest/component specs are referenced as `Foo.spec` or `Foo`.
                $existing[str_replace('.spec.ts', '.spec', $filename)] = true;
                $existing[str_replace('.spec.ts', '', $filename)] = true;

                continue;
            }
            // e2e specs are referenced as `audit.spec` / `audit`; PHP suites by class name.
            $base = preg_replace('/\.(php|ts)$/', '', $filename) ?? $filename;
            $existing[$base] = true;
            $existing[str_replace('.spec', '', $base)] = true;
        }
    }

    $missing = [];
    foreach (p23TraceRows() as $row) {
        foreach (preg_split('/[;,]/', $row['automated_tests']) ?: [] as $reference) {
            $reference = trim($reference);
            // Only resolvable, suite-shaped references are checked. Prose ("n/a at this gate
            // …", "see Plan §75") is intentionally allowed for deferred/blocked rows, which
            // have no suite to name yet — the blocked-row case above still requires an
            // absence test for anything claiming to be blocked.
            if ($reference === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9]*(Test|Spec)$|^e2e\/|Test$|\.spec$/', $reference)) {
                continue;
            }
            $name = preg_replace('/\(.*$/', '', $reference);
            $name = str_replace(['e2e/', 'tests/'], '', (string) $name);
            // A reference may carry the real file suffix (`e2e/mfa.spec.ts`); normalise it.
            $name = preg_replace('/\.(ts|php)$/', '', trim($name)) ?? '';
            if ($name === '' || isset($existing[$name])) {
                continue;
            }
            $missing[] = "{$row['requirement_id']}: automated_tests references '{$reference}' which does not exist";
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});

it('maps every implemented endpoint claim onto a live route', function (): void {
    $live = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $live[] = '/'.ltrim($route->uri(), '/');
    }

    $delivered = ['verified_complete', 'local_complete', 'implemented'];
    $problems = [];

    foreach (p23TraceRows() as $row) {
        if (! in_array($row['status'], $delivered, true)) {
            continue; // blocked/deferred rows legitimately name no route
        }

        // Extract concrete `/api/v1/...` paths the row claims to have delivered.
        preg_match_all('#/api/v1/[a-z0-9\-/{}\.]+#i', $row['controller_or_endpoint'], $matches);
        foreach ($matches[0] as $claimed) {
            $needle = rtrim($claimed, '/.,;');
            $found = false;
            foreach ($live as $uri) {
                if (str_starts_with($uri, $needle) || str_starts_with($needle, $uri)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $problems[] = "{$row['requirement_id']}: claims endpoint {$needle} which is not in the live route table";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('maps every implemented frontend route claim onto the screen inventory', function (): void {
    /** @var array{screens: list<array<string, mixed>>} $inventory */
    $inventory = json_decode(
        (string) file_get_contents(base_path('docs/frontend/screens/inventory.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $routes = [];
    foreach ($inventory['screens'] as $screen) {
        if (is_string($screen['route'] ?? null)) {
            $routes[$screen['route']] = true;
        }
    }

    // Permission keys are ALSO dotted lowercase tokens and legitimately appear in this column
    // (a row names the key that gates its screen). Excluding the canonical catalogue is what
    // keeps `audit.export` / `service.view` / `compensation.plan.view` from reading as routes.
    $permissionKeys = array_fill_keys(app(PermissionMatrix::class)->keys(), true);

    $delivered = ['verified_complete', 'local_complete', 'implemented'];
    $problems = [];

    foreach (p23TraceRows() as $row) {
        if (! in_array($row['status'], $delivered, true)) {
            continue;
        }
        // Route NAMES are dotted lowercase tokens; only check ones that look like a route name.
        preg_match_all('/\b([a-z][a-z0-9\-]*(?:\.[a-z0-9\-]+)+)\b/', $row['frontend_route_and_component'], $matches);
        foreach ($matches[1] as $candidate) {
            // Skip filenames and paths (they contain an extension or a slash).
            // `css` and `html` were added in Phase UI-04: a generated stylesheet (`tokens.css`)
            // and the SPA shell (`index.html`) are legitimate frontend artifacts to name, and no
            // router route name has ever ended in either, so excluding them narrows nothing.
            if (str_contains($candidate, '/') || preg_match('/\.(vue|ts|md|json|yaml|php|js|mjs|css|html|spec)$/', $candidate)) {
                continue;
            }
            if (isset($routes[$candidate]) || isset($permissionKeys[$candidate])) {
                continue;
            }
            // Unknown dotted token: only fail when it really is a router route name.
            if (Route::has($candidate)) {
                continue;
            }
            $problems[] = "{$row['requirement_id']}: frontend route '{$candidate}' is in no screen-inventory entry";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('reports the status distribution so drift is visible, not assumed', function (): void {
    $counts = array_fill_keys(P23_TRACE_STATUSES, 0);
    foreach (p23TraceRows() as $row) {
        $counts[$row['status']]++;
    }

    // Every row landed in exactly one bucket of the closed vocabulary.
    expect(array_sum($counts))->toBe(count(p23TraceRows()));

    // The three gate-blocked phases (20D-W, 21R-B, 21N) must each be represented, so the
    // CSV can never quietly stop modelling deliberately-absent work.
    $blockedPhases = [];
    foreach (p23TraceRows() as $row) {
        if ($row['status'] === 'blocked_external_gate') {
            $blockedPhases[trim($row['phase'])] = true;
        }
    }
    expect(array_keys($blockedPhases))->toEqualCanonicalizing(['20D-W', '21R-B', '21N']);
});
