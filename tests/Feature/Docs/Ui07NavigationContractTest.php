<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

uses()->group('docs', 'ui07', 'contracts');

/*
 |==============================================================================
 | Phase UI-07 — the authenticated navigation and screen contract.
 |
 | ONE handwritten authority describes the 160 authenticated pages:
 |
 |   docs/frontend/navigation/servana-user-account-navigation-map.yaml
 |
 | It is not free to say whatever it likes. These cases pin it to the binding HUMAN map it
 | encodes — account, label, route and purpose, page by page — so the machine-readable contract
 | cannot drift from the document it was derived from. UI-01 recorded the reverse failure mode:
 | a register that looked authoritative while quietly describing something else.
 |
 | Everything here is OFFLINE and deterministic. It reads the authority, the human map, the
 | permission matrix, the account-host registry and the generated projections. It asserts what
 | the CONTRACT requires. Whether a page renders in a browser is UI-01/UI-16 evidence, and the
 | runtime router parity is proven by the generator's --check mode and the Vitest suites.
 */

const UI07_AUTHORITY = 'docs/frontend/navigation/servana-user-account-navigation-map.yaml';
const UI07_HUMAN_MAP = 'docs/frontend/navigation/servana-user-account-navigation-maps.md';
const UI07_AUDIT_DIR = 'docs/frontend/audits/ui-07';
const UI07_SPEC_DIR = 'docs/frontend/screens/contract';

/** The eight accounts and their binding authenticated page counts (UI/UX plan §7.5). */
const UI07_ACCOUNTS = [
    'super_administrator' => 22,
    'merchant_administrator' => 23,
    'merchant_branch' => 18,
    'merchant_human_resource' => 19,
    'merchant_finance' => 24,
    'merchant_front_office' => 19,
    'merchant_personnel' => 20,
    'merchant_audit' => 15,
];

/** The map section that enumerates each account's pages. */
const UI07_MAP_SECTIONS = [
    5 => 'super_administrator',
    6 => 'merchant_administrator',
    7 => 'merchant_branch',
    8 => 'merchant_human_resource',
    9 => 'merchant_finance',
    10 => 'merchant_front_office',
    11 => 'merchant_personnel',
    12 => 'merchant_audit',
];

const UI07_STATUSES = ['implemented', 'planned', 'disabled_by_gate', 'removed_by_authority'];

const UI07_OWNER_BY_ACCOUNT = [
    'super_administrator' => 'UI-08',
    'merchant_administrator' => 'UI-09',
    'merchant_branch' => 'UI-10',
    'merchant_human_resource' => 'UI-11',
    'merchant_finance' => 'UI-12',
    'merchant_front_office' => 'UI-13',
    'merchant_personnel' => 'UI-14',
    'merchant_audit' => 'UI-15',
];

function ui07Path(string $relative): string
{
    return base_path($relative);
}

function ui07Contract(): array
{
    static $contract = null;
    $contract ??= Yaml::parseFile(ui07Path(UI07_AUTHORITY));

    return $contract;
}

function ui07Pages(): array
{
    return ui07Contract()['pages'];
}

/**
 * The 160 pages as the BINDING HUMAN MAP states them, parsed from the map itself.
 *
 * Each page is a `### {section}.4.{n} — {Title}` heading followed by a required frontend route,
 * a navigation placement and a purpose.
 */
function ui07HumanMapPages(): array
{
    static $pages = null;

    if ($pages !== null) {
        return $pages;
    }

    // Index-based, never by reference: a memoised array holding a live reference in its last
    // slot is corrupted by the next write to that variable.
    $pages = [];
    $cursor = -1;

    foreach (preg_split('/\R/', (string) file_get_contents(ui07Path(UI07_HUMAN_MAP))) as $line) {
        if (preg_match('/^### (\d+)\.4\.(\d+) — (.+?)\s*$/u', $line, $m) === 1) {
            $pages[] = [
                'section' => "{$m[1]}.4.{$m[2]}",
                'account_type' => UI07_MAP_SECTIONS[(int) $m[1]] ?? null,
                'order' => (int) $m[2],
                'label' => $m[3],
                'route_path' => null,
                'placement' => null,
                'description' => null,
            ];
            $cursor++;

            continue;
        }

        if ($cursor < 0) {
            continue;
        }

        if (preg_match('/^- \*\*Required frontend route:\*\*\s*(.*)$/u', $line, $m) === 1) {
            $pages[$cursor]['route_path'] = trim(str_replace('`', '', $m[1]));
        } elseif (preg_match('/^- \*\*Navigation placement:\*\*\s*(.*)$/u', $line, $m) === 1) {
            $pages[$cursor]['placement'] = trim($m[1]);
        } elseif (preg_match('/^- \*\*Purpose:\*\*\s*(.*)$/u', $line, $m) === 1) {
            $pages[$cursor]['description'] = trim($m[1]);
        }
    }

    return $pages;
}

function ui07Audit(string $name): array
{
    return json_decode((string) file_get_contents(ui07Path(UI07_AUDIT_DIR."/{$name}")), true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// The authority exists and is the only handwritten one
// ---------------------------------------------------------------------------------------------

it('keeps exactly one handwritten canonical authority for the authenticated page contract', function (): void {
    expect(file_exists(ui07Path(UI07_AUTHORITY)))->toBeTrue();

    $authority = ui07Audit('source-authority.json');
    expect($authority['handwritten_authority'])->toBe(UI07_AUTHORITY);

    // Every other machine-readable representation is generated or explicitly reclassified, so a
    // second editable statement of the same contract cannot appear without failing here.
    expect($authority['generated_projections'])->toContain('resources/spa/src/navigation/navigationRegistry.generated.ts');
    expect(array_keys($authority['superseded_representations']))
        ->toContain('docs/frontend/navigation/role-navigation.yaml')
        ->toContain('docs/frontend/screens/inventory.json');
});

// ---------------------------------------------------------------------------------------------
// Exact counts — derived, never asserted from a written total
// ---------------------------------------------------------------------------------------------

it('registers exactly 160 authenticated pages', function (): void {
    expect(ui07Pages())->toHaveCount(160);
});

it('registers the exact page count for each of the eight accounts', function (): void {
    $counts = [];
    foreach (ui07Pages() as $page) {
        $counts[$page['account_type']] = ($counts[$page['account_type']] ?? 0) + 1;
    }

    ksort($counts);
    $expected = UI07_ACCOUNTS;
    ksort($expected);

    expect($counts)->toBe($expected);
});

it('sums the account counts to the total instead of trusting a hard-coded number', function (): void {
    $counts = [];
    foreach (ui07Pages() as $page) {
        $counts[$page['account_type']] = ($counts[$page['account_type']] ?? 0) + 1;
    }

    expect(array_sum($counts))->toBe(160);
    expect(ui07Contract()['total_required_pages'])->toBe(array_sum($counts));

    // The generated matrix must agree, and must state its arithmetic.
    $matrix = ui07Audit('page-count-matrix.json');
    expect($matrix['total'])->toBe(160);
    expect($matrix['arithmetic'])->toBe('22 + 23 + 18 + 19 + 24 + 19 + 20 + 15 = 160');
    foreach (UI07_ACCOUNTS as $account => $required) {
        expect($matrix[$account])->toBe($required);
    }
});

// ---------------------------------------------------------------------------------------------
// Parity with the binding human map
// ---------------------------------------------------------------------------------------------

it('parses exactly 160 pages out of the binding human navigation map', function (): void {
    $pages = ui07HumanMapPages();

    expect($pages)->toHaveCount(160);

    foreach ($pages as $page) {
        expect($page['account_type'])->not->toBeNull();
        expect($page['route_path'])->not->toBeNull();
        expect($page['description'])->not->toBeNull();
    }
});

it('encodes every page of the human map verbatim — account, label, route and purpose', function (): void {
    $contract = [];
    foreach (ui07Pages() as $page) {
        $contract[$page['map_section']] = $page;
    }

    $mismatches = [];

    foreach (ui07HumanMapPages() as $mapPage) {
        $entry = $contract[$mapPage['section']] ?? null;

        if ($entry === null) {
            $mismatches[] = "{$mapPage['section']}: absent from the canonical authority";

            continue;
        }

        foreach (['account_type', 'label', 'route_path', 'description'] as $field) {
            if ($entry[$field] !== $mapPage[$field]) {
                $mismatches[] = sprintf(
                    '%s %s: authority "%s" != map "%s"',
                    $mapPage['section'],
                    $field,
                    $entry[$field],
                    $mapPage[$field],
                );
            }
        }
    }

    expect($mismatches)->toBe([]);
});

it('never invents a page the human map does not contain', function (): void {
    $mapSections = array_column(ui07HumanMapPages(), 'section');

    foreach (ui07Pages() as $page) {
        expect($mapSections)->toContain($page['map_section']);
    }
});

// ---------------------------------------------------------------------------------------------
// Identity invariants
// ---------------------------------------------------------------------------------------------

it('gives every page a globally unique key, route name and account-scoped path and screen key', function (): void {
    $keys = [];
    $routeNames = [];
    $accountPaths = [];
    $screenKeys = [];

    foreach (ui07Pages() as $page) {
        $keys[] = $page['key'];
        $routeNames[] = $page['route_name'];
        $accountPaths[] = $page['account_type'].$page['route_path'];
        $screenKeys[] = $page['account_type'].'::'.$page['screen_key'];
    }

    expect(array_unique($keys))->toHaveCount(160);
    expect(array_unique($routeNames))->toHaveCount(160);
    expect(array_unique($accountPaths))->toHaveCount(160);
    expect(array_unique($screenKeys))->toHaveCount(160);
});

it('normalises every contract path and never embeds a host or query string', function (): void {
    foreach (ui07Pages() as $page) {
        expect($page['route_path'])->toStartWith('/');
        expect($page['route_path'])->not->toContain('?');
        expect($page['route_path'])->not->toContain('://');
        expect($page['route_path'])->not->toContain('//');
    }
});

it('keeps every parent relationship inside the same account, resolvable and acyclic', function (): void {
    $byKey = [];
    foreach (ui07Pages() as $page) {
        $byKey[$page['key']] = $page;
    }

    foreach (ui07Pages() as $page) {
        if ($page['parent_key'] === null) {
            continue;
        }

        expect($byKey)->toHaveKey($page['parent_key']);
        expect($byKey[$page['parent_key']]['account_type'])->toBe($page['account_type']);

        $seen = [$page['key'] => true];
        $cursor = $byKey[$page['parent_key']];
        while ($cursor !== null) {
            expect($seen)->not->toHaveKey($cursor['key']);
            $seen[$cursor['key']] = true;
            $cursor = $cursor['parent_key'] === null ? null : $byKey[$cursor['parent_key']];
        }
    }
});

it('orders siblings deterministically with no duplicate order among them', function (): void {
    $groups = [];
    foreach (ui07Pages() as $page) {
        $group = $page['account_type'].'::'.($page['parent_key'] ?? '(root)');
        $groups[$group][] = $page['order'];
    }

    foreach ($groups as $group => $orders) {
        expect(array_unique($orders))->toHaveCount(count($orders), "duplicate sibling order in {$group}");
    }
});

// ---------------------------------------------------------------------------------------------
// Status vocabulary and owner phases
// ---------------------------------------------------------------------------------------------

it('uses only the four allowed implementation statuses', function (): void {
    foreach (ui07Pages() as $page) {
        expect(UI07_STATUSES)->toContain($page['implementation_status']);
    }
});

it('retires the legacy status vocabulary everywhere', function (): void {
    // `phase_11`, `live`, `future`, `stub` and `pending` are not statuses. UI/UX plan §7.2 closes
    // the vocabulary, and the runtime screen register was reconciled to it in this phase.
    $inventory = json_decode(
        (string) file_get_contents(ui07Path('docs/frontend/screens/inventory.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // `toContain` is VARIADIC in Pest: a second argument is another expected value, not a
    // message. Collect the offenders instead so the failure still names them.
    $illegal = [];
    foreach ($inventory['screens'] as $screen) {
        if (! in_array($screen['status'], UI07_STATUSES, true)) {
            $illegal[] = "{$screen['key']} → {$screen['status']}";
        }
    }

    expect($illegal)->toBe([]);
});

it('gives every page exactly one UI owner phase, matching its account', function (): void {
    foreach (ui07Pages() as $page) {
        expect($page['owner_phase'])->toBe(UI07_OWNER_BY_ACCOUNT[$page['account_type']]);
    }
});

it('never infers backend ownership from the UI phase numbering', function (): void {
    foreach (ui07Pages() as $page) {
        if ($page['backend_owner_phase'] === null) {
            continue;
        }

        // A backend owner is a real delivery phase read from the screen inventory, never a UI-xx.
        expect($page['backend_owner_phase'])->not->toStartWith('UI-');
        expect($page['backend_owner_phase'])->toStartWith('Phase ');
    }
});

it('never leaves an owner unnamed', function (): void {
    foreach (ui07Pages() as $page) {
        expect($page['owner_phase'])->not->toBeIn(['later', 'future', 'unknown', 'TBD', null, '']);
    }
});

// ---------------------------------------------------------------------------------------------
// Runtime binding truth
// ---------------------------------------------------------------------------------------------

it('names a runtime route only for an implemented page', function (): void {
    foreach (ui07Pages() as $page) {
        if ($page['implementation_status'] === 'implemented') {
            expect($page['runtime_route_name'])->not->toBeNull("{$page['key']} is implemented");
            expect($page['route_delivery'])->toBeIn(['dedicated', 'consolidated', 'cross_account_utility']);

            continue;
        }

        expect($page['runtime_route_name'])->toBeNull("{$page['key']} is {$page['implementation_status']}");
        expect($page['route_delivery'])->toBeNull();
    }
});

it('never exposes a planned or removed page as a live route', function (): void {
    $parity = ui07Audit('route-parity.json');

    expect($parity['planned_pages_with_runtime_route'])->toBe([]);
    expect($parity['removed_pages_with_runtime_route'])->toBe([]);
    // A reserved contract route name must not already be registered in the router.
    expect($parity['contract_route_names_colliding_with_runtime'])->toBe([]);
});

it('gives every implemented page a lazily loaded runtime component', function (): void {
    $parity = ui07Audit('route-parity.json');

    expect($parity['runtime_routes_without_lazy_component'])->toBe([]);
    expect($parity['duplicate_runtime_route_names'])->toBe([]);
    expect($parity['duplicate_runtime_paths'])->toBe([]);

    foreach ($parity['rows'] as $row) {
        if ($row['implementation_status'] !== 'implemented') {
            continue;
        }

        expect($row['runtime_route_name'])->not->toBeNull();
        expect($row['lazy_component'])->toBeTrue();
    }
});

it('imports no page eagerly — 160 pages never reach the initial bundle', function (): void {
    $matrix = ui07Audit('code-splitting-matrix.json');

    expect($matrix['eager_page_imports'])->toBe([]);
    expect($matrix['planned_pages_with_runtime_chunk'])->toBe([]);
    foreach ($matrix['rows'] as $row) {
        expect($row['lazy'])->toBeTrue();
        expect($row['in_initial_bundle'])->toBeFalse();
    }
});

it('names the exact gate on every gate-blocked page and gives it no destination', function (): void {
    $gated = array_values(array_filter(
        ui07Pages(),
        static fn (array $p): bool => $p['implementation_status'] === 'disabled_by_gate',
    ));

    expect($gated)->not->toBeEmpty();

    $accountSpecificGates = [
        'merchant_human_resource.staff-detail-edit' => 'staff_profile_mutation_contract',
        'merchant_human_resource.staff-detail-access' => 'staff_access_assignment_contract',
        'merchant_human_resource.reports' => 'phase_21n_blocked_by_external_gate_w',
        'merchant_human_resource.notifications' => 'phase_21n_blocked_by_external_gate_w',
    ];

    foreach ($gated as $page) {
        $expectedGate = $accountSpecificGates[$page['key']] ?? 'external_gate_w';
        expect($page['gate'])->not->toBeNull();
        expect($page['gate'])->toBe($expectedGate);
        expect($page['runtime_route_name'])->toBeNull();
    }
});

it('keeps External Gate W closed and creates no partner runtime behind a gated page', function (): void {
    // A gated page must not have quietly become a Wallet or Refer & Earn implementation.
    expect(file_exists(ui07Path('docs/integrations/wallet/gate-w-evidence.md')))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Permissions — referenced, never invented
// ---------------------------------------------------------------------------------------------

it('references only permission keys that already exist in the canonical matrix', function (): void {
    $matrix = Yaml::parseFile(ui07Path('docs/auth/permission-matrix.yaml'));
    $known = array_keys($matrix['keys']);

    $unknown = [];
    foreach (ui07Pages() as $page) {
        foreach ([...$page['permission_any'], ...$page['permission_all']] as $key) {
            if (! in_array($key, $known, true)) {
                $unknown[] = "{$page['key']} → {$key}";
            }
        }
    }

    expect($unknown)->toBe([]);

    expect(ui07Audit('permission-parity.json')['unknown_permission_references'])->toBe([]);
});

it('adds no permission key to the matrix', function (): void {
    // UI-07 is a read-only phase for the permission authority (Plan §10.3): it referenced only keys
    // the merged UI-06 tree already carried, and its 167 is the baseline.
    //
    // A later phase MAY grow the matrix, but only under a recorded product-owner authorization.
    // COR-UI08-001 authorized exactly two internal-platform-access keys so UI-08 could deliver
    // navigation map §5.4.19. Asserting the baseline plus an itemised authorization keeps this test
    // meaningful: it still fails for any key UI-07 or an unauthorized later phase slipped in.
    $matrix = Yaml::parseFile(ui07Path('docs/auth/permission-matrix.yaml'));

    $authorizedSinceUi07 = [
        // COR-UI08-001 — docs/decisions/cor-ui08-001-super-administrator-backend-enablement.md
        'platform.internal_access.view',
        'platform.internal_access.manage',
    ];

    expect($matrix['keys'])->toHaveCount(167 + count($authorizedSinceUi07));

    foreach ($authorizedSinceUi07 as $key) {
        expect($matrix['keys'])->toHaveKey($key);
    }

    // Every OTHER key beyond UI-07's baseline would be unauthorized, and the count above catches it.
    // UI-07's own contract still references nothing outside the matrix (asserted immediately above).
});

// ---------------------------------------------------------------------------------------------
// Authority boundaries
// ---------------------------------------------------------------------------------------------

it('forbids every account other than the owning one, and never the owner itself', function (): void {
    $accounts = array_keys(UI07_ACCOUNTS);

    foreach (ui07Pages() as $page) {
        expect($page['forbidden_for'])->not->toContain($page['account_type']);

        foreach ($page['forbidden_for'] as $forbidden) {
            expect($accounts)->toContain($forbidden);
        }
    }
});

it('gives the Super Administrator no merchant-creation or impersonation page', function (): void {
    $labels = [];
    foreach (ui07Pages() as $page) {
        if ($page['account_type'] === 'super_administrator') {
            $labels[] = mb_strtolower($page['label'].' '.$page['key']);
        }
    }

    $joined = implode(' | ', $labels);

    expect($joined)->not->toContain('create merchant');
    expect($joined)->not->toContain('new merchant');
    expect($joined)->not->toContain('impersonat');
    expect($joined)->not->toContain('first admin');
});

it('gives Personnel no contact-export page in any form', function (): void {
    foreach (ui07Pages() as $page) {
        if ($page['account_type'] !== 'merchant_personnel') {
            continue;
        }

        $text = mb_strtolower($page['label'].' '.$page['key'].' '.$page['route_path']);
        expect($text)->not->toContain('export');
        expect($text)->not->toContain('contact');
    }
});

it('keeps the Audit account read-only — no mutating page', function (): void {
    foreach (ui07Pages() as $page) {
        if ($page['account_type'] !== 'merchant_audit') {
            continue;
        }

        expect($page['key'])->not->toMatch('/create|update|delete|validate|approve|refund/i');
    }
});

it('keeps navigation placement header for the Super Administrator and sidebar for the rest', function (): void {
    foreach (ui07Contract()['accounts'] as $account) {
        $expected = $account['account_type'] === 'super_administrator' ? 'header' : 'sidebar';
        expect($account['navigation_placement'])->toBe($expected);
    }
});

it('uses the account-host registry keys and never a second account vocabulary', function (): void {
    $registry = json_decode(
        (string) file_get_contents(ui07Path('config/account-hosts.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $hosts = [];
    foreach ($registry['accounts'] as $account) {
        $hosts[$account['account_key']] = $account['subdomain'] === null
            ? $registry['domains']['production']
            : "{$account['subdomain']}.{$registry['domains']['production']}";
    }

    foreach (ui07Contract()['accounts'] as $account) {
        expect($hosts)->toHaveKey($account['account_type']);
        expect($account['host'])->toBe($hosts[$account['account_type']]);
        expect($account['route_name_prefix'])->toBe(
            $registry['accounts'][array_search($account['account_type'], array_column($registry['accounts'], 'account_key'), true)]['route_name_prefix'],
        );
    }
});

// ---------------------------------------------------------------------------------------------
// Screen specifications
// ---------------------------------------------------------------------------------------------

it('writes exactly one screen specification per contract page, with no orphan', function (): void {
    $expected = [];
    foreach (ui07Pages() as $page) {
        $expected[] = "{$page['account_type']}/{$page['screen_key']}.md";
    }

    sort($expected);
    expect($expected)->toHaveCount(160);
    expect(array_unique($expected))->toHaveCount(160);

    $onDisk = [];
    foreach (glob(ui07Path(UI07_SPEC_DIR).'/*/*.md') ?: [] as $file) {
        $onDisk[] = basename(dirname($file)).'/'.basename($file);
    }

    sort($onDisk);
    expect($onDisk)->toBe($expected);
});

it('gives every screen specification the sections UI/UX plan §7.3 requires', function (): void {
    $required = [
        'Account', 'Host', 'Page title', 'Route', 'Route name', 'Navigation group', 'Purpose',
        'User story', 'UI owner phase', 'Backend owner phase', 'Implementation status',
        'API dependencies', 'Data fields', 'Filters', 'Sorts', 'Pagination', 'Primary action',
        'Secondary actions', 'Authorization', 'Permission-any', 'Permission-all', 'Tenant scope',
        'Branch scope', 'Own-scope', 'MFA', 'Step-up', 'Feature flag', 'Billing-state behaviour',
        'Entitlement behaviour', 'Loading state', 'Empty state', 'Error state', 'Stale-data state',
        'Offline state', 'No-permission state', 'Suspended state', 'Locked-period state',
        'Responsive behaviour', 'Accessibility behaviour', 'Audit events', 'Analytics events',
        'Tests', 'Screenshot requirements', 'Non-navigation reason',
    ];

    $missing = [];
    foreach (ui07Pages() as $page) {
        $path = ui07Path(UI07_SPEC_DIR."/{$page['account_type']}/{$page['screen_key']}.md");
        $body = (string) file_get_contents($path);

        foreach ($required as $heading) {
            if (! str_contains($body, "**{$heading}:**")) {
                $missing[] = "{$page['key']}: {$heading}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('never describes a page that is not implemented as though it were', function (): void {
    foreach (ui07Pages() as $page) {
        if ($page['implementation_status'] === 'implemented') {
            continue;
        }

        $body = (string) file_get_contents(
            ui07Path(UI07_SPEC_DIR."/{$page['account_type']}/{$page['screen_key']}.md"),
        );

        if ($page['implementation_status'] === 'planned') {
            expect($body)->toContain('no Vue Router record and no navigation link is exposed');
        } else {
            expect($body)->toContain("Blocked by **{$page['gate']}**");
        }

        // An unresolved field must always name the owner phase that resolves it — never a bare TBD.
        expect($body)->toContain($page['owner_phase']);
        expect($body)->not->toContain('TBD');
    }
});

// ---------------------------------------------------------------------------------------------
// Inventory reconciliation
// ---------------------------------------------------------------------------------------------

it('keeps the public and excluded surfaces out of the authenticated count', function (): void {
    $parity = ui07Audit('inventory-parity.json');

    expect($parity['contract_pages'])->toBe(160);
    expect($parity['excluded_classification']['predicate'])->toContain('domain in');
    expect($parity['excluded_classification']['domains'])
        ->toContain('public')
        ->toContain('legal')
        ->toContain('auth');

    // The exclusion list of UI/UX plan §7.5 is recorded on the authority itself.
    $excluded = ui07Contract()['excluded_from_count'];
    foreach (['public landing pages', 'login', 'legal pages', 'FAQ'] as $item) {
        expect($excluded)->toContain($item);
    }
});

it('backs every implemented contract page with a runtime screen-register row', function (): void {
    expect(ui07Audit('inventory-parity.json')['implemented_contract_pages_without_inventory_row'])->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// Account route guard coverage
// ---------------------------------------------------------------------------------------------

it('guards all eight authenticated account route trees', function (): void {
    $coverage = ui07Audit('requires-account-coverage.json');

    /*
     | Eight trees, one per account (Phase UI-08 Increment 7B).
     |
     | This was nine while coverage was grouped by URL PREFIX — the Merchant Administrator owned
     | two roots, `/merchant` and the first-time-setup route. Coverage is now grouped by the
     | account each route DECLARES in `meta.accountKey`, which the file's own boundary note always
     | said was the authority ("a path prefix is not an account boundary"), and which UI-08 made
     | unavoidable: the Super Administrator's canonical paths share no prefix at all, and `/audit`
     | is a contract path for two different accounts served on two different hosts.
     */
    expect($coverage['trees'])->toHaveCount(8);

    $accounts = [];
    foreach ($coverage['trees'] as $tree) {
        $accounts[$tree['account_key']] = true;
        expect($tree['routes_missing_account'])->toBe([], "{$tree['root']} has unguarded routes");
        expect($tree['routes_in_tree'])->toBeGreaterThan(0);
    }

    expect(array_keys(UI07_ACCOUNTS))->each->toBeIn(array_keys($accounts));
});

it('explains every authenticated route that sits outside an account tree', function (): void {
    foreach (ui07Audit('requires-account-coverage.json')['authenticated_routes_outside_an_account_tree'] as $route) {
        expect($route['reason'])->not->toBe('UNEXPLAINED — investigate');
        expect(mb_strlen($route['reason']))->toBeGreaterThan(30);
    }
});

it('states that the route guard is defence in depth and not the security boundary', function (): void {
    $coverage = ui07Audit('requires-account-coverage.json');

    expect($coverage['boundary'])
        ->toContain('auth:sanctum')
        ->toContain('policies')
        ->toContain('Host header alone still grants nothing');
});

// ---------------------------------------------------------------------------------------------
// Provenance
// ---------------------------------------------------------------------------------------------

it('records the exact source hashes every generated matrix was derived from', function (): void {
    $expected = [
        'canonical_authority_sha256' => UI07_AUTHORITY,
        'human_map_sha256' => UI07_HUMAN_MAP,
        'account_host_registry_sha256' => 'config/account-hosts.json',
        'permission_matrix_sha256' => 'docs/auth/permission-matrix.yaml',
        'screen_inventory_sha256' => 'docs/frontend/screens/inventory.json',
    ];

    foreach (['page-count-matrix.json', 'route-parity.json', 'navigation-parity.json'] as $artifact) {
        $payload = ui07Audit($artifact);

        foreach ($expected as $field => $source) {
            expect($payload[$field])->toBe(hash_file('sha256', ui07Path($source)), "{$artifact}.{$field}");
        }
    }
});
