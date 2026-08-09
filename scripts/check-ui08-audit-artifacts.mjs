/**
 * Phase UI-08 audit-artifact checker.
 *
 * The UI-08 audit artifacts under `docs/frontend/audits/ui-08/` are HANDWRITTEN design and
 * evidence authorities, not generated projections, so a stale or self-contradicting one cannot be
 * caught by regenerating it. This script is their `--check`: it validates each artifact against
 * the authorities that outrank it —
 *
 *   docs/frontend/navigation/servana-user-account-navigation-map.yaml   the 160-page contract
 *   docs/auth/permission-matrix.yaml                                    canonical permission keys
 *   docs/backend/audits/ui-08/cor-ui08-001-contract-matrix.json         the 33 corrective operations
 *
 * It is additive per increment: an artifact that does not exist yet is reported as `pending`
 * rather than failing, so the same command is runnable at every green boundary. Once an artifact
 * exists it is validated in full.
 *
 *   node scripts/check-ui08-audit-artifacts.mjs
 *
 * Exit code 0 = every present artifact is internally coherent and agrees with its authorities.
 */
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import yaml from 'js-yaml';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const AUDIT_DIR = join(ROOT, 'docs/frontend/audits/ui-08');

const problems = [];
const notes = [];
const pending = [];

const readJson = (p) => JSON.parse(readFileSync(p, 'utf8'));
const readYaml = (p) => yaml.load(readFileSync(p, 'utf8'));

// ---------------------------------------------------------------------------------------------
// Authorities
// ---------------------------------------------------------------------------------------------

const nav = readYaml(join(ROOT, 'docs/frontend/navigation/servana-user-account-navigation-map.yaml'));
const navSuperAdmin = nav.pages.filter((p) => p.account_type === 'super_administrator');
const navByScreenKey = new Map(navSuperAdmin.map((p) => [p.screen_key, p]));

/**
 * Canonical permission keys. The matrix nests permission records under grouping keys, so the
 * keys are collected structurally rather than by a regular expression over the file — a regex
 * would also match example keys inside comments and prose.
 */
const permissionKeys = new Set();
(function collect(node) {
  if (Array.isArray(node)) {
    node.forEach(collect);
    return;
  }
  if (node !== null && typeof node === 'object') {
    if (typeof node.key === 'string' && node.key.includes('.')) permissionKeys.add(node.key);
    Object.values(node).forEach(collect);
  }
})(readYaml(join(ROOT, 'docs/auth/permission-matrix.yaml')));

if (permissionKeys.size === 0) {
  problems.push('permission-matrix.yaml yielded no permission keys — the collector is broken, not the data');
}

// ---------------------------------------------------------------------------------------------
// page-readiness-matrix.json — the Increment 1/1A classification
// ---------------------------------------------------------------------------------------------

const readinessPath = join(AUDIT_DIR, 'page-readiness-matrix.json');
if (!existsSync(readinessPath)) {
  problems.push('page-readiness-matrix.json is missing — it is Increment 1 output and must never be deleted');
} else {
  const readiness = readJson(readinessPath);

  if (readiness.pages.length !== 22) {
    problems.push(`page-readiness-matrix: expected 22 entries, found ${readiness.pages.length}`);
  }
  for (const p of readiness.pages) {
    if (!navByScreenKey.has(p.screen_key)) {
      problems.push(`page-readiness-matrix: ${p.screen_key} is not a super_administrator contract page`);
    }
  }
  const t = readiness.ui08_target_totals;
  if (t.implemented !== 17 || t.disabled_by_gate !== 5 || t.planned !== 0 || t.removed_by_authority !== 0) {
    problems.push(
      `page-readiness-matrix: target disposition must be 17/5/0/0, found ${t.implemented}/${t.disabled_by_gate}/${t.planned}/${t.removed_by_authority}`,
    );
  }
  notes.push(`page-readiness-matrix: 22 entries, target ${t.implemented}/${t.disabled_by_gate}/${t.planned}/${t.removed_by_authority}`);
}

// ---------------------------------------------------------------------------------------------
// route-activation-matrix.json — the Increment 7A activation design
// ---------------------------------------------------------------------------------------------

const activationPath = join(AUDIT_DIR, 'route-activation-matrix.json');
if (!existsSync(activationPath)) {
  pending.push('route-activation-matrix.json (Increment 7A)');
} else {
  const m = readJson(activationPath);

  if (m.pages.length !== 22) {
    problems.push(`route-activation-matrix: expected 22 entries, found ${m.pages.length}`);
  }

  const routeNames = new Set();
  const hostPaths = new Set();
  const screenKeys = new Set();
  let implemented = 0;
  let gated = 0;

  for (const p of m.pages) {
    const where = `route-activation-matrix: ${p.screen_key}`;
    const contract = navByScreenKey.get(p.screen_key);

    if (!contract) {
      problems.push(`${where}: not a super_administrator contract screen_key`);
      continue;
    }

    // The activation matrix may never invent a route identity: the contract owns it.
    if (contract.route_path !== p.canonical_route) {
      problems.push(`${where}: canonical_route ${p.canonical_route} != contract route_path ${contract.route_path}`);
    }
    if (contract.route_name !== p.target_route_name) {
      problems.push(`${where}: target_route_name ${p.target_route_name} != contract route_name ${contract.route_name}`);
    }
    if (contract.map_section !== p.map_section) problems.push(`${where}: map_section disagrees with the contract`);
    if (contract.navigation_group !== p.navigation_group) problems.push(`${where}: navigation_group disagrees with the contract`);
    if (contract.navigation_visibility !== p.navigation_visibility) {
      problems.push(`${where}: navigation_visibility disagrees with the contract`);
    }

    if (routeNames.has(p.target_route_name)) problems.push(`${where}: duplicate target_route_name`);
    routeNames.add(p.target_route_name);

    const hostPath = `${p.host}${p.canonical_route}`;
    if (hostPaths.has(hostPath)) problems.push(`${where}: duplicate host+path ${hostPath}`);
    hostPaths.add(hostPath);

    if (screenKeys.has(p.screen_key)) problems.push(`${where}: duplicate screen_key`);
    screenKeys.add(p.screen_key);

    if (!p.canonical_route.startsWith('/')) problems.push(`${where}: canonical_route must start with /`);
    if (p.canonical_route.includes('?')) problems.push(`${where}: canonical_route must not carry a query string`);

    for (const key of [...(p.permissions ?? []), ...(p.conditional_link_permissions ?? [])]) {
      if (!permissionKeys.has(key)) problems.push(`${where}: permission ${key} is not in the canonical matrix`);
    }

    // A parameterised route cannot be a primary navigation destination: the header resolves a
    // route NAME alone, so a required param throws at mount time. The UI-07 generator enforces
    // the same rule; catching it here means the design fails before any route is registered.
    if (p.canonical_route.includes(':') && p.navigation_visibility === 'primary') {
      problems.push(`${where}: parameterised route cannot be primary navigation`);
    }

    if (p.requires_account !== 'super_administrator') problems.push(`${where}: requires_account must be super_administrator`);
    if (p.mfa !== true) problems.push(`${where}: every Super Administrator page requires MFA`);

    if (p.target_status === 'implemented') {
      implemented += 1;
      if (!p.target_component) problems.push(`${where}: implemented target without a component`);
      else if (!p.target_component.startsWith('@/pages/')) problems.push(`${where}: ${p.target_component} is not a page module`);
      if (!Array.isArray(p.activation_prerequisite) || p.activation_prerequisite.length === 0) {
        problems.push(`${where}: implemented target without activation prerequisites`);
      }
      if (!(p.api_operations ?? []).length) problems.push(`${where}: implemented target names no API operation`);
      if (p.gate) problems.push(`${where}: an implemented target must not name a gate`);
    } else if (p.target_status === 'disabled_by_gate') {
      gated += 1;
      if (!p.gate) problems.push(`${where}: disabled_by_gate without a gate`);
      if (!p.gate_statement) problems.push(`${where}: disabled_by_gate without a gate statement`);
      if (p.target_component) problems.push(`${where}: a gated entry must not name a component`);
      if (p.target_delivery) problems.push(`${where}: a gated entry must not name a delivery`);
      if ((p.api_operations ?? []).length) problems.push(`${where}: a gated entry must not name API operations`);
      if (p.activation_prerequisite !== 'NONE — no live route may be registered') {
        problems.push(`${where}: a gated entry must forbid activation explicitly`);
      }
    } else {
      problems.push(`${where}: target_status ${p.target_status} is not permitted by the final disposition`);
    }
  }

  const canonicalPaths = new Set(m.pages.map((p) => p.canonical_route));
  for (const r of m.compatibility_redirects ?? []) {
    const where = `route-activation-matrix: redirect ${r.from}`;
    if (r.from === r.to_path) problems.push(`${where}: self-redirect`);
    if (!canonicalPaths.has(r.to_path)) problems.push(`${where}: target ${r.to_path} is not a canonical route`);
    if (canonicalPaths.has(r.from)) problems.push(`${where}: source shadows a canonical route`);
    if (!(r.proven_consumers ?? []).length) problems.push(`${where}: retained without proven consumer evidence`);
    if (r.same_account !== true) problems.push(`${where}: cross-account redirects are forbidden`);
  }

  if (implemented !== 17) problems.push(`route-activation-matrix: implemented must be 17, found ${implemented}`);
  if (gated !== 5) problems.push(`route-activation-matrix: disabled_by_gate must be 5, found ${gated}`);
  if (m.totals.implemented !== implemented || m.totals.disabled_by_gate !== gated) {
    problems.push('route-activation-matrix: declared totals disagree with the entries');
  }

  notes.push(
    `route-activation-matrix: 22 entries, ${implemented} implemented / ${gated} disabled_by_gate, ` +
      `${routeNames.size} unique route names, ${(m.compatibility_redirects ?? []).length} same-account redirects`,
  );
}

// ---------------------------------------------------------------------------------------------
// gate-disposition.json — the Increment 9F per-entry gate record
// ---------------------------------------------------------------------------------------------

const gatePath = join(AUDIT_DIR, 'gate-disposition.json');
if (!existsSync(gatePath)) {
  pending.push('gate-disposition.json (Increment 9F)');
} else if (!existsSync(readinessPath) || !existsSync(activationPath)) {
  problems.push('gate-disposition.json cannot be validated without the readiness and activation matrices');
} else {
  const g = readJson(gatePath);
  const activation = readJson(activationPath);
  const activationGated = new Map(
    activation.pages.filter((p) => p.target_status === 'disabled_by_gate').map((p) => [p.screen_key, p]),
  );

  if (g.entries.length !== 5) {
    problems.push(`gate-disposition: expected 5 gated entries, found ${g.entries.length}`);
  }

  // The set of gated screens is decided by the activation matrix; this artifact explains them.
  const declared = new Set(g.entries.map((e) => e.screen_key));
  for (const key of activationGated.keys()) {
    if (!declared.has(key)) problems.push(`gate-disposition: ${key} is disabled_by_gate but has no disposition entry`);
  }

  let direct = 0;
  let transitive = 0;

  for (const e of g.entries) {
    const where = `gate-disposition: ${e.screen_key}`;
    const contract = navByScreenKey.get(e.screen_key);
    const activationEntry = activationGated.get(e.screen_key);

    if (!contract) {
      problems.push(`${where}: not a super_administrator contract screen_key`);
      continue;
    }
    if (!activationEntry) {
      problems.push(`${where}: not disabled_by_gate in the activation matrix`);
      continue;
    }

    if (contract.route_path !== e.contract_route) problems.push(`${where}: contract_route disagrees with the contract`);
    if (contract.navigation_group !== e.navigation_group) problems.push(`${where}: navigation_group disagrees with the contract`);
    if (contract.map_section !== e.map_section) problems.push(`${where}: map_section disagrees with the contract`);
    if (activationEntry.gate !== e.gate) problems.push(`${where}: gate disagrees with the activation matrix`);

    // The whole point of the artifact: a specific dependency, an owner, and a way out.
    if (!e.blocked_by) problems.push(`${where}: no blocking dependency named`);
    if (!e.backend_owner_phase) problems.push(`${where}: no backend owner phase`);
    if (!e.entry_condition) problems.push(`${where}: no entry condition`);
    if (!e.why_no_partial_page) problems.push(`${where}: no reason for withholding a partial page`);

    if (e.blocking_kind === 'direct') direct += 1;
    else if (e.blocking_kind === 'transitive') transitive += 1;
    else problems.push(`${where}: blocking_kind ${e.blocking_kind} is neither direct nor transitive`);

    for (const key of e.planned_permission_keys ?? []) {
      if (!permissionKeys.has(key)) problems.push(`${where}: permission ${key} is not in the canonical matrix`);
    }

    // A gated entry must stay inert in every artifact that describes it.
    for (const forbidden of ['component', 'store', 'api_operations', 'runtime_route']) {
      if (e[forbidden] !== undefined) problems.push(`${where}: a gated entry must not declare ${forbidden}`);
    }
  }

  const t = g.totals ?? {};
  if (t.gated !== g.entries.length) problems.push('gate-disposition: declared total disagrees with the entries');
  if (t.direct !== direct || t.transitive !== transitive) {
    problems.push(`gate-disposition: declared direct/transitive ${t.direct}/${t.transitive} != counted ${direct}/${transitive}`);
  }

  notes.push(`gate-disposition: ${g.entries.length} gated entries, ${direct} direct / ${transitive} transitive, 0 routed`);
}

// ---------------------------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------------------------

for (const note of notes) console.log(`  ok   ${note}`);
for (const p of pending) console.log(`  --   pending: ${p}`);

if (problems.length > 0) {
  console.error('\nUI-08 audit artifacts FAILED:');
  for (const p of problems) console.error(`  ${p}`);
  process.exit(1);
}

console.log('\nUI-08 audit artifacts: OK');
