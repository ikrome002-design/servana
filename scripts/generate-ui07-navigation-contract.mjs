#!/usr/bin/env node
// Phase UI-07 — derive every projection of the canonical authenticated navigation contract.
//
// ONE handwritten authority:
//   docs/frontend/navigation/servana-user-account-navigation-map.yaml
//
// Everything below is DERIVED from it and is never hand-edited:
//   resources/spa/src/navigation/navigationRegistry.generated.ts   typed runtime registry
//   docs/frontend/screens/contract/{account}/{screen_key}.md       160 screen specifications
//   docs/frontend/audits/ui-07/*.json                              contract and parity matrices
//
// The runtime route table is loaded through Vite's own SSR transform rather than parsed with a
// regular expression. UI-06 proved that reading a route contract by regex produces an artifact
// that looks right and describes something the code does not do: the parser succeeds, matches
// nothing, and every assertion built on it becomes vacuous.
//
// Usage: node scripts/generate-ui07-navigation-contract.mjs [--check]

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import yaml from 'js-yaml';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');

// `--validate-only [--authority=<path>]` runs the invariants and exits, writing nothing. It
// exists so scripts/ui07-negative-controls.mjs can point the same validator at a DISPOSABLE
// mutated copy of the authority and prove each guard actually fires. A guard nobody has ever
// seen fail is a guard you are only assuming works.
const VALIDATE_ONLY = process.argv.includes('--validate-only');
const AUTHORITY_OVERRIDE = process.argv.find((a) => a.startsWith('--authority='))?.slice('--authority='.length);

const AUTHORITY = AUTHORITY_OVERRIDE ?? 'docs/frontend/navigation/servana-user-account-navigation-map.yaml';
const HUMAN_MAP = 'docs/frontend/navigation/servana-user-account-navigation-maps.md';
const AUDIT_DIR = 'docs/frontend/audits/ui-07';
const SPEC_DIR = 'docs/frontend/screens/contract';
const REGISTRY = 'resources/spa/src/navigation/navigationRegistry.generated.ts';

const failures = [];
const written = [];

const sha256 = (relative) =>
  existsSync(join(ROOT, relative))
    ? createHash('sha256').update(readFileSync(join(ROOT, relative))).digest('hex')
    : null;

function emit(relative, body) {
  const absolute = join(ROOT, relative);
  if (CHECK_ONLY) {
    const current = existsSync(absolute) ? readFileSync(absolute, 'utf8') : null;
    if (current !== body) failures.push(relative);
    return;
  }
  mkdirSync(dirname(absolute), { recursive: true });
  writeFileSync(absolute, body, 'utf8');
  written.push(relative);
}

const emitJson = (name, payload) => emit(`${AUDIT_DIR}/${name}`, `${JSON.stringify(payload, null, 2)}\n`);

// ---------------------------------------------------------------------------------------------
// Load the authorities
// ---------------------------------------------------------------------------------------------

// The authority may be overridden with an ABSOLUTE path by the negative-control harness.
const authorityPath = isAbsolute(AUTHORITY) ? AUTHORITY : join(ROOT, AUTHORITY);
const contract = yaml.load(readFileSync(authorityPath, 'utf8'));
const pages = contract.pages;
const accounts = contract.accounts;
const inventory = JSON.parse(readFileSync(join(ROOT, 'docs/frontend/screens/inventory.json'), 'utf8'));
const permissionMatrix = yaml.load(readFileSync(join(ROOT, 'docs/auth/permission-matrix.yaml'), 'utf8'));
const hostRegistry = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));

const PERMISSION_KEYS = new Set(Object.keys(permissionMatrix.keys));
const ACCOUNT_KEYS = accounts.map((a) => a.account_type);
const byKey = new Map(pages.map((p) => [p.key, p]));

// ---------------------------------------------------------------------------------------------
// The runtime route table, read from the real modules
// ---------------------------------------------------------------------------------------------

const { createServer } = await import(pathToFileURL(join(ROOT, 'node_modules/vite/dist/node/index.js')).href);
const server = await createServer({
  configFile: join(ROOT, 'vite.config.ts'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
});

const ROUTE_MODULES = [
  ['routes/audit.ts', '/src/router/routes/audit.ts', 'auditRoutes'],
  ['routes/auth.ts', '/src/router/routes/auth.ts', 'authRoutes'],
  ['routes/branch.ts', '/src/router/routes/branch.ts', 'branchRoutes'],
  ['routes/finance.ts', '/src/router/routes/finance.ts', 'financeRoutes'],
  ['routes/frontOffice.ts', '/src/router/routes/frontOffice.ts', 'frontOfficeRoutes'],
  ['routes/hr.ts', '/src/router/routes/hr.ts', 'hrRoutes'],
  ['routes/merchant.ts', '/src/router/routes/merchant.ts', 'merchantRoutes'],
  ['routes/personnel.ts', '/src/router/routes/personnel.ts', 'personnelRoutes'],
  ['routes/platform.ts', '/src/router/routes/platform.ts', 'platformRoutes'],
  ['routes/public.ts', '/src/router/routes/public.ts', 'publicRoutes'],
  ['routes/search.ts', '/src/router/routes/search.ts', 'searchRoutes'],
];

const joinPath = (parent, child) => {
  if (child.startsWith('/')) return child;
  if (!child) return parent || '/';
  return `${parent === '/' ? '' : parent}/${child}`;
};

const runtimeRoutes = [];
for (const [file, specifier, exported] of ROUTE_MODULES) {
  const module = await server.ssrLoadModule(specifier);
  const walk = (records, parentPath, accountMeta) => {
    for (const record of records) {
      const path = joinPath(parentPath, record.path ?? '');
      const account = record.meta?.accountKey ?? accountMeta;
      if (typeof record.name === 'string') {
        runtimeRoutes.push({
          name: record.name,
          path,
          declared_in: file,
          account_key: account ?? null,
          lazy_component: typeof record.component === 'function',
          screen_key: record.meta?.screenKey ?? null,
        });
      }
      if (Array.isArray(record.children)) walk(record.children, path, account);
    }
  };
  walk(module[exported], '', null);
}
await server.close();

const runtimeByName = new Map(runtimeRoutes.map((r) => [r.name, r]));

// ---------------------------------------------------------------------------------------------
// Validate the authority before deriving anything from it
// ---------------------------------------------------------------------------------------------

const STATUSES = new Set(['implemented', 'planned', 'disabled_by_gate', 'removed_by_authority']);
const OWNERS = new Set(['UI-08', 'UI-09', 'UI-10', 'UI-11', 'UI-12', 'UI-13', 'UI-14', 'UI-15']);
const problems = [];

const seenKeys = new Set();
const seenRouteNames = new Set();
const seenAccountPaths = new Set();
const seenScreenKeys = new Set();

for (const p of pages) {
  const where = p.key ?? '(missing key)';
  if (seenKeys.has(p.key)) problems.push(`duplicate key: ${where}`);
  seenKeys.add(p.key);
  if (!ACCOUNT_KEYS.includes(p.account_type)) problems.push(`${where}: unknown account_type ${p.account_type}`);
  if (seenRouteNames.has(p.route_name)) problems.push(`${where}: duplicate route_name ${p.route_name}`);
  seenRouteNames.add(p.route_name);
  const accountPath = `${p.account_type}${p.route_path}`;
  if (seenAccountPaths.has(accountPath)) problems.push(`${where}: duplicate account+path ${accountPath}`);
  seenAccountPaths.add(accountPath);
  const scoped = `${p.account_type}::${p.screen_key}`;
  if (seenScreenKeys.has(scoped)) problems.push(`${where}: duplicate screen_key ${scoped}`);
  seenScreenKeys.add(scoped);
  if (!p.route_path.startsWith('/')) problems.push(`${where}: route_path must start with /`);
  if (p.route_path.includes('?')) problems.push(`${where}: route_path must not carry a query string`);
  if (!STATUSES.has(p.implementation_status)) problems.push(`${where}: unknown status ${p.implementation_status}`);
  if (!OWNERS.has(p.owner_phase)) problems.push(`${where}: unknown owner_phase ${p.owner_phase}`);
  for (const key of [...p.permission_any, ...p.permission_all]) {
    if (!PERMISSION_KEYS.has(key)) problems.push(`${where}: permission ${key} is not in the canonical matrix`);
  }
  for (const forbidden of p.forbidden_for) {
    if (!ACCOUNT_KEYS.includes(forbidden)) problems.push(`${where}: forbidden_for names unknown account ${forbidden}`);
    if (forbidden === p.account_type) problems.push(`${where}: forbidden_for names its own account`);
  }
  if (p.implementation_status === 'implemented') {
    if (!p.runtime_route_name) problems.push(`${where}: implemented without runtime_route_name`);
    else if (!runtimeByName.has(p.runtime_route_name)) {
      problems.push(`${where}: runtime_route_name ${p.runtime_route_name} is not a registered route`);
    } else if (!runtimeByName.get(p.runtime_route_name).lazy_component) {
      problems.push(`${where}: runtime route ${p.runtime_route_name} has no lazy component`);
    } else if (p.navigation_visibility === 'primary' && runtimeByName.get(p.runtime_route_name).path.includes(':')) {
      // A primary navigation link is rendered with the route NAME alone. Pointing it at a
      // parameterised route throws "Missing required param" at mount time — a dead link that
      // type-checks. The page is only implemented when a parameter-free route delivers it.
      problems.push(
        `${where}: primary navigation cannot resolve parameterised runtime route ${p.runtime_route_name} (${runtimeByName.get(p.runtime_route_name).path})`,
      );
    }
  } else if (p.runtime_route_name) {
    problems.push(`${where}: ${p.implementation_status} must not name a runtime route`);
  }
  if (p.implementation_status === 'disabled_by_gate' && !p.gate) {
    problems.push(`${where}: disabled_by_gate must name its gate`);
  }
  if (p.navigation_visibility !== 'primary' && !p.non_navigation_reason) {
    problems.push(`${where}: ${p.navigation_visibility} requires a non_navigation_reason`);
  }
}

// Parent graph: same account, resolvable, acyclic.
for (const p of pages) {
  if (p.parent_key === null) continue;
  const parent = byKey.get(p.parent_key);
  if (!parent) {
    problems.push(`${p.key}: parent_key ${p.parent_key} does not exist`);
    continue;
  }
  if (parent.account_type !== p.account_type) problems.push(`${p.key}: cross-account parent ${p.parent_key}`);
  const seen = new Set([p.key]);
  let cursor = parent;
  while (cursor) {
    if (seen.has(cursor.key)) {
      problems.push(`${p.key}: parent cycle through ${cursor.key}`);
      break;
    }
    seen.add(cursor.key);
    cursor = cursor.parent_key ? byKey.get(cursor.parent_key) : null;
  }
}

// Sibling order determinism.
const siblingGroups = new Map();
for (const p of pages) {
  const g = `${p.account_type}::${p.parent_key ?? '(root)'}`;
  if (!siblingGroups.has(g)) siblingGroups.set(g, new Set());
  if (siblingGroups.get(g).has(p.order)) problems.push(`${p.key}: duplicate sibling order ${p.order} in ${g}`);
  siblingGroups.get(g).add(p.order);
}

// Exact counts, derived — never a hard-coded total.
const perAccount = {};
for (const p of pages) perAccount[p.account_type] = (perAccount[p.account_type] ?? 0) + 1;
for (const a of accounts) {
  if (perAccount[a.account_type] !== a.required_pages) {
    problems.push(`${a.account_type}: ${perAccount[a.account_type] ?? 0} pages, contract requires ${a.required_pages}`);
  }
}
const derivedTotal = Object.values(perAccount).reduce((sum, n) => sum + n, 0);
if (derivedTotal !== contract.total_required_pages) {
  problems.push(`total: ${derivedTotal} pages, contract requires ${contract.total_required_pages}`);
}

if (problems.length > 0) {
  console.error('Canonical navigation contract is invalid:');
  for (const p of problems) console.error(`  - ${p}`);
  process.exit(1);
}

if (VALIDATE_ONLY) {
  console.log(`Canonical navigation contract is valid: ${pages.length} pages, ${accounts.length} accounts.`);
  process.exit(0);
}

// ---------------------------------------------------------------------------------------------
// Typed runtime registry
// ---------------------------------------------------------------------------------------------

const ts = (v) => (v === null || v === undefined ? 'null' : JSON.stringify(v));
const tsList = (a) => (a.length === 0 ? '[]' : `[${a.map((x) => JSON.stringify(x)).join(', ')}]`);

const ICONS = [...new Set(pages.map((p) => p.icon))].sort();
const GROUPS = [...new Set(pages.map((p) => p.navigation_group))].sort();

const registryBody = `/**
 * GENERATED FILE — do not edit.
 *
 * Source:      docs/frontend/navigation/servana-user-account-navigation-map.yaml
 * Regenerate:  node scripts/generate-ui07-navigation-contract.mjs
 * Verify:      node scripts/generate-ui07-navigation-contract.mjs --check
 *
 * The complete authenticated page contract of UI/UX plan §7: ${derivedTotal} pages across
 * ${accounts.length} accounts. Navigation is DISCOVERABILITY ONLY — the backend policy,
 * permission middleware and tenant/branch/own scopes remain the security boundary (ADR-017).
 *
 * \`planned\` entries carry a reserved route identity so a later owner phase cannot silently
 * rename the page, but they have no runtime route and are never rendered as a link.
 */
import type { RoleIdentity } from '@/types/roles';

export type NavigationImplementationStatus =
  | 'implemented'
  | 'planned'
  | 'disabled_by_gate'
  | 'removed_by_authority';

export type NavigationVisibility = 'primary' | 'contextual_child' | 'detail_route';

export type NavigationRouteDelivery = 'dedicated' | 'consolidated' | 'cross_account_utility';

export type NavigationIconKey =
${ICONS.map((i) => `  | ${JSON.stringify(i)}`).join('\n')};

export type NavigationGroupKey =
${GROUPS.map((g) => `  | ${JSON.stringify(g)}`).join('\n')};

export interface NavigationContractEntry {
  /** Globally unique, stable contract key. */
  readonly key: string;
  readonly accountType: RoleIdentity;
  readonly screenKey: string;
  readonly label: string;
  readonly description: string;
  readonly navigationGroup: NavigationGroupKey;
  readonly parentKey: string | null;
  readonly order: number;
  readonly icon: NavigationIconKey;
  /** Contract route identity, reserved for every status. */
  readonly routeName: string;
  /** Host-relative contract path. Each account is served on its own host (ADR-016). */
  readonly routePath: string;
  readonly ownerPhase: string;
  readonly backendOwnerPhase: string | null;
  readonly implementationStatus: NavigationImplementationStatus;
  /** The Vue Router record that renders this page today; null unless implemented. */
  readonly runtimeRouteName: string | null;
  readonly routeDelivery: NavigationRouteDelivery | null;
  /** UX visibility only — never authorization. */
  readonly permissionAny: readonly string[];
  readonly permissionAll: readonly string[];
  readonly scope: string;
  readonly requiresMfa: boolean;
  readonly requiresStepUp: boolean;
  readonly featureFlag: string | null;
  readonly billingStateBehavior: string;
  readonly gate: string | null;
  readonly navigationVisibility: NavigationVisibility;
  readonly nonNavigationReason: string | null;
  readonly forbiddenFor: readonly RoleIdentity[];
}

export const NAVIGATION_CONTRACT: readonly NavigationContractEntry[] = [
${pages
  .map(
    (p) => `  {
    key: ${ts(p.key)},
    accountType: ${ts(p.account_type)},
    screenKey: ${ts(p.screen_key)},
    label: ${ts(p.label)},
    description: ${ts(p.description)},
    navigationGroup: ${ts(p.navigation_group)},
    parentKey: ${ts(p.parent_key)},
    order: ${p.order},
    icon: ${ts(p.icon)},
    routeName: ${ts(p.route_name)},
    routePath: ${ts(p.route_path)},
    ownerPhase: ${ts(p.owner_phase)},
    backendOwnerPhase: ${ts(p.backend_owner_phase)},
    implementationStatus: ${ts(p.implementation_status)},
    runtimeRouteName: ${ts(p.runtime_route_name)},
    routeDelivery: ${ts(p.route_delivery)},
    permissionAny: ${tsList(p.permission_any)},
    permissionAll: ${tsList(p.permission_all)},
    scope: ${ts(p.scope)},
    requiresMfa: ${p.requires_mfa},
    requiresStepUp: ${p.requires_step_up},
    featureFlag: ${ts(p.feature_flag)},
    billingStateBehavior: ${ts(p.billing_state_behavior)},
    gate: ${ts(p.gate)},
    navigationVisibility: ${ts(p.navigation_visibility)},
    nonNavigationReason: ${ts(p.non_navigation_reason)},
    forbiddenFor: ${tsList(p.forbidden_for)},
  },`,
  )
  .join('\n')}
] as const;

/** Required page count per account, derived from the contract itself. */
export const REQUIRED_PAGES_PER_ACCOUNT: Readonly<Record<RoleIdentity, number>> = {
${accounts.map((a) => `  ${a.account_type}: ${a.required_pages},`).join('\n')}
} as const;

export const REQUIRED_PAGES_TOTAL = ${derivedTotal};

export function contractForAccount(account: RoleIdentity): readonly NavigationContractEntry[] {
  return NAVIGATION_CONTRACT.filter((entry) => entry.accountType === account);
}
`;

emit(REGISTRY, registryBody);

// ---------------------------------------------------------------------------------------------
// Screen specifications — one per canonical entry, no orphans, no duplicates
// ---------------------------------------------------------------------------------------------

const hostFor = Object.fromEntries(accounts.map((a) => [a.account_type, a.host]));
const placementFor = Object.fromEntries(accounts.map((a) => [a.account_type, a.navigation_placement]));
const inventoryByRoute = new Map(inventory.screens.filter((s) => s.route).map((s) => [s.route, s]));

const bullets = (items) => (items.length === 0 ? '— none' : items.map((i) => `\`${i}\``).join(', '));

const UNPROVEN =
  'Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.';

function specFor(p) {
  const runtime = p.runtime_route_name ? runtimeByName.get(p.runtime_route_name) : null;
  const invRow = p.runtime_route_name ? inventoryByRoute.get(p.runtime_route_name) : null;
  const implemented = p.implementation_status === 'implemented';
  const gated = p.implementation_status === 'disabled_by_gate';
  const parent = p.parent_key ? byKey.get(p.parent_key) : null;

  const statusNote = implemented
    ? `A real runtime route renders this page today: \`${p.runtime_route_name}\` at \`${runtime.path}\` (${runtime.declared_in}), delivery **${p.route_delivery}**.${
        p.route_delivery === 'consolidated'
          ? ` This runtime route also serves other contract pages — the collapse recorded as \`UI01-NAV-001\`. Owner phase **${p.owner_phase}** splits it into a dedicated page.`
          : ''
      }${
        runtime.path !== p.route_path
          ? ` The runtime path uses the account's path prefix rather than the host-relative contract path \`${p.route_path}\`; owner phase **${p.owner_phase}** reconciles path shape (\`UI01-ROUTE-003\`).`
          : ''
      }`
    : gated
      ? `Blocked by **${p.gate}**. The navigation entry is rendered disabled and names the gate; it has no live destination, and no Wallet, Refer & Earn, notification or provider runtime exists behind it. Owner phase **${p.owner_phase}** implements the page once the gate opens.`
      : `No runtime page implementation is active. UI-07 registers the contract identity only: **no Vue Router record and no navigation link is exposed**. Owner phase **${p.owner_phase}** implements it.`;

  const line = (label, value) => `- **${label}:** ${value}`;

  return `# Screen specification — ${p.label}

> GENERATED FILE — do not edit.
> Source: \`${AUTHORITY}\` · Regenerate: \`node scripts/generate-ui07-navigation-contract.mjs\`
>
> ${statusNote}

## Identity

${line('Account', p.account_type)}
${line('Host', `\`${hostFor[p.account_type]}\``)}
${line('Page title', p.label)}
${line('Route', `\`${p.route_path}\` (host-relative contract path)`)}
${line('Route name', `\`${p.route_name}\``)}
${line('Navigation group', `${p.navigation_group}${parent ? ` › ${parent.label}` : ''}`)}
${line('Navigation placement', `${placementFor[p.account_type]} primary navigation`)}
${line('Contract key', `\`${p.key}\``)}
${line('Screen key', `\`${p.screen_key}\``)}
${line('Authoritative map section', `§${p.map_section}`)}

## Purpose

${line('Purpose', p.description)}
${line('User story', `As ${p.account_type.replace(/_/g, ' ')}, I open ${p.label} so that ${p.description.charAt(0).toLowerCase()}${p.description.slice(1)}`)}

## Ownership and status

${line('UI owner phase', `**${p.owner_phase}**`)}
${line('Backend owner phase', p.backend_owner_phase ? `**${p.backend_owner_phase}**` : `${UNPROVEN}`)}
${line('Implementation status', `\`${p.implementation_status}\``)}
${line('Runtime route', p.runtime_route_name ? `\`${p.runtime_route_name}\`` : 'none — no runtime route is registered')}
${line('Route delivery', p.route_delivery ? `\`${p.route_delivery}\`` : 'not applicable')}
${line('External gate', p.gate ? `\`${p.gate}\`` : 'none')}

## Data and behaviour

${line('API dependencies', implemented ? `\`GET /api/v1/me\` bootstrap plus the endpoints already backing \`${p.runtime_route_name}\` (recorded in ${invRow ? `\`docs/frontend/screens/${invRow.spec ?? 'inventory.json'}\`` : '`docs/frontend/screens/inventory.json`'}).` : UNPROVEN)}
${line('Data fields', implemented && invRow ? invRow.summary : UNPROVEN)}
${line('Filters', implemented ? 'As delivered by the runtime screen; preserved across list → detail → back.' : UNPROVEN)}
${line('Sorts', implemented ? 'As delivered by the runtime screen; deterministic and server-authoritative.' : UNPROVEN)}
${line('Pagination', implemented ? 'Every collection paginates (Plan §9 rule 10).' : UNPROVEN)}
${line('Primary action', implemented ? 'As delivered by the runtime screen; one visually dominant primary action per page.' : UNPROVEN)}
${line('Secondary actions', implemented ? 'As delivered by the runtime screen.' : UNPROVEN)}

## Authorization

${line('Authorization', 'Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).')}
${line('Permission-any', bullets(p.permission_any))}
${line('Permission-all', bullets(p.permission_all))}
${line('Tenant scope', p.scope === 'platform' ? 'Platform-only; merchant users are refused without record enumeration.' : 'Merchant-scoped via `BelongsToMerchant`; foreign ULIDs resolve to 404.')}
${line('Branch scope', ['branch'].includes(p.scope) ? 'Branch-scoped via `BelongsToBranch`; the branch is resolved from the server bootstrap, never from the URL.' : 'Not branch-scoped.')}
${line('Own-scope', p.scope === 'own' ? 'Strictly own-scope; served-client data is masked and **contact export does not exist in any format** (Plan §10.2).' : 'Not own-scoped.')}
${line('MFA', p.requires_mfa ? 'Required for this account.' : 'Not required for this account.')}
${line('Step-up', p.requires_step_up ? 'Fresh step-up required for sensitive mutations.' : 'No route-level step-up requirement; individual mutations may still require it server-side.')}
${line('Feature flag', p.feature_flag ? `\`${p.feature_flag}\`` : 'none')}
${line('Forbidden for', bullets(p.forbidden_for))}

## States

${line('Loading state', 'Skeleton via `SvStateBoundary`.')}
${line('Empty state', 'Actionable empty state naming the next step.')}
${line('Error state', 'Retryable error state; the structured error envelope of Plan §11.5.')}
${line('Stale-data state', 'Near-real-time surfaces show the observation time and a manual refresh.')}
${line('Offline state', 'Offline notice; no silent write loss.')}
${line('No-permission state', 'Permissioned controls are hidden via `PermissionGate`; the API remains authoritative.')}
${line('Suspended state', 'Billing suspension and operational suspension follow the §19.2 allowlist.')}
${line('Locked-period state', 'Locked financial periods render read-only and explain why the action is unavailable.')}
${line('Billing-state behaviour', `\`${p.billing_state_behavior}\` — trialing, active, overdue, read_only_grace, suspended_billing, operational suspension and deactivation follow the account allowlist.`)}
${line('Entitlement behaviour', implemented ? 'Entitlement gating is enforced server-side by the owning feature phase.' : UNPROVEN)}

## Presentation

${line('Responsive behaviour', 'Mobile ≤767 / tablet 768–1024 / desktop ≥1025 via CSS media queries only; tables become labelled cards on mobile without horizontal scrolling.')}
${line('Accessibility behaviour', 'Labels, landmarks, visible focus, 44px targets, `aria-current` on the active navigation item, AA contrast in light and dark.')}
${line('Icon', `\`${p.icon}\` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).`)}
${line('Navigation visibility', `\`${p.navigation_visibility}\``)}
${line('Non-navigation reason', p.non_navigation_reason ?? 'not applicable — this page appears in primary navigation.')}

## Evidence

${line('Audit events', implemented ? 'Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.' : UNPROVEN)}
${line('Analytics events', 'No third-party analytics runtime exists in Servana.')}
${line('Tests', implemented ? `Route parity \`Ui07RouteParityTest\`; contract \`Ui07NavigationRegistryContractTest\`; account guard \`Ui07AccountRouteGuardCoverageTest\`; runtime navigation \`navigationFilter.spec.ts\`; browser \`tests/e2e/ui-07-navigation-screen-contracts.spec.ts\`.` : `Contract-level only in UI-07: \`Ui07NavigationRegistryContractTest\`, \`Ui07NoPlannedRouteExposureTest\`. Page-level tests are owned by **${p.owner_phase}**.`)}
${line('Screenshot requirements', implemented ? `Owner phase **${p.owner_phase}** captures this page; UI-07 captures rendered navigation states only.` : `None in UI-07 — there is no page to capture. Owner phase **${p.owner_phase}**.`)}
`;
}

// The generated spec tree is rebuilt wholesale so a renamed screen key cannot leave an orphan.
const specTreeAbsolute = join(ROOT, SPEC_DIR);
if (!CHECK_ONLY && existsSync(specTreeAbsolute)) {
  rmSync(specTreeAbsolute, { recursive: true, force: true });
}
for (const p of pages) emit(`${SPEC_DIR}/${p.account_type}/${p.screen_key}.md`, specFor(p));

if (CHECK_ONLY && existsSync(specTreeAbsolute)) {
  const expected = new Set(pages.map((p) => `${p.account_type}/${p.screen_key}.md`));
  for (const account of readdirSync(specTreeAbsolute)) {
    for (const file of readdirSync(join(specTreeAbsolute, account))) {
      if (!expected.has(`${account}/${file}`)) failures.push(`${SPEC_DIR}/${account}/${file} (orphan)`);
    }
  }
}

// ---------------------------------------------------------------------------------------------
// Audit matrices
// ---------------------------------------------------------------------------------------------

const provenance = {
  schema: 'ui-07.audit.v1',
  phase: 'UI-07',
  generated_by: 'scripts/generate-ui07-navigation-contract.mjs',
  canonical_authority: AUTHORITY,
  canonical_authority_sha256: sha256(AUTHORITY),
  human_map: HUMAN_MAP,
  human_map_sha256: sha256(HUMAN_MAP),
  account_host_registry_sha256: sha256('config/account-hosts.json'),
  permission_matrix_sha256: sha256('docs/auth/permission-matrix.yaml'),
  screen_inventory_sha256: sha256('docs/frontend/screens/inventory.json'),
};

const countBy = (rows, field) => {
  const out = {};
  for (const r of rows) out[r[field]] = (out[r[field]] ?? 0) + 1;
  return Object.fromEntries(Object.entries(out).sort(([a], [b]) => a.localeCompare(b)));
};

emitJson('source-authority.json', {
  ...provenance,
  purpose:
    'The one handwritten authority for the authenticated page contract, and the projections derived from it. Any other machine-readable representation is generated or checked against this file.',
  handwritten_authority: AUTHORITY,
  generated_projections: [REGISTRY, `${SPEC_DIR}/{account}/{screen_key}.md`, `${AUDIT_DIR}/*.json`],
  superseded_representations: {
    'docs/frontend/navigation/role-navigation.yaml':
      'Retained as a generated fixture of the shipped runtime navigation registry. It is a projection of the runtime shell, not of the 160-page contract.',
    'docs/frontend/source-inventory/navigation-map.json':
      'UI-00 source inventory. It registers the required page contract only and explicitly defers owner phase and implementation status to UI-07.',
    'docs/frontend/screens/inventory.json':
      'The runtime screen register: what is BUILT. The canonical contract records what is REQUIRED. UI-01 established that the two registers are never summed together.',
  },
});

emitJson('page-count-matrix.json', {
  ...provenance,
  purpose: 'The exact authenticated page count of UI/UX plan §7.5, derived from the canonical authority.',
  derivation: 'Counted from the canonical YAML entries. The total is summed, never written independently.',
  ...Object.fromEntries(accounts.map((a) => [a.account_type, perAccount[a.account_type]])),
  total: derivedTotal,
  arithmetic: `${accounts.map((a) => perAccount[a.account_type]).join(' + ')} = ${derivedTotal}`,
  excluded_from_count: contract.excluded_from_count,
});

emitJson('account-page-matrix.json', {
  ...provenance,
  purpose: 'Every contract page, per account, in navigation order.',
  accounts: accounts.map((a) => ({
    account_type: a.account_type,
    host: a.host,
    navigation_placement: a.navigation_placement,
    owner_phase: a.owner_phase,
    required_pages: a.required_pages,
    actual_pages: perAccount[a.account_type],
    pages: pages
      .filter((p) => p.account_type === a.account_type)
      .map((p) => ({
        key: p.key,
        order: p.order,
        label: p.label,
        route_name: p.route_name,
        route_path: p.route_path,
        navigation_group: p.navigation_group,
        parent_key: p.parent_key,
        navigation_visibility: p.navigation_visibility,
        implementation_status: p.implementation_status,
        runtime_route_name: p.runtime_route_name,
        permission_all: p.permission_all,
        permission_any: p.permission_any,
        gate: p.gate,
        map_section: p.map_section,
      })),
  })),
});

emitJson('status-matrix.json', {
  ...provenance,
  purpose: 'Implementation status across the contract, and what each status means at runtime.',
  allowed_statuses: [...STATUSES].sort(),
  status_semantics: {
    implemented: 'A real runtime route in this account tree renders a real, non-placeholder component.',
    planned: 'No runtime route and no navigation link. The contract identity is reserved only.',
    disabled_by_gate: 'Rendered disabled, naming the exact gate. No live destination, no partner runtime.',
    removed_by_authority: 'Absent from the router, all navigation surfaces and every live audit set.',
  },
  totals: countBy(pages, 'implementation_status'),
  by_account: Object.fromEntries(
    accounts.map((a) => [a.account_type, countBy(pages.filter((p) => p.account_type === a.account_type), 'implementation_status')]),
  ),
  disabled_by_gate: pages
    .filter((p) => p.implementation_status === 'disabled_by_gate')
    .map((p) => ({ key: p.key, gate: p.gate, owner_phase: p.owner_phase, label: p.label })),
  removed_by_authority: pages.filter((p) => p.implementation_status === 'removed_by_authority').map((p) => p.key),
});

emitJson('owner-phase-matrix.json', {
  ...provenance,
  purpose:
    'One UI owner phase per contract page, and the backend phase that actually delivered the runtime screen. Backend ownership is read from the screen inventory and is NEVER inferred from UI phase numbering.',
  ui_owner_by_account: Object.fromEntries(accounts.map((a) => [a.account_type, a.owner_phase])),
  ui_owner_totals: countBy(pages, 'owner_phase'),
  backend_owner_totals: countBy(
    pages.map((p) => ({ backend: p.backend_owner_phase ?? 'not-yet-delivered' })),
    'backend',
  ),
  rows: pages.map((p) => ({
    key: p.key,
    account_type: p.account_type,
    screen_key: p.screen_key,
    ui_owner_phase: p.owner_phase,
    backend_owner_phase: p.backend_owner_phase,
    implementation_status: p.implementation_status,
    remaining_dependency:
      p.implementation_status === 'implemented'
        ? p.route_delivery === 'consolidated'
          ? 'Split the consolidated runtime screen into a dedicated page (UI01-NAV-001).'
          : 'Reconcile the runtime path to the host-relative contract path (UI01-ROUTE-003).'
        : p.implementation_status === 'disabled_by_gate'
          ? `Blocked by ${p.gate}.`
          : 'Component, read model, authorization, tests and browser proof.',
  })),
});

emitJson('screen-spec-matrix.json', {
  ...provenance,
  purpose: 'One screen specification per contract page: no missing spec, no orphan spec, no spec shared by two pages.',
  spec_directory: SPEC_DIR,
  expected_specs: pages.length,
  rows: pages.map((p) => ({
    key: p.key,
    spec: `${SPEC_DIR}/${p.account_type}/${p.screen_key}.md`,
    implementation_status: p.implementation_status,
    owner_phase: p.owner_phase,
  })),
});

const authenticatedInventory = inventory.screens.filter(
  (s) => !['public', 'legal', 'auth', 'access-state', 'onboarding', 'search'].includes(s.domain),
);
const referencedRuntime = new Set(pages.map((p) => p.runtime_route_name).filter(Boolean));

emitJson('inventory-parity.json', {
  ...provenance,
  purpose:
    'The contract register (what is REQUIRED) against the runtime screen register (what is BUILT). The two are reconciled, never summed.',
  contract_pages: pages.length,
  inventory_rows: inventory.screens.length,
  inventory_authenticated_rows: authenticatedInventory.length,
  inventory_excluded_rows: inventory.screens.length - authenticatedInventory.length,
  excluded_classification: {
    predicate: "inventory row domain in ['public','legal','auth','access-state','onboarding','search']",
    note: 'An explicit predicate, never a path guess. These surfaces are real routes that UI/UX plan §7.5 excludes from the 160.',
    domains: ['public', 'legal', 'auth', 'access-state', 'onboarding', 'search'],
  },
  implemented_contract_pages_with_inventory_row: pages.filter(
    (p) => p.runtime_route_name && inventoryByRoute.has(p.runtime_route_name),
  ).length,
  implemented_contract_pages_without_inventory_row: pages
    .filter((p) => p.runtime_route_name && !inventoryByRoute.has(p.runtime_route_name))
    .map((p) => p.key),
  authenticated_inventory_rows_not_referenced_by_contract: authenticatedInventory
    .filter((s) => !s.route || !referencedRuntime.has(s.route))
    .map((s) => ({ key: s.key, route: s.route, status: s.status, phase: s.phase })),
  inventory_status_totals: countBy(inventory.screens, 'status'),
});

const contractByRuntime = new Map();
for (const p of pages) {
  if (!p.runtime_route_name) continue;
  if (!contractByRuntime.has(p.runtime_route_name)) contractByRuntime.set(p.runtime_route_name, []);
  contractByRuntime.get(p.runtime_route_name).push(p.key);
}

emitJson('route-parity.json', {
  ...provenance,
  purpose:
    'The canonical contract against the ACTUAL Vue Router records, loaded through the real modules rather than parsed from source text.',
  loading_method: "Vite SSR transform of resources/spa/src/router/routes/*.ts — never a regular expression over source.",
  runtime_named_routes: runtimeRoutes.length,
  runtime_routes_with_lazy_component: runtimeRoutes.filter((r) => r.lazy_component).length,
  runtime_routes_without_lazy_component: runtimeRoutes.filter((r) => !r.lazy_component).map((r) => r.name),
  duplicate_runtime_route_names: runtimeRoutes.map((r) => r.name).filter((n, i, a) => a.indexOf(n) !== i),
  /*
   * Duplicate PATHS are counted WITHIN an account, not across the whole repository (Phase UI-08
   * Increment 7B). Each account is served on its own host and `createAppRouter(accountKey)`
   * registers exactly one account tree, so two accounts owning the same canonical path — `/audit`
   * belongs to both the Super Administrator and the Merchant Audit contract — is correct and is
   * precisely why the router became host-scoped. Two records claiming one path INSIDE one account
   * is still a defect, and is what this reports.
   */
  duplicate_runtime_paths: Object.values(
    runtimeRoutes.reduce((acc, r) => {
      const key = `${r.account_key ?? 'shared'} ${r.path}`;
      (acc[key] ??= []).push(r.path);
      return acc;
    }, {}),
  )
    .filter((group) => group.length > 1)
    .map((group) => group[0]),
  /** Recorded, not hidden: the paths two accounts legitimately own on their own hosts. */
  paths_owned_by_more_than_one_account: Object.entries(
    runtimeRoutes.reduce((acc, r) => {
      if (r.account_key === null) return acc;
      (acc[r.path] ??= new Set()).add(r.account_key);
      return acc;
    }, {}),
  )
    .filter(([, accounts]) => accounts.size > 1)
    .map(([path, accounts]) => ({ path, accounts: [...accounts].sort() })),
  contract_route_names_colliding_with_runtime: pages
    .map((p) => p.route_name)
    .filter((n) => runtimeByName.has(n) && !referencedRuntime.has(n)),
  implemented_pages: pages.filter((p) => p.implementation_status === 'implemented').length,
  planned_pages_with_runtime_route: pages
    .filter((p) => p.implementation_status === 'planned' && p.runtime_route_name)
    .map((p) => p.key),
  removed_pages_with_runtime_route: pages
    .filter((p) => p.implementation_status === 'removed_by_authority' && p.runtime_route_name)
    .map((p) => p.key),
  consolidated_runtime_routes: [...contractByRuntime.entries()]
    .filter(([, keys]) => keys.length > 1)
    .map(([route, keys]) => ({ runtime_route_name: route, contract_pages: keys.sort() }))
    .sort((a, b) => a.runtime_route_name.localeCompare(b.runtime_route_name)),
  path_shape_conformance: {
    note: 'The contract path is host-relative; the current router prefixes it with the account path segment (UI01-ROUTE-003). Each owning UI phase reconciles shape when it implements its account.',
    conformant: pages.filter((p) => p.runtime_route_name && runtimeByName.get(p.runtime_route_name).path === p.route_path).length,
    prefixed: pages.filter((p) => p.runtime_route_name && runtimeByName.get(p.runtime_route_name).path !== p.route_path).length,
  },
  rows: pages.map((p) => ({
    key: p.key,
    contract_route_name: p.route_name,
    contract_route_path: p.route_path,
    implementation_status: p.implementation_status,
    runtime_route_name: p.runtime_route_name,
    runtime_route_path: p.runtime_route_name ? runtimeByName.get(p.runtime_route_name).path : null,
    runtime_declared_in: p.runtime_route_name ? runtimeByName.get(p.runtime_route_name).declared_in : null,
    lazy_component: p.runtime_route_name ? runtimeByName.get(p.runtime_route_name).lazy_component : null,
    route_delivery: p.route_delivery,
  })),
});

emitJson('permission-parity.json', {
  ...provenance,
  purpose:
    'Every permission referenced by the contract exists in the canonical matrix. UI-07 creates, activates, retires and re-grants NO permission key.',
  permission_matrix_keys: PERMISSION_KEYS.size,
  contract_permission_references: [...new Set(pages.flatMap((p) => [...p.permission_any, ...p.permission_all]))].sort(),
  unknown_permission_references: [
    ...new Set(
      pages
        .flatMap((p) => [...p.permission_any, ...p.permission_all])
        .filter((k) => !PERMISSION_KEYS.has(k)),
    ),
  ].sort(),
  pages_without_a_proven_permission: pages
    .filter((p) => p.permission_any.length === 0 && p.permission_all.length === 0)
    .map((p) => ({ key: p.key, implementation_status: p.implementation_status, owner_phase: p.owner_phase })),
  boundary:
    'Frontend permission metadata drives discoverability only. Its absence never grants backend access; its presence never authorizes anything (ADR-017, Plan §9 rule 2).',
});

// Account-route-guard coverage, read from the router's own metadata.
const TREE_ROOTS = [
  ['/platform', 'super_administrator', 'routes/platform.ts'],
  ['/merchant', 'merchant_administrator', 'routes/merchant.ts'],
  ['/onboarding/first-time-setup', 'merchant_administrator', 'routes/merchant.ts'],
  ['/branch', 'merchant_branch', 'routes/branch.ts'],
  ['/hr', 'merchant_human_resource', 'routes/hr.ts'],
  ['/finance', 'merchant_finance', 'routes/finance.ts'],
  ['/front-office', 'merchant_front_office', 'routes/frontOffice.ts'],
  ['/personnel', 'merchant_personnel', 'routes/personnel.ts'],
  ['/audit', 'merchant_audit', 'routes/audit.ts'],
];

const UNGUARDED_BY_DESIGN = {
  'staff.accept': 'Invitation acceptance happens before the membership exists; UI/UX plan §7.5 excludes it from the 160.',
  search: 'Cross-account authenticated utility route. GET /api/v1/search grants nothing and returns only what the caller\'s existing per-type authority already allows (D-22-01).',
};

emitJson('requires-account-coverage.json', {
  ...provenance,
  purpose:
    'Every authenticated account route tree declares the account it requires, and the guard is attached. Read from the router records, not from source text.',
  boundary:
    'The route guard is defence in depth and UX only. It does not replace auth:sanctum, tenant/branch middleware, policies, permission middleware or own-scope query constraints. Changing the Host header alone still grants nothing (AccountHostDoesNotAuthorizeTest).',
  pre_existing_guarded_tree: 'super_administrator (/platform, Phase UI-03, closing UI01-ROLE-001)',
  shared_ownership: {
    note:
      'A path prefix is not an account boundary. Five screens are served to two accounts today, so their tree admits both owners and every OTHER child of that tree re-asserts the single owner. Ownership is taken from docs/frontend/screens/inventory.json and Plan §10.2/§13, never from the URL.',
    trees_admitting_two_accounts: [
      {
        root: '/branch',
        accounts: ['merchant_branch', 'merchant_administrator'],
        shared_screens: ['branch.list', 'branch.create', 'branch.detail', 'branch.operating-hours'],
        authority:
          'Plan §13 — "Create branches: Merchant Administrator owns within entitlement; Branch Manager: No". branch.create is the Merchant Administrator\'s alone.',
        branch_only_children_reasserting_single_owner: 10,
      },
      {
        root: '/hr',
        accounts: ['merchant_human_resource', 'merchant_administrator'],
        shared_screens: ['hr.invitations'],
        authority:
          'Plan §13 — "Manage staff invitations/access: Merchant Administrator owns the initial Branch Manager/HR invitations; HR owns operational staff in branch". StaffInvitations.vue offers each account only the roles it may issue.',
        hr_only_children_reasserting_single_owner: 9,
      },
    ],
    guard_semantics:
      'The served host account must be one of the route\'s owners AND the user must hold THAT account — not merely one the route allows. Host and held account still have to agree.',
  },
  newly_guarded_trees_in_ui07: TREE_ROOTS.filter(([, a]) => a !== 'super_administrator').map(([p, a]) => ({ root: p, account_key: a })),
  /*
   * Phase UI-08 Increment 7B: coverage is read from the account each route DECLARES, not from the
   * URL prefix it happens to sit under.
   *
   * The prefix model was already documented here as wrong in principle ("A path prefix is not an
   * account boundary… never from the URL"), and UI-08 made it wrong in fact: the Super
   * Administrator's canonical contract paths — `/dashboard`, `/billing/…`, `/merchants/…`,
   * `/audit`, `/platform-access`, `/account` — share no prefix at all, and `/audit` is a contract
   * path for two different accounts. Grouping by `meta.accountKey` reads the guard-bearing
   * metadata itself, which is strictly stronger than inferring an owner from a URL.
   *
   * `routes_missing_account` keeps its teeth: a route that sits under one of this account's
   * declared roots and declares NO account at all is still an unguarded route, and still fails.
   */
  trees: [...new Set(TREE_ROOTS.map(([, account]) => account))].map((account) => {
    const roots = TREE_ROOTS.filter(([, a]) => a === account);
    const declared = runtimeRoutes.filter((r) => r.account_key === account);
    const underRoots = runtimeRoutes.filter((r) =>
      roots.some(([root]) => r.path === root || r.path.startsWith(`${root}/`)),
    );
    return {
      root: roots.map(([p]) => p).join(' + '),
      account_key: account,
      declared_in: [...new Set(roots.map(([, , file]) => file))].join(', '),
      routes_in_tree: declared.length,
      routes_declaring_account: declared.length,
      routes_outside_the_declared_prefix: declared
        .filter((r) => !roots.some(([root]) => r.path === root || r.path.startsWith(`${root}/`)))
        .map((r) => r.name),
      routes_missing_account: underRoots.filter((r) => r.account_key === null).map((r) => r.name),
    };
  }),
  authenticated_routes_outside_an_account_tree: runtimeRoutes
    .filter((r) => r.account_key === null)
    .filter((r) => !r.path.startsWith('/auth') && !['home', 'public.faq', 'public.legal', 'legal.document'].includes(r.name))
    .map((r) => ({ name: r.name, path: r.path, reason: UNGUARDED_BY_DESIGN[r.name] ?? 'UNEXPLAINED — investigate' })),
});

const navigable = pages.filter((p) => p.navigation_visibility === 'primary');
emitJson('navigation-parity.json', {
  ...provenance,
  purpose:
    'What the runtime navigation may render, derived from the same contract the router is checked against. Navigation is discoverability; it is never authorization.',
  filter_order: [
    'account ownership',
    'removed_by_authority',
    'implementation status (planned is never rendered)',
    'external gate state (disabled_by_gate renders disabled, naming the gate)',
    'feature flag',
    'forbidden account',
    'held permissions (permission_all AND permission_any, fail-closed)',
    'navigation visibility',
    'parent pruning',
    'stable parent/order',
  ],
  navigation_placement: Object.fromEntries(accounts.map((a) => [a.account_type, a.navigation_placement])),
  primary_navigation_entries: navigable.length,
  non_navigation_entries: pages.length - navigable.length,
  non_navigation_reasons: countBy(
    pages.filter((p) => p.navigation_visibility !== 'primary'),
    'navigation_visibility',
  ),
  renderable_by_status: countBy(navigable, 'implementation_status'),
  never_rendered: {
    planned: pages.filter((p) => p.implementation_status === 'planned').length,
    removed_by_authority: pages.filter((p) => p.implementation_status === 'removed_by_authority').length,
  },
  groups_by_account: Object.fromEntries(
    accounts.map((a) => [
      a.account_type,
      [...new Set(pages.filter((p) => p.account_type === a.account_type).map((p) => p.navigation_group))],
    ]),
  ),
  icons_used: ICONS,
});

emitJson('code-splitting-matrix.json', {
  ...provenance,
  purpose: 'Every account route group and page component is lazily loaded. No eager barrel imports 160 pages.',
  rule: 'UI/UX plan §6.5 — do not import 160 pages into the initial bundle.',
  route_groups: ROUTE_MODULES.map(([file]) => file),
  rows: runtimeRoutes.map((r) => ({
    route_name: r.name,
    route_path: r.path,
    declared_in: r.declared_in,
    account_key: r.account_key,
    lazy: r.lazy_component,
    in_initial_bundle: false,
  })),
  eager_page_imports: runtimeRoutes.filter((r) => !r.lazy_component).map((r) => r.name),
  planned_pages_with_runtime_chunk: pages
    .filter((p) => p.implementation_status !== 'implemented' && p.runtime_route_name)
    .map((p) => p.key),
  contract_documentation_is_build_time_only: true,
});

// ---------------------------------------------------------------------------------------------

if (CHECK_ONLY) {
  if (failures.length > 0) {
    console.error('Stale UI-07 generated artifacts:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
  }
  console.log(`UI-07 navigation contract is current: ${pages.length} pages, ${accounts.length} accounts.`);
} else {
  console.log(`UI-07 navigation contract: ${pages.length} pages across ${accounts.length} accounts.`);
  console.log(`Wrote ${written.length} files.`);
}
