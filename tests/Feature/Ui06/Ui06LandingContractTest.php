<?php

declare(strict_types=1);

uses()->group('docs', 'ui06', 'contracts');

/*
 |==============================================================================
 | Phase UI-06 — the public landing contract, read from the repository.
 |
 | These are STATIC contract tests. They touch no database, because nothing they assert depends on
 | one: the eight public landing pages are built from source-controlled content, a source-controlled
 | account-host registry and a source-controlled image manifest, and the audit artifacts under
 | docs/frontend/audits/ui-06/ are DERIVED from those by `node scripts/generate-ui06-artifacts.mjs`.
 |
 | The artifacts are the subject here rather than the TypeScript, for one reason: the PHP image
 | carries no Node runtime, so it cannot execute the modules. The staleness gate that keeps the
 | artifacts honest runs in the Frontend job (`node scripts/generate-ui06-artifacts.mjs --check`)
 | and is asserted again from `Ui06GeneratedArtifactStalenessTest`. Together they close the loop:
 | the artifacts describe the code, and these assertions describe the artifacts.
 */

/** The eight account keys, in the registry's canonical order. */
const UI06_ACCOUNTS = [
    'merchant_administrator',
    'merchant_audit',
    'merchant_branch',
    'merchant_finance',
    'merchant_front_office',
    'merchant_human_resource',
    'merchant_personnel',
    'super_administrator',
];

/** The sixteen semantic regions UI/UX plan §8.3 binds, in plan order. */
const UI06_REGIONS = [
    'header_navigation', 'hero', 'social_proof', 'problem', 'solution', 'features', 'how_it_works',
    'benefits', 'product_showcase', 'use_cases', 'testimonials', 'pricing', 'security', 'faq',
    'final_cta', 'footer',
];

/**
 * @return array<string, mixed>
 */
function ui06Audit(string $name): array
{
    $path = base_path("docs/frontend/audits/ui-06/{$name}.json");

    expect(is_file($path))->toBeTrue("missing UI-06 audit artifact: {$name}.json");

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/** Every source file under a directory, for the source-scan assertions. */
function ui06SourceFiles(string $relative, string $extension): array
{
    $root = base_path($relative);
    if (! is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === $extension) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

// ==============================================================================================
// Ui06LandingPageContractTest — eight pages exist and each is its own
// ==============================================================================================

it('declares exactly one landing page per approved account host', function (): void {
    $manifest = ui06Audit('landing-page-manifest');

    expect($manifest['account_count'])->toBe(8);
    expect(array_column($manifest['accounts'], 'account_key'))->toBe(UI06_ACCOUNTS);
});

it('points every landing page at its own compiled content, never another account\'s', function (): void {
    foreach (ui06Audit('landing-page-manifest')['accounts'] as $account) {
        $key = $account['account_key'];

        expect($account['content_source'])->toBe("docs/landing_page/{$key}_landing_page_content.md");
        expect($account['content_sha256'])->toMatch('/^[0-9a-f]{64}$/');
        expect($account['faq_source'])->toBe("docs/support/faq/{$key}_faq.md");

        foreach (UI06_ACCOUNTS as $other) {
            if ($other !== $key) {
                expect($account['content_source'])->not->toContain($other);
                expect($account['faq_source'])->not->toContain($other);
            }
        }
    }
});

it('gives every landing page its own title, description and hero eyebrow', function (): void {
    // §8.1 forbids one generic content object with only the role title changed.
    $accounts = ui06Audit('landing-page-manifest')['accounts'];

    foreach (['document_title', 'meta_description', 'hero_eyebrow'] as $field) {
        $values = array_column($accounts, $field);
        expect(count(array_unique($values)))->toBe(count($values), "{$field} is shared between accounts");
    }
});

it('serves each account from its own composition module', function (): void {
    foreach (ui06Audit('landing-page-manifest')['accounts'] as $account) {
        expect(is_file(base_path($account['composition_module'])))
            ->toBeTrue("missing composition module for {$account['account_key']}");
    }

    // Eight modules, one per account, and no more.
    expect(ui06SourceFiles('resources/spa/src/content/landing/accounts', 'ts'))->toHaveCount(8);
});

// ==============================================================================================
// Ui06SectionParityTest — the sixteen regions
// ==============================================================================================

it('presents all sixteen semantic regions on every account page', function (): void {
    $parity = ui06Audit('section-parity');

    expect($parity['plan_regions'])->toBe(UI06_REGIONS);

    foreach ($parity['accounts'] as $account) {
        $rendered = array_column(array_filter($account['regions'], fn (array $r): bool => $r['rendered']), 'region');

        expect($rendered)->toBe(UI06_REGIONS, "{$account['account_key']} does not present all sixteen regions");
    }
});

it('never renders a section UI-05 marked non-publishable', function (): void {
    foreach (ui06Audit('section-parity')['accounts'] as $account) {
        foreach ($account['regions'] as $region) {
            if ($region['source_render_permitted'] === true || $region['source_presence'] === 'missing_from_source') {
                continue;
            }

            // The region is still PRESENT — it carries an approved factual alternative — but the
            // withheld source is never what fills it.
            expect($region['rendered_as'])->not->toBe('compiled_source');
        }
    }
});

it('gives every rendered region a unique anchor', function (): void {
    foreach (ui06Audit('section-parity')['accounts'] as $account) {
        $anchors = array_filter(array_column($account['regions'], 'anchor'));

        expect(count(array_unique($anchors)))->toBe(count($anchors), $account['account_key']);
    }
});

// ==============================================================================================
// Ui06TrustEvidenceContractTest — the approved factual alternative
// ==============================================================================================

it('publishes no customer evidence on any account', function (): void {
    foreach (ui06Audit('trust-evidence-matrix')['accounts'] as $account) {
        foreach ($account['items'] as $item) {
            expect($item['customer_claim'])->toBeFalse("{$account['account_key']}: {$item['title']}");
            expect($item['metric_claim'])->toBeFalse("{$account['account_key']}: {$item['title']}");
        }
    }
});

it('backs every trust-evidence item with a real repository source', function (): void {
    foreach (ui06Audit('trust-evidence-matrix')['accounts'] as $account) {
        foreach ($account['items'] as $item) {
            expect(is_file(base_path($item['source'])))
                ->toBeTrue("{$account['account_key']}: {$item['title']} cites {$item['source']}, which does not exist");
            expect(strlen((string) $item['source_reference']))->toBeGreaterThan(8);
        }
    }
});

it('uses only evidence types that cannot carry a customer claim', function (): void {
    $allowed = [
        'source_backed_capability', 'security_control', 'role_boundary',
        'operational_workflow', 'policy_commitment', 'factual_account_purpose',
    ];

    foreach (ui06Audit('trust-evidence-matrix')['accounts'] as $account) {
        foreach ($account['items'] as $item) {
            expect($item['evidence_type'])->toBeIn($allowed);
        }
    }
});

it('renders the compiled testimonial section only where UI-05 permitted it', function (): void {
    foreach (ui06Audit('trust-evidence-matrix')['accounts'] as $account) {
        if ($account['mode'] === 'compiled_source_section') {
            expect($account['source_render_permitted'])->toBeTrue($account['account_key']);
        }
        if ($account['source_render_permitted'] === false) {
            expect($account['mode'])->toBe('approved_factual_alternative', $account['account_key']);
        }
    }
});

it('gives every account a distinct trust-evidence set', function (): void {
    $signatures = [];
    foreach (ui06Audit('trust-evidence-matrix')['accounts'] as $account) {
        $signatures[] = $account['heading'].'|'.implode(',', array_column($account['items'], 'title'));
    }

    expect(count(array_unique($signatures)))->toBe(8);
});

// ==============================================================================================
// Ui06PricingPlanAccessContractTest
// ==============================================================================================

it('states no plan amount on any public landing page', function (): void {
    $matrix = ui06Audit('pricing-plan-access-matrix');

    foreach ($matrix['accounts'] as $account) {
        expect($account['shows_amount'])->toBeFalse($account['account_key']);
        expect($account['purchase_cta'])->toBeFalse($account['account_key']);

        $text = $account['heading'].' '.implode(' ', $account['points']);
        expect($text)->not->toMatch('/\bKES\s*[\d,]/i');
        expect($text)->not->toMatch('/\b\d[\d,]*\s*(\/|per)\s*month\b/i');
    }
});

it('never renders a compiled pricing section that states an amount', function (): void {
    foreach (ui06Audit('pricing-plan-access-matrix')['accounts'] as $account) {
        if ($account['renders_compiled_source']) {
            expect($account['source_states_amount'])->toBeFalse($account['account_key']);
        }
    }
});

it('records a reason for every piece of pricing content it withholds', function (): void {
    foreach (ui06Audit('pricing-plan-access-matrix')['accounts'] as $account) {
        foreach ($account['withheld'] as $entry) {
            expect(strlen((string) $entry['what']))->toBeGreaterThan(10);
            expect(strlen((string) $entry['reason']))->toBeGreaterThan(40);
        }
    }
});

it('keeps the canonical price authority out of reach of a public page', function (): void {
    // The binding decision rests on this: no fixture seeds plan prices and no public endpoint
    // exposes them, so no amount on a public page could be proven current. If either changed, the
    // decision would need revisiting rather than silently continuing to withhold.
    $routes = (string) file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('subscription/plans'");
    expect($routes)->toContain("EnsurePermission::class.':platform.plan.view'");

    $seeder = (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
    expect($seeder)->not->toContain('SubscriptionPlanPrice');
});

it('invents no generic pricing tier', function (): void {
    foreach (ui06Audit('pricing-plan-access-matrix')['accounts'] as $account) {
        $text = $account['heading'].' '.implode(' ', $account['points']);

        foreach (['Free tier', 'Basic plan', 'Pro plan', 'Enterprise plan'] as $tier) {
            expect($text)->not->toContain($tier);
        }
    }
});

it('gives Super Administrator plan administration rather than pricing', function (): void {
    $accounts = collect(ui06Audit('pricing-plan-access-matrix')['accounts'])->keyBy('account_key');

    expect($accounts['super_administrator']['mode'])->toBe('platform_plan_administration');
    expect($accounts['super_administrator']['source_presence'])->toBe('missing_from_source');
    expect($accounts['super_administrator']['withheld'])->toHaveCount(1);
});

// ==============================================================================================
// Ui06CtaContractTest
// ==============================================================================================

it('resolves every declared call to action, rejecting none', function (): void {
    foreach (ui06Audit('cta-matrix')['accounts'] as $account) {
        expect($account['rejected'])->toBe([], "{$account['account_key']} has an unresolvable CTA");
        expect(count($account['resolved']))->toBeGreaterThan(1, $account['account_key']);
    }
});

it('exposes merchant self-registration on exactly one account host', function (): void {
    $exposing = [];

    foreach (ui06Audit('cta-matrix')['accounts'] as $account) {
        foreach ($account['resolved'] as $cta) {
            if ($cta['kind'] === 'self_registration') {
                $exposing[] = $account['account_key'];
            }
        }
    }

    expect(array_unique($exposing))->toBe(['merchant_administrator']);
});

it('never offers an action the account-host registry forbids', function (): void {
    foreach (ui06Audit('cta-matrix')['accounts'] as $account) {
        foreach ($account['resolved'] as $cta) {
            if ($cta['kind'] === 'self_registration') {
                expect($account['self_registration_permitted'])->toBeTrue($account['account_key']);
            }
            if ($cta['kind'] === 'invitation_acceptance') {
                expect($account['invitation_acceptance_permitted'])->toBeTrue($account['account_key']);
            }
        }
    }
});

it('keeps every call to action on the current host', function (): void {
    foreach (ui06Audit('cta-matrix')['accounts'] as $account) {
        foreach ($account['resolved'] as $cta) {
            $url = (string) $cta['same_host_url'];

            expect(str_starts_with($url, '/') || str_starts_with($url, '#'))
                ->toBeTrue("{$account['account_key']}/{$cta['key']} points at {$url}");
            expect($url)->not->toStartWith('//');
            expect($url)->not->toContain('://');
        }
    }
});

it('gives every account a sign-in action and exactly one primary action', function (): void {
    foreach (ui06Audit('cta-matrix')['accounts'] as $account) {
        $kinds = array_column($account['resolved'], 'kind');
        $primary = array_filter($account['resolved'], fn (array $cta): bool => $cta['emphasis'] === 'primary');

        // `toContain` is VARIADIC in Pest: a second argument is another needle, not a message.
        expect($kinds)->toContain('sign_in');
        expect($primary)->toHaveCount(1, $account['account_key']);
    }
});

it('records why every call to action is offered and where it came from', function (): void {
    foreach (ui06Audit('cta-matrix')['accounts'] as $account) {
        foreach ($account['resolved'] as $cta) {
            expect(strlen((string) $cta['eligibility_reason']))->toBeGreaterThan(20);
            expect(strlen((string) $cta['source_section']))->toBeGreaterThan(5);
        }
    }
});

it('writes every call-to-action label in sentence case', function (): void {
    // Brand Identity, "Buttons": "Use sentence case. Good: Create invoice. Avoid: CREATE INVOICE."
    foreach (ui06Audit('cta-matrix')['accounts'] as $account) {
        foreach ($account['resolved'] as $cta) {
            expect($cta['label'])->not->toBe(mb_strtoupper((string) $cta['label']));
        }
    }
});

// ==============================================================================================
// Ui06PublicRouteContractTest
// ==============================================================================================

it('registers every public route UI/UX plan §4.2 requires on every host', function (): void {
    $matrix = ui06Audit('public-route-matrix');
    $paths = [];
    foreach ($matrix['routes'] as $route) {
        $paths[] = $route['absolute_path'];
        foreach ($route['aliases'] as $alias) {
            $paths[] = $alias;
        }
    }

    // `toContain` is VARIADIC in Pest, so the whole required set is asserted in one call and a
    // missing member is named by the failure itself.
    expect($paths)->toContain('/', '/login', '/auth/magic-link/request', '/auth/magic-link/consume', '/faq');

    // The three legal documents live behind one closed alternation, so an unknown slug is a routing
    // miss rather than a component branch.
    $legal = collect($matrix['routes'])->firstWhere('name', 'public.legal');
    expect($legal['absolute_path'])->toBe('/legal/:doc(data-policy|privacy-policy|terms-of-service)');
});

it('provides registration and setup for the merchant host', function (): void {
    $matrix = ui06Audit('public-route-matrix');
    $paths = collect($matrix['routes'])->pluck('absolute_path')->all();
    $aliases = collect($matrix['routes'])->pluck('aliases')->flatten()->all();

    expect($aliases)->toContain('/register');
    expect($paths)->toContain('/setup');
});

it('keeps the plan-named paths as aliases of one implementation, not second pages', function (): void {
    // Two login screens would be two things to secure and two things to drift.
    $routes = collect(ui06Audit('public-route-matrix')['routes'])->keyBy('name');

    expect($routes['auth.login']['absolute_path'])->toBe('/auth/login');
    expect($routes['auth.login']['aliases'])->toBe(['/login', '/auth/magic-link/request']);
    expect($routes['auth.verify']['aliases'])->toBe(['/auth/magic-link/consume']);
    expect($routes['merchant.setup']['aliases'])->toBe(['/onboarding/first-time-setup']);
});

it('keeps the invitation-acceptance route host-relative', function (): void {
    $routes = collect(ui06Audit('public-route-matrix')['routes'])->keyBy('name');

    expect($routes['staff.accept']['absolute_path'])->toBe('/staff/accept');
});

it('records the compatibility treatment of the role-parameter legal route', function (): void {
    $legacy = ui06Audit('public-route-matrix')['legacy_role_parameter_route'];

    expect($legacy['path'])->toBe('/legal/:role/:doc');
    expect($legacy['treatment'])->toContain('fails closed');
    expect($legacy['treatment'])->toContain('UI06-LEGAL-001');
});

// ==============================================================================================
// Ui06LegalRouteContractTest — twenty-four canonical routes
// ==============================================================================================

it('publishes twenty-four canonical legal routes, three per account', function (): void {
    $matrix = ui06Audit('legal-link-matrix');

    expect($matrix['rows'])->toHaveCount(24);
    expect($matrix['account_selected_by'])->toContain('never a path segment');

    foreach (UI06_ACCOUNTS as $account) {
        $rows = array_values(array_filter(
            $matrix['rows'],
            fn (array $row): bool => $row['account_key'] === $account,
        ));

        expect($rows)->toHaveCount(3, $account);
        expect(array_column($rows, 'document'))
            ->toBe(['data-policy', 'privacy-policy', 'terms-of-service']);
    }
});

it('serves each account its own legal documents, verbatim from its own source', function (): void {
    foreach (ui06Audit('legal-link-matrix')['rows'] as $row) {
        $source = base_path($row['source_path']);

        expect(is_file($source))->toBeTrue($row['source_path']);
        expect(hash_file('sha256', $source))->toBe($row['source_sha256'], $row['source_path']);
        expect($row['source_path'])->toContain($row['account_key']);

        foreach (UI06_ACCOUNTS as $other) {
            if ($other !== $row['account_key']) {
                expect($row['source_path'])->not->toContain($other);
            }
        }
    }
});

it('builds no legal destination that carries a role in the path', function (): void {
    foreach (ui06Audit('legal-link-matrix')['rows'] as $row) {
        expect($row['route'])->toBe("/legal/{$row['document']}");
    }
});

// ==============================================================================================
// Ui06FaqRouteContractTest
// ==============================================================================================

it('publishes an FAQ route on every account host, serving that account\'s own questions', function (): void {
    $matrix = ui06Audit('faq-route-matrix');

    expect($matrix['route'])->toBe('/faq');
    expect($matrix['accounts'])->toHaveCount(8);

    foreach ($matrix['accounts'] as $account) {
        expect($account['route'])->toBe('/faq');
        expect($account['source_path'])->toBe("docs/support/faq/{$account['account_key']}_faq.md");
        expect(hash_file('sha256', base_path($account['source_path'])))->toBe($account['source_sha256']);
        expect($account['item_count'])->toBeGreaterThan(50);
        expect($account['all_items_rendered'])->toBeTrue();
    }
});

it('carries the full compiled FAQ, including the sixty questions UI05-FAQ-001 recovered', function (): void {
    $matrix = ui06Audit('faq-route-matrix');
    $accounts = collect($matrix['accounts'])->keyBy('account_key');

    expect($matrix['total_items'])->toBe(1264);
    expect($accounts['merchant_administrator']['item_count'])->toBe(196);
});

// ==============================================================================================
// Ui06ImageRenderContractTest
// ==============================================================================================

it('renders the curated images and nothing else', function (): void {
    $matrix = ui06Audit('image-render-matrix');

    expect($matrix['total_images'])->toBe(32);

    foreach ($matrix['accounts'] as $account) {
        expect($account['image_count'])->toBeGreaterThanOrEqual(2);
        expect($account['image_count'])->toBeLessThanOrEqual(4);
    }
});

it('marks exactly one image per page eager and high priority', function (): void {
    foreach (ui06Audit('image-render-matrix')['accounts'] as $account) {
        expect($account['high_priority_count'])->toBe(1, $account['account_key']);

        foreach ($account['images'] as $image) {
            if ($image['fetch_priority'] === 'high') {
                expect($image['landing_section'])->toBe('hero', $account['account_key']);
                expect($image['loading'])->toBe('eager');

                continue;
            }
            expect($image['loading'])->toBe('lazy', "{$account['account_key']}/{$image['landing_section']}");
        }
    }
});

it('places every image in a region that actually renders', function (): void {
    // An image mapped to a withheld region would be loaded and never shown.
    foreach (ui06Audit('image-render-matrix')['accounts'] as $account) {
        foreach ($account['images'] as $image) {
            expect($image['section_renders'])
                ->toBeTrue("{$account['account_key']}: {$image['landing_section']} does not render");
        }
    }
});

it('renders only files from the account\'s own directory, with real dimensions and alt text', function (): void {
    foreach (ui06Audit('image-render-matrix')['accounts'] as $account) {
        $key = $account['account_key'];

        foreach ($account['images'] as $image) {
            expect($image['source_public_path'])->toStartWith("/assets/landing_page_images/{$key}/");
            expect(is_file(public_path(ltrim((string) $image['source_public_path'], '/'))))->toBeTrue();

            expect($image['intrinsic_width'])->toBeGreaterThan(100);
            expect($image['intrinsic_height'])->toBeGreaterThan(100);
            expect($image['decorative'])->toBeFalse();
            expect(strlen((string) $image['alternative_text']))->toBeGreaterThan(30);
            // Alt text describes the illustration, never the role key.
            expect($image['alternative_text'])->not->toContain($key);

            expect($image['derivative_paths'])->toHaveCount(6);
            foreach ($image['derivative_paths'] as $derivative) {
                expect(is_file(public_path(ltrim((string) $derivative, '/'))))->toBeTrue($derivative);
                expect($derivative)->toStartWith("/assets/landing_page_images/generated/{$key}/");
            }
        }
    }
});

// ==============================================================================================
// Ui06HostContentIsolationTest
// ==============================================================================================

it('loads each account\'s composition through its own static import', function (): void {
    // A template-built specifier would let a browser-supplied value influence which module loads.
    $loader = (string) file_get_contents(base_path('resources/spa/src/content/landing/index.ts'));

    expect($loader)->not->toMatch('/import\(\s*`/');
    expect($loader)->toContain('unknown account key');
    expect($loader)->toContain('Landing composition mismatch');

    foreach (UI06_ACCOUNTS as $account) {
        expect($loader)->toContain("{$account}:");
    }
});

it('lets no composition module import another account\'s module or content', function (): void {
    foreach (ui06SourceFiles('resources/spa/src/content/landing/accounts', 'ts') as $path) {
        $body = (string) file_get_contents($path);
        $own = pathinfo($path, PATHINFO_FILENAME);

        expect($body)->not->toContain('content/generated/');
        foreach (['merchantAdministrator', 'merchantAudit', 'merchantBranch', 'merchantFinance',
            'merchantFrontOffice', 'merchantHumanResource', 'merchantPersonnel', 'superAdministrator'] as $module) {
            if ($module !== $own) {
                expect($body)->not->toContain("accounts/{$module}");
            }
        }
    }
});

it('resolves the public account from the server context, never from a path parameter', function (): void {
    foreach ([
        'resources/spa/src/pages/public/PublicLandingPage.vue',
        'resources/spa/src/pages/public/PublicFaqPage.vue',
        'resources/spa/src/pages/public/PublicLegalPage.vue',
    ] as $page) {
        $body = (string) file_get_contents(base_path($page));

        expect($body)->toContain('currentAccountContext');
        // No page reads a role from the route.
        expect($body)->not->toMatch("/route\.params\[?'?role/");
    }
});

// ==============================================================================================
// Ui06NoFabricatedEvidenceTest — a source scan over what the pages can render
// ==============================================================================================

it('contains no fabricated customer evidence anywhere in the public landing source', function (): void {
    $forbidden = [
        '/\b\d+\s*%\s*(faster|more|better|increase|improvement)/i' => 'a performance-improvement claim',
        '/\b\d[\d,]{2,}\+?\s*(users|merchants|businesses|customers|salons)\b/i' => 'an adoption statistic',
        '/\b\d(\.\d)?\s*(\/\s*5|stars?|out of 5)\b/i' => 'a rating',
        '/\btrusted by\b/i' => 'an unverifiable trust claim',
    ];

    $files = array_merge(
        ui06SourceFiles('resources/spa/src/content/landing', 'ts'),
        ui06SourceFiles('resources/spa/src/components/landing', 'vue'),
        ui06SourceFiles('resources/spa/src/pages/public', 'vue'),
    );

    foreach ($files as $path) {
        if (str_ends_with($path, '.spec.ts')) {
            continue;
        }
        $body = (string) file_get_contents($path);
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

        foreach ($forbidden as $pattern => $description) {
            expect(preg_match($pattern, $body))->toBe(0, "{$relative} contains {$description}");
        }
    }
});

it('renders no quotation styling on the trust-evidence region', function (): void {
    // The TEMPLATE is the subject. The component's own documentation explains why it renders no
    // blockquote, so scanning the whole file would match the explanation and never the markup.
    $body = (string) file_get_contents(
        base_path('resources/spa/src/components/landing/LandingTrustEvidence.vue')
    );
    $template = substr($body, (int) strpos($body, '<template>'));

    expect($template)->not->toContain('<blockquote');
    expect($template)->not->toContain('<cite');
    expect($template)->not->toContain('&ldquo;');
    expect($template)->not->toContain('&quot;');
});

it('uses no emoji icon and no JavaScript device detection in the public surface', function (): void {
    $files = array_merge(
        ui06SourceFiles('resources/spa/src/components/landing', 'vue'),
        ui06SourceFiles('resources/spa/src/pages/public', 'vue'),
        ui06SourceFiles('resources/spa/src/layouts', 'vue'),
    );

    foreach ($files as $path) {
        $body = (string) file_get_contents($path);
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

        expect(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $body))
            ->toBe(0, "{$relative} contains an emoji");
        foreach (['navigator.userAgent', 'window.innerWidth', 'matchMedia(', 'ontouchstart'] as $detection) {
            expect($body)->not->toContain($detection, "{$relative} performs device detection");
        }
    }
});

it('introduces no unsafe raw HTML into the public surface', function (): void {
    // `v-html` appears only where the input has passed through the audited markdown renderer.
    foreach (array_merge(
        ui06SourceFiles('resources/spa/src/components/landing', 'vue'),
        ui06SourceFiles('resources/spa/src/pages/public', 'vue'),
    ) as $path) {
        $body = (string) file_get_contents($path);
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

        if (! str_contains($body, 'v-html')) {
            continue;
        }

        expect($body)->toContain('renderMarkdown');
        expect(str_contains($body, 'renderMarkdown'))
            ->toBeTrue("{$relative} uses v-html without the audited renderer");
    }
});
