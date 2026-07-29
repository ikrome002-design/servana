<?php

declare(strict_types=1);

uses()->group('docs', 'ui00', 'contracts');

/*
 |==============================================================================
 | Phase UI-00 — UI source-contract enforcement.
 |
 | The corrective UI programme depends on a set of source documents, directories and assets whose
 | paths were previously asserted only by prose in CLAUDE.md — and were WRONG. `docs/landing page/`
 | (with a space) was named as the landing-copy directory although it has never existed here, and
 | `Logo.svg` was listed as a required asset although the product owner had deleted it. Both
 | mistakes had already cost implementation time.
 |
 | This guard turns those source contracts into checked facts. It is deliberately OFFLINE and
 | deterministic: it reads the plan, the generated inventories and the filesystem. It asserts what
 | the sources REQUIRE. It never asserts that a page is implemented — the runtime navigation
 | contract, the router parity and the browser proof belong to Phases UI-07, UI-01 and UI-16.
 */

/** The canonical UI/UX delivery plan, at the product-owner-supplied path. */
const UI00_PLAN = 'Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md';

/** The binding human-readable frontend page and workflow specification. */
const UI00_NAV_MAP = 'docs/frontend/navigation/servana-user-account-navigation-maps.md';

const UI00_INVENTORY_DIR = 'docs/frontend/source-inventory';

/** The eight canonical role keys and their binding authenticated page counts (UI/UX plan §0). */
const UI00_ACCOUNTS = [
    'super_administrator' => 22,
    'merchant_administrator' => 23,
    'merchant_branch' => 18,
    'merchant_human_resource' => 19,
    'merchant_finance' => 24,
    'merchant_front_office' => 19,
    'merchant_personnel' => 20,
    'merchant_audit' => 15,
];

/** The five role-specific content categories and their canonical directories (UI/UX plan §8.2). */
const UI00_CONTENT_DIRECTORIES = [
    'landing_page' => 'docs/landing_page',
    'data_policy' => 'docs/legal/data_policy',
    'privacy_policy' => 'docs/legal/privacy_policy',
    'terms_of_service' => 'docs/legal/terms_of_service',
    'faq' => 'docs/support/faq',
];

/** The supplied landing-image baseline confirmed by the product owner at UI-00 kickoff. */
const UI00_LANDING_IMAGE_BASELINE = [
    'super_administrator' => 10,
    'merchant_administrator' => 8,
    'merchant_branch' => 9,
    'merchant_finance' => 5,
    'merchant_human_resource' => 8,
    'merchant_front_office' => 6,
    'merchant_personnel' => 7,
    'merchant_audit' => 8,
];

/** The ten ADRs Phase UI-00 is required to create (UI/UX plan §3.3). */
const UI00_ADRS = [
    '0016-eight-account-hosts-one-application',
    '0017-host-context-versus-authorization-context',
    '0018-cross-subdomain-account-context-switching',
    '0019-magic-link-host-binding',
    '0020-role-navigation-registry-and-navigation-map-parity',
    '0021-design-tokens-light-mode-default-dark-mode-persistence',
    '0022-static-content-and-legal-document-compilation',
    '0023-role-specific-landing-image-manifest',
    '0024-fixed-footer-layout-and-obstruction-prevention',
    '0025-visual-regression-and-browser-proof-policy',
];

/** @return array<string, mixed> */
function ui00Inventory(string $name): array
{
    $path = base_path(UI00_INVENTORY_DIR."/{$name}.json");

    expect(file_exists($path))->toBeTrue(
        "{$name}.json is missing. Run: node scripts/generate-ui-source-inventory.mjs",
    );

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

// ---------------------------------------------------------------------------------------------
// 1. Plan authority
// ---------------------------------------------------------------------------------------------

it('registers exactly one canonical UI/UX plan', function (): void {
    expect(file_exists(base_path(UI00_PLAN)))->toBeTrue('The canonical UI/UX plan is missing.');

    // A second copy would be a second authority, and the two would drift. `docs/plans/` is the
    // path the plan itself offers as an EXAMPLE ("such as"); the product-owner-supplied root path
    // is the adopted canonical one, so a docs/plans/ duplicate must not exist.
    $duplicates = [];
    foreach (sourceFilesUnder(base_path('docs'), ['md']) as $path) {
        $name = basename($path);
        if (str_contains($name, 'Role_Specific_UI_UX') || str_contains($name, 'role-ui-ux-subdomain')) {
            $duplicates[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($duplicates)->toBe([], 'A second copy of the UI/UX plan exists: '.implode(', ', $duplicates));
});

it('points the workflow guides at the UI authorities and never at a stale path', function (): void {
    foreach (['CLAUDE.md', 'AGENTS.md'] as $guide) {
        $text = (string) file_get_contents(base_path($guide));

        expect(str_contains($text, UI00_PLAN))
            ->toBeTrue("{$guide} does not name the canonical UI/UX plan.");
        expect(str_contains($text, UI00_NAV_MAP))
            ->toBeTrue("{$guide} does not name the binding navigation map.");

        // The backend plan must remain the higher authority for business/security invariants.
        expect(str_contains($text, 'Servana Software Development Plan.md'))
            ->toBeTrue("{$guide} no longer names the backend plan as an authority.");

        // The two proven stale references. `docs/landing page/` (with a space) never existed, and
        // Logo.svg was deleted under product-owner authority.
        expect($text)->not->toMatch(
            '#`docs/landing page/?\{?role\}?#',
            "{$guide} still names the space-directory `docs/landing page/` as a source path.",
        );
        expect($text)->not->toMatch(
            '#\|\s*Logo \(SVG\)#',
            "{$guide} still lists Logo.svg as a required asset; it was deleted by product-owner authority.",
        );
        expect(str_contains($text, 'brand/Favicon.ico'))->toBeFalse(
            "{$guide} still names `Favicon.ico`; the real file is lowercase `favicon.ico` and CI is case-sensitive.",
        );
    }
});

// ---------------------------------------------------------------------------------------------
// 2. Navigation contract
// ---------------------------------------------------------------------------------------------

it('registers exactly one canonical navigation map, generated with provenance', function (): void {
    $path = base_path(UI00_NAV_MAP);
    expect(file_exists($path))->toBeTrue('The canonical navigation map is missing.');

    $text = (string) file_get_contents($path);
    foreach (['GENERATED FILE', UI00_PLAN, 'Appendix A', 'Source plan hash', 'generate-ui-source-inventory.mjs'] as $marker) {
        expect(str_contains($text, $marker))->toBeTrue("The navigation map lacks provenance: {$marker}.");
    }

    // Exactly one manually editable source. Any other copy of the map would drift.
    $copies = [];
    foreach (sourceFilesUnder(base_path('docs'), ['md']) as $file) {
        if (str_contains(basename($file), 'servana-user-account-navigation-maps')) {
            $copies[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }
    }
    expect($copies)->toHaveCount(1, 'More than one navigation-map copy exists: '.implode(', ', $copies));
});

it('parses exactly eight accounts with the binding per-account page counts', function (): void {
    $inventory = ui00Inventory('navigation-map');

    /** @var list<array<string, mixed>> $accounts */
    $accounts = $inventory['accounts'];
    expect($accounts)->toHaveCount(8, 'The navigation map must describe exactly eight accounts.');

    $parsed = [];
    foreach ($accounts as $account) {
        $parsed[$account['role_key']] = $account['parsed_pages'];
        expect($account['parsed_pages'])->toBe(
            $account['required_pages'],
            "{$account['account']}: parsed {$account['parsed_pages']} pages, the plan binds {$account['required_pages']}.",
        );
    }

    expect($parsed)->toBe(UI00_ACCOUNTS);
});

it('accounts for exactly 160 authenticated pages', function (): void {
    $inventory = ui00Inventory('navigation-map');

    expect($inventory['total_required_pages'])->toBe(160);
    expect($inventory['pages'])->toHaveCount(160);
    expect(array_sum(UI00_ACCOUNTS))->toBe(160);
});

it('gives every page a complete, unique specification', function (): void {
    $inventory = ui00Inventory('navigation-map');
    $problems = [];
    $sections = [];
    $accountRoutes = [];

    /** @var list<array<string, string>> $pages */
    $pages = $inventory['pages'];
    foreach ($pages as $page) {
        foreach (['section', 'account_key', 'account', 'host', 'page', 'route', 'navigation_placement', 'purpose'] as $field) {
            if (trim((string) ($page[$field] ?? '')) === '') {
                $problems[] = "{$page['section']}: {$field} is blank";
            }
        }
        if (isset($sections[$page['section']])) {
            $problems[] = "duplicate section identifier: {$page['section']}";
        }
        $sections[$page['section']] = true;

        $pair = $page['account_key'].' '.$page['route'];
        if (isset($accountRoutes[$pair])) {
            $problems[] = "duplicate account/route pair: {$pair}";
        }
        $accountRoutes[$pair] = true;

        if (! str_starts_with($page['route'], '/')) {
            $problems[] = "{$page['section']}: route '{$page['route']}' is not rooted";
        }
        if (! array_key_exists($page['account_key'], UI00_ACCOUNTS)) {
            $problems[] = "{$page['section']}: '{$page['account_key']}' is not a canonical role key";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('agrees exactly with the plan §30 route implementation register', function (): void {
    $inventory = ui00Inventory('navigation-map');

    // The plan states §30 is generated from the same page specifications. If the two ever
    // disagree, one drifted and later phases would build the wrong information architecture.
    expect($inventory['section_30_register_rows'])->toBe(160);
    expect($inventory['section_30_parity'])->toBe('exact');
});

it('claims no implementation for the required page contract', function (): void {
    $inventory = ui00Inventory('navigation-map');

    // UI-00 registers the REQUIREMENT. Marking any page implemented here would discharge the
    // requirement by assertion and is exactly the drift this phase exists to prevent.
    /** @var list<array<string, string>> $pages */
    $pages = $inventory['pages'];
    foreach ($pages as $page) {
        expect($page['implementation_status'])->toBe(
            'planned',
            "{$page['section']} claims status '{$page['implementation_status']}'; UI-00 implements nothing.",
        );
        expect($page['owner_phase'])->toBe('UI-07');
    }
});

// ---------------------------------------------------------------------------------------------
// 3. Content contract
// ---------------------------------------------------------------------------------------------

it('supplies all five source categories for all eight roles', function (): void {
    $inventory = ui00Inventory('role-content');
    $problems = [];

    /** @var list<array<string, string>> $documents */
    $documents = $inventory['documents'];
    $seen = [];
    foreach ($documents as $document) {
        $seen[$document['role_key']][$document['category']] = $document['path'];

        if (! file_exists(base_path($document['path']))) {
            $problems[] = "{$document['path']} is inventoried but missing from disk";
        }
        // A role must never map to another role's source file.
        if (! str_contains(basename($document['path']), $document['role_key'])) {
            $problems[] = "{$document['path']} does not belong to role {$document['role_key']}";
        }
    }

    foreach (array_keys(UI00_ACCOUNTS) as $role) {
        foreach (array_keys(UI00_CONTENT_DIRECTORIES) as $category) {
            if (! isset($seen[$role][$category])) {
                $problems[] = "{$role} has no {$category} source";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
    expect($documents)->toHaveCount(40, 'Expected 8 roles x 5 categories = 40 role source documents.');
});

it('keeps every legal and content source in its canonical directory', function (): void {
    $problems = [];

    foreach (UI00_CONTENT_DIRECTORIES as $category => $directory) {
        expect(is_dir(base_path($directory)))->toBeTrue("Canonical directory {$directory} is missing.");

        foreach (array_keys(UI00_ACCOUNTS) as $role) {
            $suffix = $category === 'landing_page' ? '_landing_page_content.md' : "_{$category}.md";
            $path = "{$directory}/{$role}{$suffix}";
            if (! file_exists(base_path($path))) {
                $problems[] = "missing canonical source: {$path}";
            }
        }
    }

    // A legal document misfiled into the landing directory is the misfile case CLAUDE.md warned
    // about; assert it directly rather than trusting the warning.
    foreach (glob(base_path('docs/landing_page/*.md')) ?: [] as $file) {
        $name = basename($file);
        foreach (['_data_policy', '_privacy_policy', '_terms_of_service', '_faq'] as $legal) {
            if (str_contains($name, $legal)) {
                $problems[] = "legal document misfiled in docs/landing_page/: {$name}";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('resolves the duplicate landing-directory question permanently', function (): void {
    // `docs/landing_page/` (underscore) is canonical and populated. The space-named directory that
    // CLAUDE.md used to reference has never existed and must not be created: two active landing
    // directories would be two sources of truth for public and legal-adjacent copy.
    expect(is_dir(base_path('docs/landing_page')))->toBeTrue();
    expect(is_dir(base_path('docs/landing page')))->toBeFalse(
        'docs/landing page/ (with a space) now exists — reconcile it before any UI phase reads content.',
    );

    $inventory = ui00Inventory('role-content');
    expect($inventory['canonical_directories']['landing_page'])->toBe('docs/landing_page');
});

it('leaves the source content byte-identical to the inventoried hashes', function (): void {
    $inventory = ui00Inventory('role-content');
    $problems = [];

    /** @var list<array<string, string|int>> $documents */
    $documents = $inventory['documents'];
    foreach ($documents as $document) {
        $path = base_path((string) $document['path']);
        if (! file_exists($path)) {
            continue; // reported by the completeness test
        }
        $actual = hash_file('sha256', $path);
        if ($actual !== $document['sha256']) {
            // Not a freeze: a reviewed legal change simply regenerates the inventory. What this
            // catches is a source edited WITHOUT the inventory being regenerated.
            $problems[] = "{$document['path']}: content changed but the inventory was not regenerated";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems)
        ."\nRun: node scripts/generate-ui-source-inventory.mjs");
});

// ---------------------------------------------------------------------------------------------
// 4. Brand contract
// ---------------------------------------------------------------------------------------------

it('proves the approved logo with its exact case', function (): void {
    $inventory = ui00Inventory('brand-assets');

    expect($inventory['approved_primary_logo'])->toBe('public/assets/brand/Logo.png');

    $logo = null;
    /** @var list<array<string, mixed>> $assets */
    $assets = $inventory['assets'];
    foreach ($assets as $asset) {
        if ($asset['purpose'] === 'primary_logo') {
            $logo = $asset;
        }
    }

    expect($logo)->not->toBeNull('No approved primary logo is recorded.');
    expect(file_exists(base_path((string) $logo['path'])))->toBeTrue();
    expect($logo['type'])->toBe('image/png');
    expect($logo['width'])->toBeGreaterThan(0);
    expect($logo['approval'])->toBe('approved');

    // Case-sensitive existence: on Linux/CI `logo.png` and `Logo.png` are different files.
    $names = array_map('basename', glob(base_path('public/assets/brand/*.png')) ?: []);
    expect($names)->toContain('Logo.png');
});

it('keeps the authorized Logo.svg deletion in force', function (): void {
    expect(file_exists(base_path('public/assets/brand/Logo.svg')))->toBeFalse(
        'Logo.svg was deleted under product-owner authority and must not be restored.',
    );

    // No ACTIVE workflow document may require it. Historical records (PROGRESS/proof/CHANGELOG)
    // legitimately retain factual references to its earlier existence.
    foreach (['CLAUDE.md', 'AGENTS.md'] as $guide) {
        expect((string) file_get_contents(base_path($guide)))->not->toMatch(
            '#\|\s*Logo \(SVG\)#',
            "{$guide} still requires Logo.svg.",
        );
    }

    $inventory = ui00Inventory('brand-assets');
    expect($inventory['deleted_by_authority'])->toContain('public/assets/brand/Logo.svg');
});

it('proves every required favicon with its exact lowercase filename', function (): void {
    $required = [
        'favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png',
        'apple-touch-icon.png', 'android-chrome-192x192.png', 'android-chrome-512x512.png',
    ];

    $present = array_map('basename', array_filter(
        glob(base_path('public/assets/brand/*')) ?: [],
        'is_file',
    ));

    $missing = array_values(array_diff($required, $present));
    expect($missing)->toBe([], 'Missing favicon assets (exact case): '.implode(', ', $missing));
});

it('keeps the brand inventory a true statement about the filesystem', function (): void {
    $inventory = ui00Inventory('brand-assets');

    $inventoried = [];
    /** @var list<array<string, mixed>> $assets */
    $assets = $inventory['assets'];
    foreach ($assets as $asset) {
        if ($asset['present'] === true) {
            $inventoried[] = $asset['path'];
        }
    }

    $onDisk = [];
    foreach (sourceFilesUnder(base_path('public/assets/brand'), ['png', 'ico', 'svg', 'jpg', 'jpeg', 'webp']) as $file) {
        $onDisk[] = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file);
    }

    sort($inventoried);
    sort($onDisk);

    expect($inventoried)->toBe($onDisk, 'The brand inventory and the filesystem disagree. '
        .'Run: node scripts/generate-ui-source-inventory.mjs');
});

// ---------------------------------------------------------------------------------------------
// 5. Landing-image contract
// ---------------------------------------------------------------------------------------------

it('inventories all eight role image directories against the approved baseline', function (): void {
    $inventory = ui00Inventory('landing-images');

    /** @var array<string, int> $counts */
    $counts = (array) $inventory['counts_by_role'];
    $expected = UI00_LANDING_IMAGE_BASELINE;
    // Compare by role key, not by declaration order — the generator emits its own account order.
    ksort($counts);
    ksort($expected);

    expect($counts)->toBe($expected);
    expect($inventory['total_images'])->toBe(61);

    foreach (array_keys(UI00_ACCOUNTS) as $role) {
        expect(is_dir(base_path("public/assets/landing_page_images/{$role}")))->toBeTrue(
            "Missing landing-image directory for {$role}.",
        );
    }
});

it('proves every supplied landing image is a real image', function (): void {
    $inventory = ui00Inventory('landing-images');
    $problems = [];

    /** @var list<array<string, mixed>> $images */
    $images = $inventory['images'];
    foreach ($images as $image) {
        if (! file_exists(base_path((string) $image['path']))) {
            $problems[] = "{$image['path']} is inventoried but missing";

            continue;
        }
        if (! is_int($image['width']) || $image['width'] <= 0 || ! is_int($image['height']) || $image['height'] <= 0) {
            $problems[] = "{$image['path']} has no readable dimensions";
        }
        if ($image['type'] !== 'image/png') {
            $problems[] = "{$image['path']} is {$image['type']}, expected image/png";
        }
        // An image must live in its own role's directory.
        if (! str_contains((string) $image['path'], "/{$image['role_key']}/")) {
            $problems[] = "{$image['path']} is filed under the wrong role";
        }
        if ($image['duplicate_of'] !== null) {
            $problems[] = "{$image['path']} duplicates {$image['duplicate_of']}";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('makes no final landing-image selection in UI-00', function (): void {
    $inventory = ui00Inventory('landing-images');

    // The curated production manifest belongs to UI-05/UI-06. Its absence here is the contract.
    expect($inventory['selection_rule'])->toContain('two to four');
    expect(file_exists(base_path('public/assets/landing_page_images/manifest.json')))->toBeFalse(
        'A landing-image selection manifest exists; UI-00 must not select images (owner: UI-05/UI-06).',
    );
});

// ---------------------------------------------------------------------------------------------
// 6. ADR contract
// ---------------------------------------------------------------------------------------------

it('creates all ten UI architecture decision records with unique numbers', function (): void {
    $problems = [];

    foreach (UI00_ADRS as $slug) {
        $path = base_path("docs/architecture/adr/{$slug}.md");
        if (! file_exists($path)) {
            $problems[] = "missing ADR: {$slug}.md";

            continue;
        }

        $text = (string) file_get_contents($path);
        // Every field the adoption phase requires, so an ADR cannot be a stub.
        foreach ([
            '- **Status:**', '- **Date:**', '## Context', '## Problem proven', '## Decision',
            '## Scope', '## Non-goals', '## Security implications', '## Accessibility implications',
            '## Responsive implications', '## Operational implications', '## Consequences',
            '## Rejected alternatives', '## Future implementation owner phase', '## Required tests',
            '## Traceability links', '## Superseded or related ADRs',
        ] as $section) {
            if (! str_contains($text, $section)) {
                $problems[] = "{$slug}: missing section '{$section}'";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));

    // Numbers must be unique across the whole ADR directory — a collision would make two
    // decisions share an identifier and silently overwrite each other in references.
    $numbers = [];
    foreach (glob(base_path('docs/architecture/adr/*.md')) ?: [] as $file) {
        $number = substr(basename($file), 0, 4);
        expect(in_array($number, $numbers, true))->toBeFalse("Duplicate ADR number: {$number}");
        $numbers[] = $number;
    }

    // The ten new ADRs took the next available numbers after the highest existing one (0015).
    foreach (UI00_ADRS as $slug) {
        expect($numbers)->toContain(substr($slug, 0, 4));
    }
});

it('records the binding UI decisions rather than implying them', function (): void {
    $decisions = [
        '0016-eight-account-hosts-one-application' => ['citrus.servana.ke', 'staff.servana.ke', 'one'],
        '0017-host-context-versus-authorization-context' => ['never', 'authorization'],
        '0020-role-navigation-registry-and-navigation-map-parity' => ['header', 'left', '160'],
        '0021-design-tokens-light-mode-default-dark-mode-persistence' => ['light', 'prefers-color-scheme'],
        '0022-static-content-and-legal-document-compilation' => ['verbatim', 'docs/landing_page/'],
        '0023-role-specific-landing-image-manifest' => ['two to four', 'manifest'],
        '0024-fixed-footer-layout-and-obstruction-prevention' => ['reserve', 'obstruct'],
        '0025-visual-regression-and-browser-proof-policy' => ['review', 'provenance'],
    ];

    $problems = [];
    foreach ($decisions as $slug => $markers) {
        $text = (string) file_get_contents(base_path("docs/architecture/adr/{$slug}.md"));
        foreach ($markers as $marker) {
            if (stripos($text, $marker) === false) {
                $problems[] = "{$slug} does not record '{$marker}'";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

// ---------------------------------------------------------------------------------------------
// 7. Generated-artifact determinism
// ---------------------------------------------------------------------------------------------

it('keeps every generated UI source artifact reproducible and current', function (): void {
    foreach (['navigation-map', 'role-content', 'brand-assets', 'landing-images'] as $artifact) {
        $inventory = ui00Inventory($artifact);
        expect($inventory['generated_by'])->toBe(
            'scripts/generate-ui-source-inventory.mjs',
            "{$artifact}.json does not record its generator.",
        );
    }

    // The navigation map must match the plan it was generated from, so an Appendix A edit cannot
    // silently leave the binding specification behind.
    $inventory = ui00Inventory('navigation-map');
    expect($inventory['source_plan_sha256'])->toBe(
        hash_file('sha256', base_path(UI00_PLAN)),
        'The UI/UX plan changed but the navigation map was not regenerated. '
            .'Run: node scripts/generate-ui-source-inventory.mjs',
    );
});
