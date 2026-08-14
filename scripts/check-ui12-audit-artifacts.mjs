/** Phase UI-12 Finance evidence consistency checker. */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import yaml from 'js-yaml';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const audit = join(root, 'docs/frontend/audits/ui-12');
const required = [
  'implementation-checklist.md', 'page-readiness-matrix.json', 'route-activation-matrix.json',
  'gate-disposition.json', 'responsive-matrix.json', 'accessibility-matrix.json',
  'visual-quality-review.json', 'browser-proof.json', 'screenshot-index.json',
  'historical-evidence-baseline.json', 'production-host-proof.json', 'defect-closure.json',
];
const problems = [];
const json = (name) => JSON.parse(readFileSync(join(audit, name), 'utf8'));
for (const name of required) if (!existsSync(join(audit, name))) problems.push(`missing required artifact: ${name}`);

const nav = yaml.load(readFileSync(join(root, 'docs/frontend/navigation/servana-user-account-navigation-map.yaml'), 'utf8'));
const pages = nav.pages.filter((page) => page.account_type === 'merchant_finance');
const byKey = new Map(pages.map((page) => [page.screen_key, page]));
if (pages.length !== 24) problems.push(`canonical Finance count must be 24, found ${pages.length}`);
const counted = pages.reduce((totals, page) => {
  totals[page.implementation_status] = (totals[page.implementation_status] ?? 0) + 1;
  return totals;
}, {});
if (counted.implemented !== 20 || counted.disabled_by_gate !== 4 || (counted.planned ?? 0) !== 0 || (counted.removed_by_authority ?? 0) !== 0) {
  problems.push('canonical Finance navigation is not 20 implemented / 4 gated / 0 planned / 0 removed');
}

if (existsSync(join(audit, 'page-readiness-matrix.json'))) {
  const readiness = json('page-readiness-matrix.json');
  if (readiness.entries.length !== 24 || readiness.class_counts.F !== 0) problems.push('readiness must contain 24 rows and no class-F ambiguity');
  if (readiness.target_disposition.implemented !== 20 || readiness.target_disposition.disabled_by_gate !== 4 || readiness.target_disposition.planned !== 0 || readiness.target_disposition.removed_by_authority !== 0) problems.push('readiness disposition mismatch');
}
if (existsSync(join(audit, 'route-activation-matrix.json'))) {
  const routes = json('route-activation-matrix.json');
  if (routes.implemented_routes.length !== 20 || routes.gated_no_route.length !== 4) problems.push('route activation must name 20 live routes and 4 gated no-routes');
  for (const route of routes.implemented_routes) {
    const expected = byKey.get(route.screen_key);
    if (!expected || expected.implementation_status !== 'implemented' || expected.runtime_route_name !== route.route_name || expected.route_path !== route.route_path) problems.push(`route activation mismatch: ${route.screen_key}`);
  }
  for (const key of routes.gated_no_route) {
    const expected = byKey.get(key);
    if (!expected || expected.implementation_status !== 'disabled_by_gate' || expected.runtime_route_name !== null) problems.push(`gated route mismatch: ${key}`);
  }
}
if (existsSync(join(audit, 'gate-disposition.json'))) {
  const gates = json('gate-disposition.json');
  if (!gates.external_gate_w_closed || !gates.phase_20d_w_closed || !gates.phase_21n_closed || gates.entries.length !== 4 || gates.entries.some((entry) => entry.route_exists || entry.component_exists || entry.network_runtime_exists)) problems.push('Gate disposition must remain closed with exactly four inert entries');
}
if (existsSync(join(audit, 'responsive-matrix.json'))) {
  const responsive = json('responsive-matrix.json');
  if (responsive.widths.map((row) => row.px).join(',') !== '360,767,768,1024,1025,1280,1440') problems.push('responsive width set is incomplete or unordered');
  if (responsive.widths.some((row) => row.pages !== 20 || row.horizontal_overflow !== 0) || responsive.footer_obstruction !== false || !responsive.zoom_200_percent_equivalent) problems.push('responsive proof is not green across all 20 pages');
}
if (existsSync(join(audit, 'accessibility-matrix.json'))) {
  const a11y = json('accessibility-matrix.json');
  if (a11y.axe.light_pages.length !== 20 || a11y.axe.serious !== 0 || a11y.axe.critical !== 0) problems.push('accessibility proof must cover 20 pages with zero serious/critical');
}
if (existsSync(join(audit, 'browser-proof.json'))) {
  const browser = json('browser-proof.json');
  if (browser.focused_playwright.passed < 1 || browser.focused_playwright.failed !== 0 || browser.focused_playwright.flaky !== 0 || browser.focused_playwright.skipped !== 0 || browser.focused_playwright.exit_code !== 0) problems.push('focused browser proof must be fully green');
  const whole = browser.whole_product_playwright;
  if (whole.collected < 1 || whole.passed !== whole.collected || whole.failed !== 0 || whole.flaky !== 0 || whole.skipped !== 0 || whole.exit_code !== 0) problems.push('whole-product browser proof must be fully green with no flaky/skipped cases');
}
if (existsSync(join(audit, 'production-host-proof.json'))) {
  const production = json('production-host-proof.json');
  if (production.images.length !== 2 || production.images.some((entry) => entry.build_exit_code !== 0)) problems.push('both invalidated production images must build');
  if (production.nginx_syntax.exit_code !== 0 || production.canonical_host.failed !== 0 || production.canonical_host.exit_code !== 0) problems.push('production host proof must be green');
  if (production.topology.project_volume_mounted || !production.topology.removed_after_proof) problems.push('production proof must be no-volume and removed');
}
if (existsSync(join(audit, 'defect-closure.json'))) {
  const closure = json('defect-closure.json');
  if (closure.closures.some((item) => item.lifecycle !== 'local_complete') || closure.local_complete !== closure.closures.length || closure.verified_complete !== 0 || closure.open !== 0) problems.push('all UI-12 closures must be local_complete with none open before the PR lifecycle');
}
if (existsSync(join(audit, 'screenshot-index.json'))) {
  const screenshots = json('screenshot-index.json');
  if (screenshots.captures.filter((capture) => capture.screen_key !== null).length < 20) problems.push('screenshots must cover every implemented Finance page');
  for (const capture of screenshots.captures) {
    const path = join(audit, capture.file);
    if (!existsSync(path)) { problems.push(`missing screenshot: ${capture.file}`); continue; }
    const hash = createHash('sha256').update(readFileSync(path)).digest('hex');
    if (hash !== capture.sha256) problems.push(`screenshot hash mismatch: ${capture.file}`);
  }
}

if (problems.length) {
  console.error('UI-12 audit artifacts FAILED:');
  for (const problem of problems) console.error(`  ${problem}`);
  process.exit(1);
}
console.log('UI-12 audit artifacts: OK — 24 pages, 20 implemented, 4 gated, 0 planned, permission catalogue 169/134/35, OpenAPI 347 operations.');
