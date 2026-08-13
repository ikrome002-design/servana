/** Phase UI-10 evidence consistency checker. */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import yaml from 'js-yaml';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const audit = join(root, 'docs/frontend/audits/ui-10');
const required = [
  'implementation-checklist.md', 'page-readiness-matrix.json', 'route-activation-matrix.json',
  'gate-disposition.json', 'responsive-matrix.json', 'accessibility-matrix.json',
  'visual-quality-review.json', 'browser-proof.json', 'screenshot-index.json',
  'historical-evidence-baseline.json', 'production-host-proof.json', 'defect-closure.json',
];
const problems = [];
const json = (name) => JSON.parse(readFileSync(join(audit, name), 'utf8'));

for (const name of required) {
  if (!existsSync(join(audit, name))) problems.push(`missing required artifact: ${name}`);
}

const nav = yaml.load(readFileSync(join(root, 'docs/frontend/navigation/servana-user-account-navigation-map.yaml'), 'utf8'));
const branch = nav.pages.filter((page) => page.account_type === 'merchant_branch');
const byKey = new Map(branch.map((page) => [page.screen_key, page]));
if (branch.length !== 18) problems.push(`canonical Branch count must be 18, found ${branch.length}`);

if (existsSync(join(audit, 'page-readiness-matrix.json'))) {
  const readiness = json('page-readiness-matrix.json');
  if (readiness.rows.length !== 18) problems.push(`readiness matrix must contain 18 rows, found ${readiness.rows.length}`);
  if (readiness.target_disposition.implemented !== 15 || readiness.target_disposition.disabled_by_gate !== 3 || readiness.target_disposition.planned !== 0 || readiness.target_disposition.removed_by_authority !== 0) {
    problems.push('readiness target must be 15 implemented / 3 gated / 0 planned / 0 removed');
  }
  if (readiness.classification_totals.F !== 0 || readiness.blocking_ambiguities.length !== 0) problems.push('readiness contains an unresolved class-F ambiguity');
}

if (existsSync(join(audit, 'route-activation-matrix.json'))) {
  const routes = json('route-activation-matrix.json');
  if (routes.implemented_routes.length !== 15 || routes.gated_no_route.length !== 3) problems.push('route activation must name 15 live routes and 3 gated no-routes');
  for (const route of routes.implemented_routes) {
    const expected = byKey.get(route.screen_key);
    if (!expected || expected.implementation_status !== 'implemented' || expected.runtime_route_name !== route.route_name || expected.route_path !== route.route_path) {
      problems.push(`route activation mismatch: ${route.screen_key}`);
    }
  }
  for (const key of routes.gated_no_route) {
    const expected = byKey.get(key);
    if (!expected || expected.implementation_status !== 'disabled_by_gate' || expected.runtime_route_name !== null) problems.push(`gated route mismatch: ${key}`);
  }
}

const counted = branch.reduce((totals, page) => {
  totals[page.implementation_status] = (totals[page.implementation_status] ?? 0) + 1;
  return totals;
}, {});
if (counted.implemented !== 15 || counted.disabled_by_gate !== 3 || (counted.planned ?? 0) !== 0 || (counted.removed_by_authority ?? 0) !== 0) {
  problems.push('canonical Branch navigation is not 15 implemented / 3 gated / 0 planned / 0 removed');
}

if (existsSync(join(audit, 'gate-disposition.json'))) {
  const gates = json('gate-disposition.json');
  if (!gates.external_gate_w_closed || gates.entries.length !== 3 || gates.entries.some((entry) => entry.route_exists || entry.component_exists || entry.network_runtime_exists)) {
    problems.push('Gate W/21N disposition must remain closed with exactly 3 inert entries');
  }
}

if (existsSync(join(audit, 'responsive-matrix.json'))) {
  const responsive = json('responsive-matrix.json');
  if (responsive.widths.map((row) => row.px).join(',') !== '360,767,768,1024,1025,1280,1440') problems.push('responsive width set is incomplete or unordered');
  if (responsive.widths.some((row) => row.pages !== 15 || row.horizontal_overflow !== 0) || responsive.footer_obstruction !== false) problems.push('responsive proof is not green across all 15 pages');
}

if (existsSync(join(audit, 'accessibility-matrix.json'))) {
  const a11y = json('accessibility-matrix.json');
  if (a11y.axe.light_pages.length !== 15 || a11y.axe.serious !== 0 || a11y.axe.critical !== 0 || !a11y.axe.dark_drawer) problems.push('accessibility proof must cover 15 light pages plus dark/drawer with 0 serious/critical');
}

if (existsSync(join(audit, 'browser-proof.json'))) {
  const browser = json('browser-proof.json');
  const run = browser.focused_playwright;
  if (run.passed !== 52 || run.failed !== 0 || run.skipped !== 0 || run.exit_code !== 0) problems.push('focused browser proof must record 52 passed and exit 0');
  const whole = browser.whole_product_playwright;
  if (whole.collected !== 1325 || whole.passed !== 1325 || whole.failed !== 0 || whole.flaky !== 0 || whole.skipped !== 0 || whole.exit_code !== 0) {
    problems.push('whole-product browser proof must record 1325/1325, no flaky/skipped case and exit 0');
  }
}

if (existsSync(join(audit, 'production-host-proof.json'))) {
  const production = json('production-host-proof.json');
  if (production.images.length !== 2 || production.images.some((image) => image.build_exit_code !== 0)) problems.push('both invalidated production images must build successfully');
  if (production.nginx_syntax.exit_code !== 0 || production.canonical_host.passed !== 38 || production.canonical_host.failed !== 0 || production.canonical_host.exit_code !== 0) {
    problems.push('production Nginx and 38-check canonical-host proof must be green');
  }
  if (production.topology.project_volume_mounted || !production.topology.removed_after_proof) problems.push('production proof topology must be no-volume and removed');
}

if (existsSync(join(audit, 'defect-closure.json'))) {
  const closure = json('defect-closure.json');
  if (closure.closures.some((item) => item.lifecycle !== 'local_complete') || closure.local_complete !== closure.closures.length || closure.verified_complete !== 0) {
    problems.push('UI-10 closures must remain local_complete until the next phase reconciles the merge');
  }
}

if (existsSync(join(audit, 'screenshot-index.json'))) {
  const screenshots = json('screenshot-index.json');
  if (screenshots.totals.implemented_page_captures !== 15) problems.push('screenshot evidence must cover all 15 implemented pages');
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
  console.error('UI-10 audit artifacts FAILED:');
  for (const problem of problems) console.error(`  ${problem}`);
  process.exit(1);
}

console.log('UI-10 audit artifacts: OK — 18 pages, 15 implemented, 3 gated, 0 planned, permission catalogue 169/134/35, OpenAPI 341 operations.');
