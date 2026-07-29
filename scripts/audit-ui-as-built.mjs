#!/usr/bin/env node
// Phase UI-01 — deterministic as-built UI audit collector.
//
// UI-01 is an EVIDENCE phase. This script never repairs, restyles, reroutes or completes the
// frontend; it records what the repository and the browser actually contain, classifies every
// existing page-implementation claim, and reconciles those claims against the binding 160-page
// contract registered by UI-00.
//
// It emits, all sorted so a second run produces no diff:
//
//   docs/frontend/audits/ui-01/served-build-provenance.json      commit/tree/Docker/Vite/browser chain
//   docs/frontend/audits/ui-01/route-component-page-audit.json   routes, components, page-claim classes
//   docs/frontend/audits/ui-01/navigation-role-audit.json        nav registry/YAML/router/browser parity
//   docs/frontend/audits/ui-01/theme-asset-legal-audit.json      theme, brand assets, legal/FAQ mapping
//   docs/frontend/audits/ui-01/baseline-screenshot-manifest.json screenshot matrix + hashes
//   docs/frontend/audits/ui-01/audit-manifest.json               hashes of every artifact above
//
// Volatile host state (git output, Docker image ids, live HTTP probes, browser runs) is NOT
// collected in the default pass — that would make `--check` non-deterministic. It is captured
// once by `--capture` into version-controlled evidence files under docs/proof/ui-01/, and the
// default pass reads those files. Missing evidence is recorded as `not_collected`, never guessed.
//
// Usage:
//   node scripts/audit-ui-as-built.mjs --capture   collect volatile host/browser evidence
//   node scripts/audit-ui-as-built.mjs             regenerate the audit artifacts (deterministic)
//   node scripts/audit-ui-as-built.mjs --check     fail when the artifacts are stale

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');
const CAPTURE = process.argv.includes('--capture');

const SCHEMA_VERSION = 'ui-01.audit.v1';

const AUDIT_DIR = 'docs/frontend/audits/ui-01';
const PROOF_DIR = 'docs/proof/ui-01';
const ENV_CAPTURE = `${PROOF_DIR}/network/environment-capture.json`;
const BROWSER_EVIDENCE = `${PROOF_DIR}/network/browser-evidence.json`;

const NAV_MAP_INVENTORY = 'docs/frontend/source-inventory/navigation-map.json';
const BRAND_INVENTORY = 'docs/frontend/source-inventory/brand-assets.json';
const CONTENT_INVENTORY = 'docs/frontend/source-inventory/role-content.json';
const IMAGE_INVENTORY = 'docs/frontend/source-inventory/landing-images.json';
const SCREEN_INVENTORY = 'docs/frontend/screens/inventory.json';
const ROLE_NAV_YAML = 'docs/frontend/navigation/role-navigation.yaml';

const SPA = 'resources/spa/src';
const ROUTES_DIR = `${SPA}/router/routes`;
const SPA_INDEX_HTML = 'resources/spa/index.html';
const BUILD_DIR = 'public/spa';
const BUILD_MANIFEST = `${BUILD_DIR}/.vite/manifest.json`;

/** Canonical account ordering. Every artifact uses it, so output never depends on directory order. */
const ACCOUNTS = [
  { key: 'super_administrator', account: 'Super Administrator', host: 'citrus.servana.ke', required: 22, routePrefix: '/platform', navPlacement: 'header' },
  { key: 'merchant_administrator', account: 'Merchant Administrator', host: 'servana.ke', required: 23, routePrefix: '/merchant', navPlacement: 'sidebar' },
  { key: 'merchant_branch', account: 'Branch', host: 'branch.servana.ke', required: 18, routePrefix: '/branch', navPlacement: 'sidebar' },
  { key: 'merchant_human_resource', account: 'Human Resource', host: 'hr.servana.ke', required: 19, routePrefix: '/hr', navPlacement: 'sidebar' },
  { key: 'merchant_finance', account: 'Finance', host: 'finance.servana.ke', required: 24, routePrefix: '/finance', navPlacement: 'sidebar' },
  { key: 'merchant_front_office', account: 'Front Office', host: 'office.servana.ke', required: 19, routePrefix: '/front-office', navPlacement: 'sidebar' },
  { key: 'merchant_personnel', account: 'Personnel', host: 'staff.servana.ke', required: 20, routePrefix: '/personnel', navPlacement: 'sidebar' },
  { key: 'merchant_audit', account: 'Audit', host: 'audit.servana.ke', required: 15, routePrefix: '/audit', navPlacement: 'sidebar' },
];

/** Screen-inventory domain → account key. `public`/`legal`/`auth`/`access-state` are cross-account. */
const DOMAIN_TO_ACCOUNT = {
  platform: 'super_administrator',
  merchant: 'merchant_administrator',
  onboarding: 'merchant_administrator',
  branch: 'merchant_branch',
  hr: 'merchant_human_resource',
  finance: 'merchant_finance',
  'front-office': 'merchant_front_office',
  personnel: 'merchant_personnel',
  audit: 'merchant_audit',
};

const CROSS_ACCOUNT_DOMAINS = new Set(['public', 'legal', 'auth', 'access-state', 'search']);

// ---------------------------------------------------------------------------
// small helpers
// ---------------------------------------------------------------------------

const abs = (relPath) => join(ROOT, relPath);
const sha256 = (buffer) => createHash('sha256').update(buffer).digest('hex');
const exists = (relPath) => existsSync(abs(relPath));
const readText = (relPath) => readFileSync(abs(relPath), 'utf8');
const readJson = (relPath) => JSON.parse(readText(relPath));
const readJsonIf = (relPath) => (exists(relPath) ? readJson(relPath) : null);

/** Stable stringify: object keys are emitted in insertion order, arrays pre-sorted by the caller. */
const stringify = (value) => `${JSON.stringify(value, null, 2)}\n`;

const byKey = (key) => (a, b) => String(a[key]).localeCompare(String(b[key]));

function listFiles(relDir, predicate) {
  const out = [];
  const walk = (dir) => {
    if (!existsSync(dir)) return;
    for (const entry of readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const full = join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (!predicate || predicate(entry.name)) out.push(relative(ROOT, full).split('\\').join('/'));
    }
  };
  walk(abs(relDir));
  return out.sort();
}

const pending = [];

function emit(relPath, contents) {
  pending.push({ path: relPath, contents });
}

function flush() {
  let stale = 0;
  for (const file of pending.sort(byKey('path'))) {
    const target = abs(file.path);
    const current = existsSync(target) ? readFileSync(target, 'utf8') : null;
    if (current === file.contents) continue;
    stale += 1;
    if (CHECK_ONLY) {
      console.error(`stale: ${file.path}`);
      continue;
    }
    mkdirSync(dirname(target), { recursive: true });
    writeFileSync(target, file.contents, 'utf8');
    console.log(`wrote: ${file.path}`);
  }
  return stale;
}

// ---------------------------------------------------------------------------
// --capture : volatile host evidence (git, docker, build, HTTP probes)
// ---------------------------------------------------------------------------

function run(command, args) {
  try {
    return { ok: true, out: execFileSync(command, args, { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim() };
  } catch (error) {
    return { ok: false, out: '', error: String(error.message ?? error).slice(0, 400) };
  }
}

/** Probe a URL with the platform-neutral runtime fetch — no curl, no shell quoting, no /dev/null. */
async function probe(url) {
  try {
    const response = await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(15_000) });
    const body = await response.arrayBuffer();
    return {
      url,
      reachable: true,
      status: response.status,
      content_type: response.headers.get('content-type'),
      bytes: body.byteLength,
    };
  } catch (error) {
    return { url, reachable: false, status: null, content_type: null, bytes: null, error: String(error.message ?? error).slice(0, 200) };
  }
}

async function capture() {
  mkdirSync(abs(`${PROOF_DIR}/network`), { recursive: true });
  mkdirSync(abs(`${PROOF_DIR}/screenshots`), { recursive: true });

  const git = (args) => run('git', args).out;
  const docker = (args) => run('docker', args);

  const buildFiles = exists(BUILD_DIR)
    ? listFiles(BUILD_DIR).map((relPath) => ({ path: relPath, bytes: statSync(abs(relPath)).size, sha256: sha256(readFileSync(abs(relPath))) })).sort(byKey('path'))
    : [];

  const containers = [];
  for (const name of ['servana-nginx-1', 'servana-app-1']) {
    const inspect = docker(['inspect', name, '--format', '{{.Id}}|{{.Image}}|{{.Config.Image}}|{{.Created}}|{{.State.Status}}|{{.Config.User}}']);
    if (!inspect.ok) {
      containers.push({ name, present: false, detail: null });
      continue;
    }
    const [id, imageDigest, imageTag, created, status, user] = inspect.out.split('|');
    const mounts = docker(['inspect', name, '--format', '{{json .Mounts}}']);
    containers.push({
      name,
      present: true,
      detail: {
        container_id: id,
        image_digest: imageDigest,
        image_tag: imageTag,
        created,
        status,
        user: user || '(default)',
        mounts: mounts.ok ? JSON.parse(mounts.out).map((m) => ({ type: m.Type, source: m.Source, destination: m.Destination, read_only: m.RW === false })).sort(byKey('destination')) : [],
      },
    });
  }

  // Linux-container filename-case and content checks. The dev bind mount comes from a
  // case-insensitive Windows host, so a case probe there proves nothing; the production image
  // COPYs the tree into a case-sensitive Linux filesystem, which is where case actually bites.
  // The nginx edge image ships only `public/`; the PHP application image ships the whole tree.
  // Probing each for the paths it is actually supposed to contain keeps "absent" meaningful.
  const caseProbes = [];
  const imageTargets = [
    {
      image: process.env['UI01_PROD_IMAGE'] ?? '',
      role: 'nginx-edge',
      targets: [
        'public/assets/brand/Logo.png',
        'public/assets/brand/logo.png',
        'public/assets/brand/Logo.svg',
        'public/assets/brand/favicon.ico',
        'public/assets/brand/favicon-16x16.png',
        'public/assets/brand/favicon-32x32.png',
        'public/assets/brand/apple-touch-icon.png',
        'public/assets/brand/android-chrome-192x192.png',
        'public/assets/brand/android-chrome-512x512.png',
        'public/assets/brand/PNG.png',
        'public/spa/index.html',
        'public/spa/.vite/manifest.json',
      ],
    },
    {
      image: process.env['UI01_PROD_PHP_IMAGE'] ?? '',
      role: 'php-application',
      targets: [
        'docs/landing_page',
        'docs/landing page',
        'docs/legal/data_policy',
        'docs/legal/privacy_policy',
        'docs/legal/terms_of_service',
        'docs/support/faq',
        'public/assets/brand/Logo.png',
        'public/assets/brand/Logo.svg',
      ],
    },
  ];

  for (const { image, role, targets } of imageTargets) {
    if (!image) continue;
    for (const target of targets) {
      const check = docker(['run', '--rm', '--entrypoint', 'sh', image, '-c', `test -e "/var/www/html/${target}" && echo present || echo absent`]);
      caseProbes.push({ path: target, image, image_role: role, result: check.ok ? check.out.trim() : 'probe_failed', error: check.ok ? null : check.error ?? null });
    }
  }

  const origin = process.env['UI01_ORIGIN'] ?? 'http://localhost:8080';
  const probeUrls = new Set([
    `${origin}/`,
    `${origin}/spa/`,
    `${origin}/assets/brand/Logo.png`,
    `${origin}/assets/brand/favicon.ico`,
    `${origin}/assets/brand/favicon-16x16.png`,
    `${origin}/assets/brand/favicon-32x32.png`,
    `${origin}/assets/brand/apple-touch-icon.png`,
    `${origin}/assets/brand/android-chrome-192x192.png`,
    `${origin}/assets/brand/android-chrome-512x512.png`,
    `${origin}/assets/brand/Logo.svg`,
    `${origin}/health`,
  ]);

  // The assets the built index.html actually asks the browser for, probed at the URLs it asks
  // for them at. This is the difference between "the file exists" and "the browser gets it".
  if (exists(`${BUILD_DIR}/index.html`)) {
    const html = readText(`${BUILD_DIR}/index.html`);
    for (const match of html.matchAll(/(?:src|href)="(\/[^"]+)"/g)) probeUrls.add(`${origin}${match[1]}`);
  }

  const probes = await Promise.all([...probeUrls].sort().map(probe));

  // Whether a public hostname resolves on this network says nothing about the product — many
  // resolvers answer every name with a wildcard. The decisive evidence is in the repository:
  // the nginx server block matches `server_name _` (any host) and no Laravel route, middleware
  // or config selects an experience by host. Both facts are recorded rather than inferred.
  const nginxConf = exists('docker/nginx/default.conf') ? readText('docker/nginx/default.conf') : '';
  const serverNames = [...nginxConf.matchAll(/server_name\s+([^;]+);/g)].map((m) => m[1].trim()).sort();
  const hostRoutingReferences = ['config', 'routes', 'app/Http/Middleware']
    .flatMap((dir) => (exists(dir) ? listFiles(dir, (n) => n.endsWith('.php')) : []))
    .filter((file) => /->domain\(|Route::domain|servana\.ke/.test(readText(file)))
    .sort();

  const hostProbes = ACCOUNTS.map((account) => {
    const lookup = run('nslookup', [account.host]);
    return {
      account_key: account.key,
      required_host: account.host,
      configured_in_nginx: serverNames.some((name) => name.split(/\s+/).includes(account.host)),
      referenced_in_backend_routing: hostRoutingReferences.length > 0,
      dns_answer_on_this_network: lookup.ok && !/can't find|NXDOMAIN|server can't/i.test(lookup.out),
      status: 'not_configured',
      note: 'UI-01 created no hosts-file entry, DNS record or server block. A DNS answer on this network is not evidence the product serves this host. UI-02 owns host registration.',
    };
  }).sort(byKey('required_host'));

  const evidence = {
    schema: `${SCHEMA_VERSION}.environment-capture`,
    captured_at_utc: new Date().toISOString(),
    note: 'Volatile host evidence. Regenerated only by --capture; the default audit pass reads it.',
    git: {
      branch: git(['rev-parse', '--abbrev-ref', 'HEAD']),
      head: git(['rev-parse', 'HEAD']),
      head_tree: git(['rev-parse', 'HEAD^{tree}']),
      origin_main: git(['rev-parse', 'origin/main']),
      merge_base_origin_main: git(['merge-base', 'origin/main', 'HEAD']),
      divergence_origin_main_vs_head: git(['rev-list', '--left-right', '--count', 'origin/main...HEAD']),
      dirty_paths: git(['status', '--short', '--untracked-files=all']).split('\n').filter(Boolean).sort(),
      fsck_non_dangling: run('git', ['fsck', '--full']).out.split('\n').filter((line) => line && !line.startsWith('dangling')).sort(),
    },
    toolchain: {
      node: run('node', ['-v']).out,
      npm: run('npm', ['-v']).out,
      docker: run('docker', ['--version']).out,
      docker_compose: run('docker', ['compose', 'version', '--short']).out,
      php_in_container: run('docker', ['exec', 'servana-app-1', 'php', '-r', 'echo PHP_VERSION;']).out,
      composer_in_container: run('docker', ['exec', 'servana-app-1', 'composer', '--version', '--no-ansi']).out,
    },
    build: {
      manifest_path: BUILD_MANIFEST,
      manifest_present: exists(BUILD_MANIFEST),
      manifest_sha256: exists(BUILD_MANIFEST) ? sha256(readFileSync(abs(BUILD_MANIFEST))) : null,
      emitted_file_count: buildFiles.length,
      emitted_files: buildFiles,
    },
    containers: containers.sort(byKey('name')),
    linux_image_case_probes: caseProbes.sort(byKey('path')),
    host_routing_evidence: {
      nginx_server_names: serverNames,
      nginx_matches_any_host: serverNames.includes('_'),
      backend_files_referencing_host_routing: hostRoutingReferences,
    },
    served_origin: origin,
    http_probes: probes.sort(byKey('url')),
    planned_host_probes: hostProbes,
  };

  writeFileSync(abs(ENV_CAPTURE), stringify(evidence), 'utf8');
  console.log(`captured: ${ENV_CAPTURE}`);
}

// ---------------------------------------------------------------------------
// route parsing
// ---------------------------------------------------------------------------

/**
 * Parse the vue-router route records out of the router source. The route files are plain,
 * regular object literals with lazy `component: () => import('…')`, so a brace-balanced scan is
 * exact and needs no bundler. Anything unparseable is reported, never silently dropped.
 */
function parseRouteFile(relPath) {
  const text = readText(relPath);
  const records = [];

  // Walk every `{` that begins a route record (identified by a `path:` at its own nesting depth).
  const scanBlock = (start, parentPath, layout, guards, depthLabel) => {
    let depth = 0;
    let end = start;
    for (let i = start; i < text.length; i += 1) {
      if (text[i] === '{') depth += 1;
      else if (text[i] === '}') {
        depth -= 1;
        if (depth === 0) { end = i; break; }
      }
    }
    const block = text.slice(start, end + 1);
    const childrenIndex = block.indexOf('children:');

    // A record's own fields all precede its `children:` array. Matching against the whole block
    // would let a layout shell inherit its first child's name, component or meta — which reads
    // as a duplicate route rather than the parentless shell it is.
    const head = childrenIndex >= 0 ? block.slice(0, childrenIndex) : block;

    const field = (name) => {
      const match = head.match(new RegExp(`(?:^|[\\s,{])${name}:\\s*'([^']*)'`));
      return match ? match[1] : null;
    };

    const path = field('path');
    if (path === null) return end;
    const name = field('name');

    const componentMatch = head.match(/component:\s*\(\)\s*=>\s*import\('([^']+)'\)/);
    const component = componentMatch ? componentMatch[1] : null;
    const eagerComponent = !componentMatch && /component:\s*[A-Z]/.test(head) ? (head.match(/component:\s*([A-Za-z0-9_]+)/) ?? [])[1] ?? null : null;

    const guardMatch = head.match(/beforeEnter:\s*\[([^\]]*)\]/);
    const localGuards = guardMatch ? guardMatch[1].split(',').map((s) => s.trim().replace(/\(.*$/, '')).filter(Boolean) : [];

    const metaMatch = head.match(/meta:\s*\{([^}]*)\}/);
    const meta = {};
    if (metaMatch) {
      for (const entry of metaMatch[1].matchAll(/([A-Za-z0-9_]+):\s*'([^']*)'/g)) meta[entry[1]] = entry[2];
    }

    const redirect = (head.match(/redirect:\s*\{[^}]*name:\s*'([^']+)'/) ?? head.match(/redirect:\s*'([^']+)'/) ?? [])[1] ?? null;

    const fullPath = path.startsWith('/')
      ? path
      : `${parentPath.replace(/\/$/, '')}/${path}`.replace(/\/$/, '') || parentPath;

    const isLayoutShell = component !== null && component.includes('/layouts/');

    const record = {
      source_file: relPath,
      name,
      path,
      full_path: fullPath || '/',
      parent_path: depthLabel === 0 ? null : parentPath,
      component,
      eager_component: eagerComponent,
      lazy: componentMatch !== null,
      redirect,
      guards: [...guards, ...localGuards].sort(),
      meta,
      is_layout_shell: isLayoutShell,
      layout: isLayoutShell ? component.split('/').pop().replace('.vue', '') : layout,
      has_params: /:/.test(fullPath),
    };
    records.push(record);

    if (childrenIndex >= 0) {
      const childArrayStart = text.indexOf('[', start + childrenIndex);
      let arrayDepth = 0;
      for (let i = childArrayStart; i <= end; i += 1) {
        if (text[i] === '[') arrayDepth += 1;
        else if (text[i] === ']') { arrayDepth -= 1; if (arrayDepth === 0) break; }
        else if (text[i] === '{' && arrayDepth === 1) {
          i = scanBlock(i, record.full_path, record.layout, record.guards, depthLabel + 1);
        }
      }
    }
    return end;
  };

  const arrayStart = text.indexOf('= [');
  if (arrayStart < 0) return records;
  let depth = 0;
  for (let i = text.indexOf('[', arrayStart); i < text.length; i += 1) {
    if (text[i] === '[') depth += 1;
    else if (text[i] === ']') { depth -= 1; if (depth === 0) break; }
    else if (text[i] === '{' && depth === 1) i = scanBlock(i, '', null, [], 0);
  }
  return records;
}

function parseRootRoutes() {
  const text = readText(`${SPA}/router/index.ts`);
  const records = [];
  for (const match of text.matchAll(/\{\s*(?:\/\/[^\n]*\n\s*)*path:\s*'([^']+)',\s*name:\s*'([^']+)',\s*component:\s*\(\)\s*=>\s*import\('([^']+)'\)/g)) {
    records.push({
      source_file: `${SPA}/router/index.ts`,
      name: match[2],
      path: match[1],
      full_path: match[1],
      parent_path: null,
      component: match[3],
      eager_component: null,
      lazy: true,
      redirect: null,
      guards: [],
      meta: {},
      is_layout_shell: false,
      layout: 'Standalone',
      has_params: /:/.test(match[1]),
    });
  }
  return records;
}

// ---------------------------------------------------------------------------
// navigation registry parsing
// ---------------------------------------------------------------------------

function parseRoleNavigation() {
  const text = readText(`${SPA}/navigation/roleNavigation.ts`);
  const constToIdentity = {};
  for (const match of text.matchAll(/^\s{2}([a-z_]+):\s*([A-Za-z]+),$/gm)) constToIdentity[match[2]] = match[1];

  const items = [];
  for (const arrayMatch of text.matchAll(/^const ([A-Za-z]+): NavItem\[\] = \[([\s\S]*?)^\];$/gm)) {
    const identity = constToIdentity[arrayMatch[1]];
    if (!identity) continue;
    for (const itemMatch of arrayMatch[2].matchAll(/\{\s*key:\s*'([^']+)',[\s\S]*?\},?\n/g)) {
      const block = itemMatch[0];
      const field = (name) => (block.match(new RegExp(`${name}:\\s*'([^']*)'`)) ?? [])[1] ?? null;
      items.push({
        account_key: identity,
        key: itemMatch[1],
        label: field('label'),
        route_name: field('routeName'),
        permission: field('permission'),
        phase: field('phase'),
        availability: field('availability'),
      });
    }
  }
  return items.sort((a, b) => a.account_key.localeCompare(b.account_key) || a.key.localeCompare(b.key));
}

function parseRoleNavYaml() {
  const text = readText(ROLE_NAV_YAML);
  const items = [];
  let account = null;
  let current = null;
  for (const rawLine of text.split('\n')) {
    if (/^#/.test(rawLine) || rawLine.trim() === '') continue;
    const accountMatch = rawLine.match(/^([a-z_]+):$/);
    if (accountMatch) { account = accountMatch[1]; continue; }
    const itemMatch = rawLine.match(/^\s*-\s+key:\s*(\S+)$/);
    if (itemMatch) {
      current = { account_key: account, key: itemMatch[1], label: null, route_name: null, permission: null, phase: null, availability: null };
      items.push(current);
      continue;
    }
    const fieldMatch = rawLine.match(/^\s+([a-z_]+):\s*(.+)$/);
    if (fieldMatch && current) {
      const map = { route: 'route_name', label: 'label', permission: 'permission', phase: 'phase', availability: 'availability' };
      const field = map[fieldMatch[1]];
      if (field) current[field] = fieldMatch[2].trim();
    }
  }
  return items.sort((a, b) => a.account_key.localeCompare(b.account_key) || a.key.localeCompare(b.key));
}

// ---------------------------------------------------------------------------
// browser evidence lookup
// ---------------------------------------------------------------------------

function browserIndex(browser) {
  const index = new Map();
  if (!browser) return index;
  for (const visit of browser.route_visits ?? []) index.set(visit.route_name, visit);
  return index;
}

// ---------------------------------------------------------------------------
// artifact builders
// ---------------------------------------------------------------------------

function buildProvenance(env, browser) {
  const buildManifest = exists(BUILD_MANIFEST) ? readJson(BUILD_MANIFEST) : null;
  const entries = buildManifest
    ? Object.entries(buildManifest).map(([key, value]) => ({ source: key, file: value.file, is_entry: value.isEntry === true, is_dynamic_entry: value.isDynamicEntry === true, css: (value.css ?? []).slice().sort() })).sort(byKey('source'))
    : [];

  const indexHtml = exists(`${BUILD_DIR}/index.html`) ? readText(`${BUILD_DIR}/index.html`) : null;
  const referencedUrls = indexHtml ? [...new Set([...indexHtml.matchAll(/(?:src|href)="(\/[^"]+)"/g)].map((m) => m[1]))].sort() : [];

  const probesByUrl = new Map((env?.http_probes ?? []).map((p) => [p.url, p]));
  const origin = env?.served_origin ?? null;

  const referencedResolution = referencedUrls.map((url) => {
    const probed = origin ? probesByUrl.get(`${origin}${url}`) ?? null : null;
    const onDiskAtBuildRoot = url.startsWith('/assets/') && exists(`${BUILD_DIR}${url}`);
    const onDiskAtPublicRoot = exists(`public${url}`);
    return {
      referenced_url: url,
      served_status: probed?.status ?? null,
      served_reachable: probed ? probed.status >= 200 && probed.status < 400 : null,
      exists_under_public_spa: onDiskAtBuildRoot,
      exists_under_public_root: onDiskAtPublicRoot,
      sha256: onDiskAtBuildRoot ? sha256(readFileSync(abs(`${BUILD_DIR}${url}`))) : onDiskAtPublicRoot ? sha256(readFileSync(abs(`public${url}`))) : null,
    };
  }).sort(byKey('referenced_url'));

  return stringify({
    schema: `${SCHEMA_VERSION}.served-build-provenance`,
    phase: 'UI-01',
    generated_by: 'scripts/audit-ui-as-built.mjs',
    boundary: 'Evidence only. UI-01 changed no runtime source, route, asset, policy or migration.',
    environment_capture: env ? { path: ENV_CAPTURE, captured_at_utc: env.captured_at_utc } : { path: ENV_CAPTURE, status: 'not_collected' },
    git: env?.git ?? 'not_collected',
    toolchain: env?.toolchain ?? 'not_collected',
    vite: {
      config_root: 'resources/spa',
      config_base: '/',
      config_out_dir: 'public/spa',
      manifest_path: BUILD_MANIFEST,
      manifest_present: buildManifest !== null,
      manifest_sha256: exists(BUILD_MANIFEST) ? sha256(readFileSync(abs(BUILD_MANIFEST))) : null,
      entry_count: entries.length,
      entrypoints: entries.filter((e) => e.is_entry).map((e) => e.source).sort(),
      emitted_asset_count: exists(`${BUILD_DIR}/assets`) ? listFiles(`${BUILD_DIR}/assets`).length : 0,
      emitted_assets: env?.build?.emitted_files ?? 'not_collected',
    },
    served_origin: origin ?? 'not_collected',
    index_html: {
      source_path: SPA_INDEX_HTML,
      built_path: `${BUILD_DIR}/index.html`,
      built_sha256: indexHtml ? sha256(Buffer.from(indexHtml, 'utf8')) : null,
      referenced_urls: referencedUrls,
      referenced_url_resolution: referencedResolution,
    },
    http_probes: env?.http_probes ?? 'not_collected',
    containers: env?.containers ?? 'not_collected',
    linux_image_case_probes: env?.linux_image_case_probes ?? 'not_collected',
    browser: browser
      ? {
          evidence_path: BROWSER_EVIDENCE,
          captured_at_utc: browser.captured_at_utc,
          base_url: browser.base_url,
          base_url_kind: browser.base_url_kind,
          user_agent: browser.user_agent,
          service_worker_registrations: browser.service_worker_registrations,
          service_worker_controller: browser.service_worker_controller,
          cache_storage_keys: browser.cache_storage_keys,
          loaded_first_party_assets: browser.loaded_first_party_assets ?? [],
          failed_requests: browser.failed_requests ?? [],
          console_errors: browser.console_errors ?? [],
        }
      : { evidence_path: BROWSER_EVIDENCE, status: 'not_collected' },
  });
}

function buildRouteAudit(env, browser) {
  const routeFiles = listFiles(ROUTES_DIR, (name) => name.endsWith('.ts') && !name.endsWith('.spec.ts'));
  const routes = [...parseRootRoutes(), ...routeFiles.flatMap(parseRouteFile)].sort((a, b) => String(a.full_path).localeCompare(String(b.full_path)) || String(a.name).localeCompare(String(b.name)));

  const named = routes.filter((r) => r.name !== null);
  const namesSeen = new Map();
  const pathsSeen = new Map();
  for (const route of named) {
    namesSeen.set(route.name, (namesSeen.get(route.name) ?? 0) + 1);
    pathsSeen.set(route.full_path, (pathsSeen.get(route.full_path) ?? 0) + 1);
  }

  const pageFiles = listFiles(`${SPA}/pages`, (name) => name.endsWith('.vue'));
  const layoutFiles = listFiles(`${SPA}/layouts`, (name) => name.endsWith('.vue'));
  const componentFiles = listFiles(`${SPA}/components`, (name) => name.endsWith('.vue'));
  const allSource = [...listFiles(SPA, (name) => name.endsWith('.ts') || name.endsWith('.vue'))].map((p) => ({ path: p, text: readText(p) }));

  /**
   * Files that import this one. Matching on the bare filename would be wrong: five distinct
   * `DashboardStub.vue` files exist under different directories, and a basename match makes each
   * of them look referenced by the others — hiding genuinely dead code. Only an alias path that
   * identifies this exact file, or a relative import from this file's own directory, counts.
   */
  const importedBy = (relPath) => {
    const aliased = `@/${relPath.replace(`${SPA}/`, '')}`;
    const withoutExtension = aliased.replace(/\.vue$/, '');
    const directory = relPath.slice(0, relPath.lastIndexOf('/'));
    const bare = relPath.split('/').pop();
    return allSource
      .filter((file) => {
        if (file.path === relPath) return false;
        if (file.text.includes(aliased) || file.text.includes(withoutExtension)) return true;
        const sameDirectory = file.path.slice(0, file.path.lastIndexOf('/')) === directory;
        return sameDirectory && (file.text.includes(`./${bare}`) || file.text.includes(`./${bare.replace(/\.vue$/, '')}`));
      })
      .map((file) => file.path)
      .sort();
  };

  const routedComponents = new Set(routes.map((r) => r.component).filter(Boolean).map((c) => c.replace('@/', `${SPA}/`)));

  const components = [...pageFiles, ...layoutFiles].map((relPath) => {
    const text = readText(relPath);
    const lines = text.split('\n').length;
    const referencedBy = importedBy(relPath);
    return {
      path: relPath,
      kind: relPath.includes('/layouts/') ? 'layout' : 'page',
      lines,
      routed: routedComponents.has(relPath),
      referenced_by_count: referencedBy.length,
      referenced_by: referencedBy.slice(0, 8),
      orphaned: !routedComponents.has(relPath) && referencedBy.length === 0,
      is_stub_named: /Stub\.vue$/.test(relPath),
      role_conditional: /roleIdentity|RoleIdentity/.test(text),
      substantive: lines >= 40,
    };
  }).sort(byKey('path'));

  const sharedRouteTargets = new Map();
  for (const route of named) {
    if (!route.component) continue;
    const list = sharedRouteTargets.get(route.component) ?? [];
    list.push(route.name);
    sharedRouteTargets.set(route.component, list);
  }

  // --- required 160-page contract -----------------------------------------
  const navMap = readJson(NAV_MAP_INVENTORY);
  const inventory = readJson(SCREEN_INVENTORY);
  const routeByName = new Map(named.map((r) => [r.name, r]));
  const visits = browserIndex(browser);

  const claims = inventory.screens.map((screen) => {
    const route = routeByName.get(screen.route) ?? null;
    const visit = visits.get(screen.route) ?? null;
    const component = route?.component ? route.component.replace('@/', `${SPA}/`) : null;
    const componentRecord = components.find((c) => c.path === component) ?? null;
    const specPath = `docs/frontend/screens/${screen.spec}`;

    let classification;
    let rationale;

    if (screen.status !== 'implemented') {
      classification = 'not_claimed';
      rationale = `Screen inventory status is "${screen.status}" — no implementation is claimed.`;
    } else if (!route) {
      classification = 'stale';
      rationale = `Inventory claims route "${screen.route}" as implemented, but no such route name is registered in the router.`;
    } else if (!component || !exists(component)) {
      classification = 'false';
      rationale = `Route "${screen.route}" resolves to component "${route.component}", which does not exist on disk.`;
    } else if (componentRecord && componentRecord.is_stub_named) {
      classification = 'false';
      rationale = `Route "${screen.route}" renders ${component}, a named stub component rather than a substantive page.`;
    } else if (visit && visit.result === 'rendered') {
      classification = 'true';
      rationale = `Route registered, component resolves, and the browser rendered it (landmark "${visit.landmark ?? ''}").`;
    } else if (visit && visit.result !== 'rendered') {
      classification = 'unreachable';
      rationale = `Route and component exist, but the browser run recorded result "${visit.result}"${visit.detail ? `: ${visit.detail}` : ''}.`;
    } else {
      classification = 'unreachable';
      rationale = 'Route and component exist, but no browser run reached this route in the UI-01 audit; reachability is unproven.';
    }

    const account = CROSS_ACCOUNT_DOMAINS.has(screen.domain) ? 'cross_account' : DOMAIN_TO_ACCOUNT[screen.domain] ?? 'unmapped';

    return {
      claim_key: screen.key,
      account_key: account,
      domain: screen.domain,
      claimed_route_name: screen.route,
      claimed_status: screen.status,
      claimed_layout: screen.layout,
      claimed_phase: screen.phase,
      spec_path: specPath,
      spec_present: exists(specPath),
      router_registered: route !== null,
      router_full_path: route?.full_path ?? null,
      router_layout: route?.layout ?? null,
      component_path: component,
      component_present: component ? exists(component) : false,
      component_shared_with_routes: component && sharedRouteTargets.get(route?.component ?? '') ? sharedRouteTargets.get(route.component).filter((n) => n !== screen.route).sort() : [],
      browser_result: visit?.result ?? 'not_visited',
      browser_landmark: visit?.landmark ?? null,
      browser_http_status: visit?.http_status ?? null,
      classification,
      rationale,
    };
  }).sort(byKey('claim_key'));

  // Required routes in the navigation map are HOST-relative (`/dashboard` on citrus.servana.ke).
  // The eight hosts do not exist yet, so today's nearest equivalent is the account's path prefix.
  // A `not_claimed` row therefore means "no route is registered at the contract's path" — NOT
  // that the capability is missing: several contract pages are approximated today by a single
  // consolidated screen at a different path. UI-07 owns reconciling path shape to the contract.
  const requiredPages = navMap.pages.map((page) => {
    const account = ACCOUNTS.find((a) => a.key === page.account_key);
    const candidateFull = page.route.startsWith(`${account.routePrefix}/`) || page.route === account.routePrefix
      ? page.route
      : `${account.routePrefix}${page.route}`;
    const matched = named.find((r) => r.full_path === candidateFull) ?? null;
    return {
      section: page.section,
      account_key: page.account_key,
      account: page.account,
      required_host: page.host,
      page: page.page,
      required_route: page.route,
      candidate_current_path: candidateFull,
      registered_owner_phase: page.owner_phase,
      contract_implementation_status: page.implementation_status,
      current_router_match: matched?.name ?? null,
      required_status: matched ? 'claimed_by_route' : 'not_claimed',
    };
  }).sort((a, b) => a.account_key.localeCompare(b.account_key) || a.section.localeCompare(b.section));

  const counts = (list, field) => {
    const out = {};
    for (const row of list) out[row[field]] = (out[row[field]] ?? 0) + 1;
    return Object.fromEntries(Object.entries(out).sort(([a], [b]) => a.localeCompare(b)));
  };

  const claimedRouteNames = new Set(inventory.screens.map((s) => s.route));
  const navItems = parseRoleNavigation();
  const navRouteNames = new Set(navItems.map((n) => n.route_name).filter(Boolean));

  return stringify({
    schema: `${SCHEMA_VERSION}.route-component-page-audit`,
    phase: 'UI-01',
    generated_by: 'scripts/audit-ui-as-built.mjs',
    boundary: 'Classification only. No route, component, inventory entry or spec was edited to make a claim pass.',
    semantics: {
      implementation_claim:
        'A row in docs/frontend/screens/inventory.json. `true` = registered, resolvable, substantive and browser-rendered. `false` = claimed implemented but a required condition fails. `unreachable` = code exists but this audit could not reach it. `stale` = the claim points at code that no longer exists. `not_claimed` = the inventory itself does not claim implementation.',
      required_page:
        'A row in the binding 160-page contract. `claimed_by_route` = a route is registered at the contract path. `not_claimed` = no route is registered at that path; the capability may still exist today at a different path under a consolidated screen. UI-07 owns route-shape reconciliation.',
      counts_are_not_interchangeable:
        'Implementation claims and required pages are separate registers and are never summed together.',
    },
    browser_evidence: browser ? { path: BROWSER_EVIDENCE, base_url: browser.base_url, base_url_kind: browser.base_url_kind, routes_visited: (browser.route_visits ?? []).length } : { status: 'not_collected' },
    totals: {
      router_records_parsed: routes.length,
      named_routes: named.length,
      layout_shell_records: routes.filter((r) => r.is_layout_shell).length,
      parameterised_routes: named.filter((r) => r.has_params).length,
      duplicate_route_names: [...namesSeen].filter(([, n]) => n > 1).map(([name]) => name).sort(),
      duplicate_full_paths: [...pathsSeen].filter(([, n]) => n > 1).map(([path]) => path).sort(),
      page_components: components.filter((c) => c.kind === 'page').length,
      layout_components: components.filter((c) => c.kind === 'layout').length,
      other_components: componentFiles.length,
      orphaned_components: components.filter((c) => c.orphaned).map((c) => c.path).sort(),
      stub_named_components: components.filter((c) => c.is_stub_named).map((c) => c.path).sort(),
      required_pages: requiredPages.length,
      implementation_claims_inspected: claims.length,
      claims_by_classification: counts(claims, 'classification'),
      claims_by_account: counts(claims, 'account_key'),
      required_pages_by_status: counts(requiredPages, 'required_status'),
      routes_without_screen_claim: named.filter((r) => !claimedRouteNames.has(r.name)).map((r) => r.name).sort(),
      screen_claims_without_route: inventory.screens.filter((s) => !routeByName.has(s.route)).map((s) => s.key).sort(),
      navigation_entries_without_route: navItems.filter((n) => n.route_name && !routeByName.has(n.route_name)).map((n) => `${n.account_key}:${n.key}`).sort(),
      routes_without_navigation: named.filter((r) => !navRouteNames.has(r.name) && !r.has_params).map((r) => r.name).sort(),
    },
    per_account: ACCOUNTS.map((account) => {
      const accountClaims = claims.filter((c) => c.account_key === account.key);
      return {
        account_key: account.key,
        account: account.account,
        required_pages: account.required,
        required_pages_claimed_by_route: requiredPages.filter((p) => p.account_key === account.key && p.required_status === 'claimed_by_route').length,
        required_pages_not_claimed: requiredPages.filter((p) => p.account_key === account.key && p.required_status === 'not_claimed').length,
        implementation_claims: accountClaims.length,
        by_classification: counts(accountClaims, 'classification'),
      };
    }),
    routes,
    components,
    implementation_claims: claims,
    required_pages: requiredPages,
  });
}

function buildNavigationAudit(env, browser) {
  const registry = parseRoleNavigation();
  const fixture = parseRoleNavYaml();
  const routeFiles = listFiles(ROUTES_DIR, (name) => name.endsWith('.ts') && !name.endsWith('.spec.ts'));
  const routes = [...parseRootRoutes(), ...routeFiles.flatMap(parseRouteFile)].filter((r) => r.name);
  const routeByName = new Map(routes.map((r) => [r.name, r]));

  const fixtureByKey = new Map(fixture.map((f) => [`${f.account_key}:${f.key}`, f]));
  const drift = [];
  for (const item of registry) {
    const mirror = fixtureByKey.get(`${item.account_key}:${item.key}`);
    if (!mirror) { drift.push({ key: `${item.account_key}:${item.key}`, kind: 'missing_from_yaml_fixture' }); continue; }
    for (const field of ['label', 'route_name', 'permission', 'phase', 'availability']) {
      if ((item[field] ?? null) !== (mirror[field] ?? null)) {
        drift.push({ key: `${item.account_key}:${item.key}`, kind: 'field_drift', field, registry_value: item[field] ?? null, fixture_value: mirror[field] ?? null });
      }
    }
  }
  for (const item of fixture) {
    if (!registry.some((r) => `${r.account_key}:${r.key}` === `${item.account_key}:${item.key}`)) {
      drift.push({ key: `${item.account_key}:${item.key}`, kind: 'missing_from_registry' });
    }
  }

  const navVisits = new Map((browser?.navigation_observations ?? []).map((n) => [n.account_key, n]));

  const items = registry.map((item) => {
    const route = item.route_name ? routeByName.get(item.route_name) ?? null : null;
    return {
      account_key: item.account_key,
      key: item.key,
      label: item.label,
      availability: item.availability,
      route_name: item.route_name,
      permission: item.permission,
      owning_phase: item.phase,
      route_registered: item.route_name ? route !== null : null,
      route_full_path: route?.full_path ?? null,
      route_is_parameterised: route?.has_params ?? null,
      dead_link: item.availability === 'live' && item.route_name !== null && route === null,
      shares_route_with: item.route_name
        ? registry.filter((o) => o.route_name === item.route_name && o.key !== item.key).map((o) => `${o.account_key}:${o.key}`).sort()
        : [],
    };
  }).sort((a, b) => a.account_key.localeCompare(b.account_key) || a.key.localeCompare(b.key));

  const roleEntry = readText(`${SPA}/types/roles.ts`);
  const layoutForAccount = {};
  for (const match of roleEntry.matchAll(/identity:\s*'([a-z_]+)',[\s\S]*?layout:\s*'([A-Za-z]+)',\s*navPlacement:\s*'([a-z]+)',\s*landingRouteName:\s*'([^']+)',\s*getStartedRouteName:\s*'([^']+)'/g)) {
    layoutForAccount[match[1]] = { layout: match[2], nav_placement: match[3], landing_route: match[4], get_started_route: match[5] };
  }

  return stringify({
    schema: `${SCHEMA_VERSION}.navigation-role-audit`,
    phase: 'UI-01',
    generated_by: 'scripts/audit-ui-as-built.mjs',
    boundary: 'Navigation placement, ordering, labels and entries were audited as they exist. UI-01 corrected none of them.',
    browser_evidence: browser ? { path: BROWSER_EVIDENCE, accounts_observed: (browser.navigation_observations ?? []).length } : { status: 'not_collected' },
    totals: {
      registry_items: registry.length,
      yaml_fixture_items: fixture.length,
      registry_vs_fixture_drift: drift.length,
      live_items: items.filter((i) => i.availability === 'live').length,
      planned_items: items.filter((i) => i.availability === 'planned').length,
      dead_links: items.filter((i) => i.dead_link).map((i) => `${i.account_key}:${i.key}`).sort(),
      duplicate_route_targets: [...new Set(items.filter((i) => i.shares_route_with.length > 0).map((i) => i.route_name))].filter(Boolean).sort(),
    },
    registry_vs_fixture_drift: drift.sort(byKey('key')),
    per_account: ACCOUNTS.map((account) => {
      const accountItems = items.filter((i) => i.account_key === account.key);
      const entry = layoutForAccount[account.key] ?? null;
      const observed = navVisits.get(account.key) ?? null;
      return {
        account_key: account.key,
        account: account.account,
        required_host: account.host,
        required_nav_placement: account.navPlacement,
        source_nav_placement: entry?.nav_placement ?? null,
        nav_placement_matches_contract: entry ? entry.nav_placement === account.navPlacement : null,
        layout_component: entry?.layout ?? null,
        layout_is_shared: entry ? Object.values(layoutForAccount).filter((l) => l.layout === entry.layout).length > 1 : null,
        landing_route: entry?.landing_route ?? null,
        get_started_route: entry?.get_started_route ?? null,
        navigation_items: accountItems.length,
        live_items: accountItems.filter((i) => i.availability === 'live').length,
        planned_items: accountItems.filter((i) => i.availability === 'planned').length,
        required_pages: account.required,
        browser_observed: observed
          ? {
              result: observed.result,
              rendered_placement: observed.rendered_placement ?? null,
              visible_item_count: observed.visible_item_count ?? null,
              detail: observed.detail ?? null,
            }
          : { result: 'not_collected' },
      };
    }),
    planned_host_probes: env?.planned_host_probes ?? 'not_collected',
    host_architecture_note:
      'The eight production hosts are not implemented. The application currently serves one origin and derives the account experience from the authenticated session, not the host. Host registration, Nginx server blocks and URL generation are owned by UI-02.',
    navigation_items: items,
  });
}

function buildThemeAssetLegalAudit(env, browser) {
  const brand = readJson(BRAND_INVENTORY);
  const content = readJson(CONTENT_INVENTORY);
  const images = readJson(IMAGE_INVENTORY);

  const indexSource = readText(SPA_INDEX_HTML);
  const usesPrefersColorScheme = /prefers-color-scheme/.test(indexSource);
  const prefersSelectsDark = /!stored\s*&&\s*prefersDark/.test(indexSource);

  const probesByUrl = new Map((env?.http_probes ?? []).map((p) => [p.url, p]));
  const origin = env?.served_origin ?? null;
  const assetProbe = (url) => (origin ? probesByUrl.get(`${origin}${url}`) ?? null : null);

  const brandAssets = (brand.assets ?? []).map((asset) => {
    const url = `/${asset.path.replace(/^public\//, '')}`;
    const probed = assetProbe(url);
    const referencedIn = listFiles(SPA, (n) => n.endsWith('.vue') || n.endsWith('.ts'))
      .concat([SPA_INDEX_HTML])
      .filter((f) => readText(f).includes(url))
      .sort();
    return {
      path: asset.path,
      approval: asset.approval ?? null,
      present: asset.present ?? exists(asset.path),
      sha256: asset.sha256 ?? null,
      served_url: url,
      served_status: probed?.status ?? null,
      served_content_type: probed?.content_type ?? null,
      referenced_in_source: referencedIn,
      referenced: referencedIn.length > 0,
    };
  }).sort(byKey('path'));

  const unapprovedBrandFiles = listFiles('public/assets/brand')
    .filter((f) => !brandAssets.some((a) => a.path === f))
    .map((f) => ({ path: f, status: 'present_unapproved', note: 'Present under the brand directory but not part of the UI-00 approved brand-asset inventory.' }))
    .sort(byKey('path'));

  const legalRenderer = `${SPA}/pages/legal/LegalDocument.vue`;
  const legalLoader = `${SPA}/content/legalContent.ts`;
  // The renderer delegates to a lazy loader, so verbatim-source provenance must be checked on
  // the loader, not on the component that calls it.
  const legalLoaderText = exists(legalLoader) ? readText(legalLoader) : '';

  const contentRows = (content.documents ?? []).map((doc) => ({
    account_key: doc.role_key ?? doc.role ?? null,
    category: doc.category,
    source_path: doc.path,
    source_present: doc.present ?? exists(doc.path),
    source_sha256: doc.sha256 ?? null,
    bytes: doc.bytes ?? null,
    reachable_route: doc.category === 'landing_page' ? 'no_public_landing_route_exists' : doc.category === 'faq' ? 'no_faq_route_exists' : 'legal.document',
    render_owner_phase: doc.category === 'landing_page' ? 'UI-06' : doc.category === 'faq' ? 'UI-06' : 'UI-05',
  })).sort((a, b) => String(a.account_key).localeCompare(String(b.account_key)) || String(a.category).localeCompare(String(b.category)));

  const themeObs = browser?.theme_observations ?? null;

  return stringify({
    schema: `${SCHEMA_VERSION}.theme-asset-legal-audit`,
    phase: 'UI-01',
    generated_by: 'scripts/audit-ui-as-built.mjs',
    boundary: 'Theme code, brand assets and legal source text were read only. UI-01 edited, replaced, cropped, restored or reformatted none of them.',
    theme: {
      bootstrap_source: SPA_INDEX_HTML,
      bootstrap_runs_before_hydration: /<script>[\s\S]*documentElement\.classList[\s\S]*<\/script>/.test(indexSource),
      storage_key: (indexSource.match(/localStorage\.getItem\('([^']+)'\)/) ?? [])[1] ?? null,
      uses_prefers_color_scheme: usesPrefersColorScheme,
      prefers_color_scheme_selects_dark: prefersSelectsDark,
      contract: 'ADR-021 — light mode is the default; prefers-color-scheme must not select the theme.',
      contract_satisfied: !prefersSelectsDark,
      browser_observations: themeObs ?? 'not_collected',
    },
    brand_assets: {
      approved_inventory: brandAssets,
      unapproved_files_present: unapprovedBrandFiles,
      logo_svg_present: exists('public/assets/brand/Logo.svg'),
      logo_svg_referenced_in_source: listFiles(SPA, (n) => n.endsWith('.vue') || n.endsWith('.ts')).filter((f) => readText(f).includes('Logo.svg')).sort(),
      totals: {
        inventoried: brandAssets.length,
        by_approval: Object.fromEntries(
          [...new Set(brandAssets.map((a) => a.approval))].sort().map((approval) => [approval, brandAssets.filter((a) => a.approval === approval).length]),
        ),
        referenced_in_source: brandAssets.filter((a) => a.referenced).length,
        unreferenced_in_source: brandAssets.filter((a) => !a.referenced).map((a) => a.path).sort(),
        approved_but_unreferenced: brandAssets.filter((a) => a.approval === 'approved' && !a.referenced).map((a) => a.path).sort(),
        // Files the product owner has not approved that nonetheless ship inside the public web root.
        unapproved_but_publicly_served: brandAssets
          .filter((a) => a.approval === 'present_unreferenced' && a.present)
          .map((a) => a.path)
          .sort(),
        served_ok: brandAssets.filter((a) => a.served_status !== null && a.served_status >= 200 && a.served_status < 400).length,
        outside_inventory: unapprovedBrandFiles.length,
      },
    },
    landing_images: {
      total: images.total_images ?? (images.images ?? []).length,
      per_account: ACCOUNTS.map((account) => ({
        account_key: account.key,
        supplied: (images.images ?? []).filter((i) => (i.role_key ?? i.role) === account.key).length,
        referenced_in_source: listFiles(SPA, (n) => n.endsWith('.vue')).filter((f) => readText(f).includes(`landing_page_images/${account.key}`)).length,
      })),
      selection_owner_phase: 'UI-05 compiles the image manifest; UI-06 selects the two-to-four images each landing page uses.',
    },
    role_content: {
      renderer: {
        path: legalRenderer,
        present: exists(legalRenderer),
        loader: legalLoader,
        loads_verbatim_from_docs: /\?raw/.test(legalLoaderText) && /docs\/legal/.test(legalLoaderText),
        lazy: /import\.meta\.glob/.test(legalLoaderText),
        route_name: 'legal.document',
        renders_faq: false,
        faq_note: 'No FAQ route or renderer exists. `/legal/{role}/faq` matches the legal.document route, fails to resolve a document and renders a landmark-less error state rather than a 404. UI-06 owns FAQ surfaces.',
      },
      documents: contentRows,
      totals: {
        documents: contentRows.length,
        present: contentRows.filter((d) => d.source_present).length,
        by_category: Object.fromEntries(
          [...new Set(contentRows.map((d) => d.category))].sort().map((category) => [category, contentRows.filter((d) => d.category === category).length]),
        ),
      },
      legal_render_observations: browser?.legal_observations ?? 'not_collected',
    },
    browser_errors: browser
      ? { console_errors: browser.console_errors ?? [], unhandled_rejections: browser.unhandled_rejections ?? [], failed_requests: browser.failed_requests ?? [] }
      : 'not_collected',
  });
}

function buildScreenshotManifest(env, browser) {
  const shots = (browser?.screenshots ?? []).map((shot) => ({
    account_key: shot.account_key,
    host_or_origin: shot.origin,
    route: shot.route,
    surface: shot.surface,
    auth_state: shot.auth_state,
    viewport_width: shot.viewport_width,
    viewport_height: shot.viewport_height,
    device_scale_factor: shot.device_scale_factor,
    theme_requested: shot.theme_requested,
    theme_rendered: shot.theme_rendered ?? null,
    source_commit: browser.source_commit ?? env?.git?.head ?? null,
    git_tree: browser.git_tree ?? env?.git?.head_tree ?? null,
    provenance: shot.provenance ?? browser.base_url_kind ?? null,
    vite_manifest_sha256: exists(BUILD_MANIFEST) ? sha256(readFileSync(abs(BUILD_MANIFEST))) : null,
    path: shot.path,
    sha256: shot.path && exists(shot.path) ? sha256(readFileSync(abs(shot.path))) : null,
    captured_at_utc: shot.captured_at_utc ?? null,
    result: shot.result,
    related_defect_ids: (shot.related_defect_ids ?? []).slice().sort(),
  })).sort((a, b) => String(a.account_key).localeCompare(String(b.account_key)) || String(a.route).localeCompare(String(b.route)) || a.viewport_width - b.viewport_width || String(a.theme_requested).localeCompare(String(b.theme_requested)));

  const counts = (field) => {
    const out = {};
    for (const shot of shots) out[shot[field]] = (out[shot[field]] ?? 0) + 1;
    return Object.fromEntries(Object.entries(out).sort(([a], [b]) => String(a).localeCompare(String(b))));
  };

  return stringify({
    schema: `${SCHEMA_VERSION}.baseline-screenshot-manifest`,
    phase: 'UI-01',
    generated_by: 'scripts/audit-ui-as-built.mjs',
    purpose:
      'As-built defect baseline. These are NOT approved release visual-regression baselines — UI-16 owns reviewed baselines. They record what the application renders today so later phases can prove improvement.',
    naming_convention: '{account}--{surface}--{route-slug}--{width}x{height}--{theme}--{commit7}.png',
    screenshot_directory: `${PROOF_DIR}/screenshots`,
    totals: {
      entries: shots.length,
      by_result: counts('result'),
      by_account: counts('account_key'),
      by_viewport_width: counts('viewport_width'),
      by_theme_requested: counts('theme_requested'),
      captured: shots.filter((s) => s.result === 'captured').length,
      unreachable: shots.filter((s) => s.result === 'unreachable').length,
      not_configured: shots.filter((s) => s.result === 'not_configured').length,
      failed: shots.filter((s) => s.result === 'failed').length,
    },
    screenshots: shots,
  });
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------

if (CAPTURE) {
  await capture();
  process.exit(0);
}

const env = readJsonIf(ENV_CAPTURE);
const browser = readJsonIf(BROWSER_EVIDENCE);

const artifacts = [
  { path: `${AUDIT_DIR}/served-build-provenance.json`, contents: buildProvenance(env, browser) },
  { path: `${AUDIT_DIR}/route-component-page-audit.json`, contents: buildRouteAudit(env, browser) },
  { path: `${AUDIT_DIR}/navigation-role-audit.json`, contents: buildNavigationAudit(env, browser) },
  { path: `${AUDIT_DIR}/theme-asset-legal-audit.json`, contents: buildThemeAssetLegalAudit(env, browser) },
  { path: `${AUDIT_DIR}/baseline-screenshot-manifest.json`, contents: buildScreenshotManifest(env, browser) },
];

for (const artifact of artifacts) emit(artifact.path, artifact.contents);

const defectRegister = `${AUDIT_DIR}/defect-register.csv`;

emit(
  `${AUDIT_DIR}/audit-manifest.json`,
  stringify({
    schema: `${SCHEMA_VERSION}.audit-manifest`,
    phase: 'UI-01',
    phase_title: 'As-built browser and repository audit',
    generated_by: 'scripts/audit-ui-as-built.mjs',
    boundary: 'Audit and classification only. No corrective runtime product code is part of this phase.',
    determinism: 'Every artifact is sorted; a second generation pass produces no diff. Volatile host state lives in the committed capture files below.',
    evidence_inputs: [
      { path: ENV_CAPTURE, present: env !== null, captured_at_utc: env?.captured_at_utc ?? null },
      { path: BROWSER_EVIDENCE, present: browser !== null, captured_at_utc: browser?.captured_at_utc ?? null },
    ].sort(byKey('path')),
    source_inputs: [BRAND_INVENTORY, CONTENT_INVENTORY, IMAGE_INVENTORY, NAV_MAP_INVENTORY, ROLE_NAV_YAML, SCREEN_INVENTORY, SPA_INDEX_HTML].sort().map((path) => ({
      path,
      present: exists(path),
      sha256: exists(path) ? sha256(readFileSync(abs(path))) : null,
    })),
    artifacts: artifacts
      .map((artifact) => ({ path: artifact.path, sha256: sha256(Buffer.from(artifact.contents, 'utf8')), bytes: Buffer.byteLength(artifact.contents, 'utf8') }))
      .concat(exists(defectRegister) ? [{ path: defectRegister, sha256: sha256(readFileSync(abs(defectRegister))), bytes: statSync(abs(defectRegister)).size }] : [])
      .sort(byKey('path')),
  }),
);

const stale = flush();

if (CHECK_ONLY && stale > 0) {
  console.error(`\n${stale} audit artifact(s) are stale. Run: node scripts/audit-ui-as-built.mjs`);
  process.exit(1);
}

console.log(CHECK_ONLY ? 'UI-01 audit artifacts are current.' : `UI-01 audit artifacts generated (${artifacts.length + 1} files).`);
