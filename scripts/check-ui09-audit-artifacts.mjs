/** Phase UI-09 evidence consistency checker. */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import yaml from 'js-yaml';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const audit = join(root, 'docs/frontend/audits/ui-09');
const required = [
  'implementation-checklist.md', 'page-readiness-matrix.json', 'page-contract-matrix.json',
  'route-parity.json', 'status-disposition.json', 'permission-api-matrix.json',
  'authority-boundary.json', 'responsive-matrix.json', 'accessibility-matrix.json',
  'theme-matrix.json', 'browser-proof.json', 'production-host-proof.json',
  'defect-closure.json', 'screenshot-index.json',
];
const problems = [];
const json = (name) => JSON.parse(readFileSync(join(audit, name), 'utf8'));

for (const name of required) {
  if (!existsSync(join(audit, name))) problems.push(`missing required artifact: ${name}`);
}

const nav = yaml.load(readFileSync(join(root, 'docs/frontend/navigation/servana-user-account-navigation-map.yaml'), 'utf8'));
const merchant = nav.pages.filter((page) => page.account_type === 'merchant_administrator');
const byKey = new Map(merchant.map((page) => [page.screen_key, page]));
if (merchant.length !== 23) problems.push(`canonical merchant count must be 23, found ${merchant.length}`);

if (existsSync(join(audit, 'page-contract-matrix.json'))) {
  const contract = json('page-contract-matrix.json');
  if (contract.pages.length !== 23) problems.push(`page-contract matrix must contain 23 pages, found ${contract.pages.length}`);
  for (const page of contract.pages) {
    const expected = byKey.get(page.screen_key);
    if (!expected) {
      problems.push(`page-contract contains unknown screen ${page.screen_key}`);
      continue;
    }
    for (const [actualKey, expectedKey] of [
      ['route_name', 'route_name'], ['route_path', 'route_path'], ['status', 'implementation_status'],
      ['group', 'navigation_group'], ['visibility', 'navigation_visibility'],
    ]) {
      if (page[actualKey] !== expected[expectedKey]) problems.push(`${page.screen_key}: ${actualKey} disagrees with canonical navigation`);
    }
    if (page.status === 'implemented' && !page.component) problems.push(`${page.screen_key}: implemented without component evidence`);
    if (page.status === 'disabled_by_gate' && page.component !== null) problems.push(`${page.screen_key}: gated page names a component`);
  }
}

if (existsSync(join(audit, 'status-disposition.json'))) {
  const status = json('status-disposition.json');
  const counted = merchant.reduce((totals, page) => {
    totals[page.implementation_status] = (totals[page.implementation_status] ?? 0) + 1;
    return totals;
  }, {});
  const expected = { implemented: 15, disabled_by_gate: 8, planned: 0, removed_by_authority: 0, total: 23 };
  for (const [key, value] of Object.entries(expected)) {
    if (status.totals[key] !== value) problems.push(`status total ${key} must be ${value}, found ${status.totals[key]}`);
  }
  if (counted.implemented !== 15 || counted.disabled_by_gate !== 8 || (counted.planned ?? 0) !== 0) {
    problems.push('canonical navigation is not at final 15 implemented / 8 gated / 0 planned');
  }
  if (status.lifecycle !== 'verified_complete') problems.push('UI-09 artifact lifecycle must be verified_complete after PR #59 merge reconciliation');
}

if (existsSync(join(audit, 'route-parity.json'))) {
  const routes = json('route-parity.json');
  if (routes.implemented_routes.length !== 15) problems.push('route parity must name 15 implemented routes');
  if (routes.gated_no_route.length !== 8) problems.push('route parity must name 8 gated no-route identities');
  for (const route of routes.implemented_routes) {
    const expected = byKey.get(route.screen_key);
    if (!expected || expected.runtime_route_name !== route.route_name || expected.route_path !== route.route_path) {
      problems.push(`route parity mismatch: ${route.screen_key}`);
    }
  }
  for (const key of routes.gated_no_route) {
    if (byKey.get(key)?.implementation_status !== 'disabled_by_gate') problems.push(`route parity incorrectly gates ${key}`);
  }
}

if (existsSync(join(audit, 'permission-api-matrix.json'))) {
  const matrix = json('permission-api-matrix.json');
  const counts = matrix.permission_catalogue;
  if (counts.total !== 169 || counts.active !== 134 || counts.planned !== 35 || counts.added_by_ui09 !== 0 || counts.activated_by_ui09 !== 0) {
    problems.push('permission catalogue must remain 169/134/35 with zero UI-09 additions/activations');
  }
  if (matrix.pages.length !== 15 || matrix.gated_pages_have_no_api.length !== 8) problems.push('permission/API matrix must describe 15 live and 8 inert pages');
}

if (existsSync(join(audit, 'responsive-matrix.json'))) {
  const responsive = json('responsive-matrix.json');
  if (responsive.widths.map((row) => row.px).join(',') !== '360,767,768,1024,1025,1280,1440') problems.push('responsive matrix width set is incomplete or unordered');
  if (responsive.widths.some((row) => row.pages !== 15 || row.horizontal_overflow !== 0)) problems.push('responsive matrix is not green across all 15 pages');
}

if (existsSync(join(audit, 'accessibility-matrix.json'))) {
  const a11y = json('accessibility-matrix.json');
  if (a11y.axe.light_pages.length !== 15 || a11y.axe.serious !== 0 || a11y.axe.critical !== 0) problems.push('accessibility matrix must be 15 light pages with 0 serious/critical');
}

if (existsSync(join(audit, 'browser-proof.json'))) {
  const run = json('browser-proof.json').focused_playwright;
  if (run.passed !== 65 || run.failed !== 0 || run.skipped !== 0 || run.exit_code !== 0) problems.push('focused browser proof must record 65 passed and exit 0');
}

if (existsSync(join(audit, 'defect-closure.json'))) {
  const closure = json('defect-closure.json');
  if (closure.closures.some((item) => item.lifecycle !== 'verified_complete')) problems.push('UI-09 closures must be verified_complete after PR #59 merge reconciliation');
  if (closure.verified_complete !== 15 || closure.local_complete !== 0) problems.push('UI-09 closure totals must be 15 verified / 0 local after merge reconciliation');
}

if (existsSync(join(audit, 'screenshot-index.json'))) {
  const screenshots = json('screenshot-index.json');
  for (const capture of screenshots.captures) {
    const path = join(audit, capture.file);
    if (!existsSync(path)) {
      problems.push(`missing screenshot: ${capture.file}`);
      continue;
    }
    const hash = createHash('sha256').update(readFileSync(path)).digest('hex');
    if (hash !== capture.sha256) problems.push(`screenshot hash mismatch: ${capture.file}`);
  }
}

if (problems.length) {
  console.error('UI-09 audit artifacts FAILED:');
  for (const problem of problems) console.error(`  ${problem}`);
  process.exit(1);
}

console.log('UI-09 audit artifacts: OK — 23 pages, 15 implemented, 8 gated, 0 planned, permission catalogue 169/134/35.');
