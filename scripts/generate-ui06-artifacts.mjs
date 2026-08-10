#!/usr/bin/env node
// Phase UI-06 — derive the deterministic public-landing audit artifacts from their real sources.
//
// These are DERIVED, never hand-maintained, so they cannot drift from the code they describe:
//
//   docs/frontend/audits/ui-06/landing-page-manifest.json      <- the eight compositions + content
//   docs/frontend/audits/ui-06/section-parity.json             <- the sixteen regions, per account
//   docs/frontend/audits/ui-06/cta-matrix.json                 <- compositions x account-host registry
//   docs/frontend/audits/ui-06/trust-evidence-matrix.json      <- the approved factual alternative
//   docs/frontend/audits/ui-06/pricing-plan-access-matrix.json <- plan access, and what is withheld
//   docs/frontend/audits/ui-06/image-render-matrix.json        <- curated manifest x rendered regions
//   docs/frontend/audits/ui-06/public-route-matrix.json        <- the router, read as source
//   docs/frontend/audits/ui-06/legal-link-matrix.json          <- 8 accounts x 3 documents
//   docs/frontend/audits/ui-06/faq-route-matrix.json           <- 8 accounts x their compiled FAQ
//
// The composition modules are TypeScript, so they are loaded through Vite's own SSR transform
// rather than parsed with a regular expression. Reading a contract by regex is how an artifact
// ends up describing something the code does not do — the parser succeeds, matches nothing, and
// the assertions built on it become vacuous.
//
// The browser-derived artifacts (responsive, theme, accessibility, network, performance,
// screenshots) are written by tests/e2e/ui-06-public-landing-pages.spec.ts, because they record
// what a browser actually did and cannot be derived from source.
//
// Usage: node scripts/generate-ui06-artifacts.mjs [--check]

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');
const OUT = 'docs/frontend/audits/ui-06';

const sha256 = (path) =>
  existsSync(join(ROOT, path)) ? createHash('sha256').update(readFileSync(join(ROOT, path))).digest('hex') : null;

const failures = [];
const written = [];

function emit(name, payload) {
  const relative = `${OUT}/${name}`;
  const body = `${JSON.stringify(payload, null, 2)}\n`;
  const absolute = join(ROOT, relative);

  if (CHECK_ONLY) {
    const current = existsSync(absolute) ? readFileSync(absolute, 'utf8') : null;
    if (current !== body) {
      failures.push(relative);
    }

    return;
  }

  mkdirSync(dirname(absolute), { recursive: true });
  writeFileSync(absolute, body, 'utf8');
  written.push(relative);
}

// ---------------------------------------------------------------------------------------------
// Load the TypeScript authorities through Vite's SSR transform
// ---------------------------------------------------------------------------------------------

const server = await createServer({
  configFile: join(ROOT, 'vite.config.ts'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
});

const load = (specifier) => server.ssrLoadModule(specifier);

const { LANDING_COMPOSITION_KEYS, loadLandingComposition } = await load('/src/content/landing/index.ts');
const { LANDING_REGION_ORDER, regionAnchorId } = await load('/src/content/landing/landingContract.ts');
const { parseLandingSection } = await load('/src/content/landing/landingSection.ts');
const { resolveCtas } = await load('/src/content/landing/ctaResolver.ts');
const { ACCOUNT_HOSTS } = await load('/src/host/accountHosts.generated.ts');
const { LANDING_IMAGES } = await load('/src/content/generated/landingImages.generated.ts');
const generatedContent = await load('/src/content/generated/index.generated.ts');
const { PUBLIC_LEGAL_DOCS, PUBLIC_LEGAL_TITLES } = await load('/src/router/publicRoutes.ts');

const ACCOUNTS = [...LANDING_COMPOSITION_KEYS].sort();

/** Everything one account contributes, gathered once. */
const accounts = new Map();
for (const key of ACCOUNTS) {
  const composition = await loadLandingComposition(key);
  const landing = await generatedContent.loadGeneratedLanding(key);
  const faq = await generatedContent.loadGeneratedFaq(key);
  const legal = {};
  for (const doc of PUBLIC_LEGAL_DOCS) {
    const category = doc.replace(/-/g, '_');
    legal[doc] = await generatedContent.loadGeneratedLegal(key, category);
  }

  const parsed = new Map();
  for (const section of landing.sections) {
    if (section.presence === 'present_in_source' && section.renderPermitted) {
      parsed.set(section.region, parseLandingSection(section.markdown));
    }
  }

  accounts.set(key, { composition, landing, faq, legal, parsed });
}

/** The regions each page renders — the same rule the page component applies. */
function renderedRegions(key) {
  const { parsed } = accounts.get(key);
  const regions = new Set(['testimonials', 'pricing']);
  for (const region of LANDING_REGION_ORDER) {
    if (region === 'header_navigation' || region === 'footer') {
      continue;
    }
    if (parsed.has(region)) {
      regions.add(region);
    }
  }

  return regions;
}

/**
 * Every route the SPA registers, walked from the ROUTE RECORDS themselves.
 *
 * Not parsed out of the source text: an earlier revision of this generator did exactly that and
 * reported `auth.login` at `/auth`, every HR child under `/auth/...`, and no `public.legal` at all,
 * because one route path is built from a template literal. The records are the authority, and
 * `vue-router` is not needed to read them — only the tree walk is.
 *
 * The router itself is deliberately NOT imported: `createWebHistory()` needs a browser.
 */
async function routeTable() {
  const modules = [
    ['resources/spa/src/router/routes/public.ts', '/src/router/routes/public.ts', 'publicRoutes'],
    ['resources/spa/src/router/routes/auth.ts', '/src/router/routes/auth.ts', 'authRoutes'],
    ['resources/spa/src/router/routes/merchant.ts', '/src/router/routes/merchant.ts', 'merchantRoutes'],
    ['resources/spa/src/router/routes/hr.ts', '/src/router/routes/hr.ts', 'hrRoutes'],
    // Invitation acceptance is pre-membership and registered on every host, outside the guarded HR tree.
    ['resources/spa/src/router/routes/hr.ts', '/src/router/routes/hr.ts', 'invitationRoutes'],
    ['resources/spa/src/router/routes/branch.ts', '/src/router/routes/branch.ts', 'branchRoutes'],
    ['resources/spa/src/router/routes/finance.ts', '/src/router/routes/finance.ts', 'financeRoutes'],
    ['resources/spa/src/router/routes/frontOffice.ts', '/src/router/routes/frontOffice.ts', 'frontOfficeRoutes'],
    ['resources/spa/src/router/routes/personnel.ts', '/src/router/routes/personnel.ts', 'personnelRoutes'],
    ['resources/spa/src/router/routes/platform.ts', '/src/router/routes/platform.ts', 'platformRoutes'],
    ['resources/spa/src/router/routes/audit.ts', '/src/router/routes/audit.ts', 'auditRoutes'],
    ['resources/spa/src/router/routes/search.ts', '/src/router/routes/search.ts', 'searchRoutes'],
  ];

  const table = {};

  /** vue-router's own rule: a child path not starting with `/` is appended to its parent. */
  const joinPath = (parent, child) => {
    if (child.startsWith('/')) {
      return child;
    }
    if (child === '') {
      return parent;
    }

    return `${parent === '/' ? '' : parent}/${child}`;
  };

  const walk = (records, parentPath, file) => {
    for (const record of records) {
      const path = joinPath(parentPath, record.path ?? '');
      if (typeof record.name === 'string') {
        table[record.name] = {
          path: record.path,
          absolute_path: path,
          aliases: [record.alias ?? []].flat(),
          declared_in: file,
        };
      }
      if (Array.isArray(record.children)) {
        walk(record.children, path, file);
      }
    }
  };

  for (const [file, specifier, exported] of modules) {
    const module = await load(specifier);
    walk(module[exported], '', file);
  }

  return table;
}

const ROUTES = await routeTable();

/** Absolute path for a route name, mirroring how the page resolves one. */
function routePath(name) {
  return ROUTES[name]?.absolute_path ?? null;
}

// ---------------------------------------------------------------------------------------------
// Artifacts
// ---------------------------------------------------------------------------------------------

const provenance = {
  generated_by: 'node scripts/generate-ui06-artifacts.mjs',
  phase: 'UI-06',
  authorities: {
    account_host_registry: {
      path: 'config/account-hosts.json',
      sha256: sha256('config/account-hosts.json'),
    },
    content_manifest: {
      path: 'docs/frontend/audits/ui-05/content-source-manifest.json',
      sha256: sha256('docs/frontend/audits/ui-05/content-source-manifest.json'),
    },
    image_manifest: {
      path: 'public/assets/landing_page_images/manifest.json',
      sha256: sha256('public/assets/landing_page_images/manifest.json'),
    },
    logo: { path: 'public/assets/brand/Logo.png', sha256: sha256('public/assets/brand/Logo.png') },
  },
};

emit('landing-page-manifest.json', {
  ...provenance,
  purpose:
    'One row per account host: which composition, which compiled content, which curated images and which routes make up its public landing page.',
  account_count: ACCOUNTS.length,
  accounts: ACCOUNTS.map((key) => {
    const { composition, landing, faq } = accounts.get(key);
    const definition = ACCOUNT_HOSTS[key];
    const rendered = renderedRegions(key);

    return {
      account_key: key,
      display_name: definition.displayName,
      production_host: definition.hosts.production,
      local_host: definition.hosts.local,
      landing_route: '/',
      faq_route: '/faq',
      legal_routes: PUBLIC_LEGAL_DOCS.map((doc) => `/legal/${doc}`),
      composition_module: `resources/spa/src/content/landing/accounts/${
        key.replace(/_(.)/g, (_, character) => character.toUpperCase())
      }.ts`,
      document_title: composition.documentTitle,
      meta_description: composition.metaDescription,
      hero_eyebrow: composition.heroEyebrow,
      content_source: landing.meta.sourcePath,
      content_sha256: landing.meta.sourceSha256,
      faq_source: faq.meta.sourcePath,
      faq_item_count: faq.items.length,
      navigation: composition.navigation.map((item) => ({
        label: item.label,
        region: item.region,
        anchor: `#${regionAnchorId(item.region)}`,
      })),
      rendered_region_count: rendered.size,
      curated_image_count: LANDING_IMAGES.filter((image) => image.accountKey === key).length,
    };
  }),
});

emit('section-parity.json', {
  ...provenance,
  purpose:
    'The sixteen semantic regions of UI/UX plan §8.3, per account: where each comes from, and — where the source supplies nothing publishable — which approved alternative fills it.',
  plan_regions: LANDING_REGION_ORDER,
  region_treatments: {
    header_navigation: 'Rendered as the real LandingHeader (logo, in-page navigation, resolved CTAs, mobile menu). The compiled section is build instruction, never body copy.',
    footer: 'Rendered as the real SvFixedFooter. The compiled section duplicates its link list and is never rendered as body copy.',
    testimonials: 'Approved factual trust evidence on all eight accounts (binding decisions §2.1 and §2.2). No customer quotation is published.',
    pricing: 'Role-appropriate plan access. No amount is published anywhere (binding decisions §2.3 and §2.4).',
  },
  accounts: ACCOUNTS.map((key) => {
    const { landing, parsed, composition } = accounts.get(key);
    const rendered = renderedRegions(key);

    return {
      account_key: key,
      regions: LANDING_REGION_ORDER.map((region) => {
        const section = landing.sections.find((entry) => entry.region === region);
        const isStructural = region === 'header_navigation' || region === 'footer';
        const parsedSection = parsed.get(region) ?? null;

        return {
          region,
          source_presence: section?.presence ?? 'missing_from_source',
          source_heading: section?.sourceHeading ?? null,
          content_restriction: section?.contentRestriction ?? 'none',
          source_render_permitted: section?.renderPermitted ?? false,
          rendered: isStructural ? true : rendered.has(region),
          rendered_as: isStructural
            ? (region === 'footer' ? 'SvFixedFooter' : 'LandingHeader')
            : region === 'testimonials'
              ? `trust_evidence:${composition.trust.mode}`
              : region === 'pricing'
                ? `plan_access:${composition.planAccess.mode}`
                : rendered.has(region)
                  ? 'compiled_source'
                  : 'not_rendered',
          anchor: isStructural ? null : `#${regionAnchorId(region)}`,
          item_count: parsedSection?.items.length ?? 0,
          dropped_annotations: parsedSection?.droppedAnnotations ?? [],
        };
      }),
    };
  }),
});

emit('cta-matrix.json', {
  ...provenance,
  purpose:
    'Every call to action on every public landing page, resolved against the account-host registry and the live route table. A CTA that could not be resolved appears under `rejected`.',
  rule: 'Only an account the registry marks selfRegistration:true may expose merchant self-registration; only invitationAcceptance:true may link invitation acceptance; every destination is a path on the current host.',
  accounts: ACCOUNTS.map((key) => {
    const { composition } = accounts.get(key);
    const definition = ACCOUNT_HOSTS[key];
    const resolution = resolveCtas(composition.ctas, definition, routePath, renderedRegions(key));

    return {
      account_key: key,
      self_registration_permitted: definition.selfRegistration,
      invitation_acceptance_permitted: definition.invitationAcceptance,
      public_cta_category: definition.publicCtaCategory,
      resolved: resolution.resolved.map((cta) => ({
        key: cta.key,
        label: cta.label,
        kind: cta.kind,
        emphasis: cta.emphasis,
        route_name: cta.routeName,
        same_host_url: cta.href,
        eligibility_reason: cta.eligibilityReason,
        source_section: cta.sourceSection,
      })),
      rejected: resolution.rejected,
    };
  }),
});

emit('trust-evidence-matrix.json', {
  ...provenance,
  purpose:
    'The approved factual alternative to customer testimonials, per account. Every item names what backs it, and declares that it makes no customer and no metric claim.',
  binding_decision:
    'No supplied customer testimonial quotation is approved for production. Four accounts supply unverified or self-declared placeholder quotations (UI05-CONTENT-001); three supply no testimonials section at all (UI05-CONTENT-002). All eight pages carry a factual alternative instead. No source text was deleted or rewritten.',
  forbidden_in_production: [
    'customer names', 'customer company names', 'customer quotations', 'placeholder quotations',
    'merchant logos', 'ratings', 'review counts', 'user counts', 'adoption statistics',
    'performance-improvement percentages', 'unverified customer outcomes',
  ],
  accounts: ACCOUNTS.map((key) => {
    const { composition, landing } = accounts.get(key);
    const section = landing.sections.find((entry) => entry.region === 'testimonials');

    return {
      account_key: key,
      source_presence: section?.presence ?? 'missing_from_source',
      source_restriction: section?.contentRestriction ?? 'none',
      source_render_permitted: section?.renderPermitted ?? false,
      heading: composition.trust.heading,
      mode: composition.trust.mode,
      intro: composition.trust.intro,
      items: composition.trust.items.map((item) => ({
        title: item.title,
        detail: item.detail,
        evidence_type: item.evidenceType,
        source: item.source,
        source_reference: item.sourceReference,
        customer_claim: item.customerClaim,
        metric_claim: item.metricClaim,
      })),
    };
  }),
});

emit('pricing-plan-access-matrix.json', {
  ...provenance,
  purpose:
    'What each account publishes about plans, and what it deliberately does not. No page states an amount.',
  price_authority: {
    canonical: 'subscription_plan_prices (Phase 20A) — configured by the platform operator at runtime',
    repository_fixture: 'none: no seeder or config file supplies plan prices',
    public_endpoint: 'none: GET /api/v1/subscription/plans requires an authenticated merchant session; the platform catalogue requires platform.plan.view',
    consequence:
      'A public page cannot prove any amount is current, and UI/UX plan §8.5 forbids showing a stale one. Binding decision §2.4 applies: the role-appropriate plan-access explanation, and no amount.',
  },
  accounts: ACCOUNTS.map((key) => {
    const { composition, landing } = accounts.get(key);
    const section = landing.sections.find((entry) => entry.region === 'pricing');

    return {
      account_key: key,
      mode: composition.planAccess.mode,
      heading: composition.planAccess.heading,
      source_presence: section?.presence ?? 'missing_from_source',
      source_states_amount: /\bKES\s*[\d,]/i.test(section?.markdown ?? ''),
      renders_compiled_source: composition.planAccess.renderCompiledSource,
      shows_amount: composition.planAccess.showsAmount,
      purchase_cta: composition.planAccess.purchaseCta,
      points: composition.planAccess.points,
      withheld: composition.planAccess.withheld.map((entry) => ({
        what: entry.what,
        reason: entry.reason,
      })),
    };
  }),
});

emit('image-render-matrix.json', {
  ...provenance,
  purpose:
    'Which curated image each account renders in which region, with the loading strategy and the responsive candidates the page emits.',
  rule: 'Exactly one eager, high-priority image per page (the hero). Every other image is lazy and auto priority. No account renders another account\'s file.',
  total_images: LANDING_IMAGES.length,
  accounts: ACCOUNTS.map((key) => {
    const rendered = renderedRegions(key);
    const images = LANDING_IMAGES.filter((image) => image.accountKey === key);

    return {
      account_key: key,
      image_count: images.length,
      high_priority_count: images.filter((image) => image.fetchPriority === 'high').length,
      images: images.map((image) => ({
        landing_section: image.landingSection,
        section_renders: rendered.has(image.landingSection),
        source_public_path: image.sourcePublicPath,
        alternative_text: image.alternativeText,
        decorative: image.decorative,
        intrinsic_width: image.intrinsicWidth,
        intrinsic_height: image.intrinsicHeight,
        loading: image.loading,
        fetch_priority: image.fetchPriority,
        sizes: image.sizes,
        derivative_paths: image.derivatives.map((derivative) => derivative.publicPath),
      })),
    };
  }),
});

emit('public-route-matrix.json', {
  ...provenance,
  purpose:
    'The public route contract of UI/UX plan §4.2, as the router actually declares it. Aliases are recorded because the plan names paths that resolve to existing implementations rather than new pages.',
  required_on_every_host: ['/', '/login', '/auth/magic-link/request', '/auth/magic-link/consume', '/faq', '/legal/data-policy', '/legal/privacy-policy', '/legal/terms-of-service'],
  merchant_administrator_additional: ['/register', '/setup'],
  invitation_accounts_additional: ['/staff/accept'],
  routes: Object.entries(ROUTES)
    .map(([name, entry]) => ({
      name,
      path: entry.path,
      absolute_path: entry.absolute_path,
      aliases: entry.aliases,
      declared_in: entry.declared_in,
    }))
    .sort((a, b) => a.name.localeCompare(b.name)),
  legacy_role_parameter_route: {
    name: 'legal.document',
    path: '/legal/:role/:doc',
    treatment:
      'Compatibility only. With a resolved account context, a role EQUAL to the host account redirects to the canonical role-free path and any other role fails closed without rendering. With no resolved context (the standalone preview origin embeds none) behaviour is unchanged from before UI-06. Recorded as UI06-LEGAL-001.',
  },
});

emit('legal-link-matrix.json', {
  ...provenance,
  purpose:
    'Twenty-four canonical legal routes — three documents on each of the eight account hosts — with the source and hash each renders.',
  route_shape: '/legal/{data-policy|privacy-policy|terms-of-service}',
  account_selected_by: 'the server-resolved account host context, never a path segment',
  rows: ACCOUNTS.flatMap((key) => {
    const { legal } = accounts.get(key);

    return PUBLIC_LEGAL_DOCS.map((doc) => ({
      account_key: key,
      document: doc,
      title: PUBLIC_LEGAL_TITLES[doc],
      route: `/legal/${doc}`,
      source_path: legal[doc].meta.sourcePath,
      source_sha256: legal[doc].meta.sourceSha256,
      source_bytes: legal[doc].meta.sourceBytes,
    }));
  }),
});

emit('faq-route-matrix.json', {
  ...provenance,
  purpose: 'The eight public FAQ routes and the compiled documents they serve.',
  route: '/faq',
  account_selected_by: 'the server-resolved account host context, never a path segment',
  renderer: 'SvFaq (UI-04) over the UI-05 compiled FAQ. No second parser, no second accordion.',
  total_items: [...accounts.values()].reduce((total, entry) => total + entry.faq.items.length, 0),
  accounts: ACCOUNTS.map((key) => {
    const { faq } = accounts.get(key);
    const categories = [...new Set(faq.items.map((item) => item.category).filter((category) => category !== null))];

    return {
      account_key: key,
      route: '/faq',
      source_path: faq.meta.sourcePath,
      source_sha256: faq.meta.sourceSha256,
      item_count: faq.items.length,
      category_count: categories.length,
      all_items_rendered: true,
    };
  }),
});

await server.close();

if (CHECK_ONLY) {
  if (failures.length > 0) {
    console.error(`UI-06 audit artifacts are STALE (${failures.length}):\n`);
    for (const failure of failures) {
      console.error(`  - ${failure}`);
    }
    console.error('\nRegenerate with `node scripts/generate-ui06-artifacts.mjs`.');
    process.exit(1);
  }
  console.log('UI-06 audit artifacts are current.');
} else {
  console.log(`UI-06 audit artifacts written (${written.length}):`);
  for (const path of written) {
    console.log(`  ${path}`);
  }
}
