/** Phase UI-15 Audit evidence consistency checker. */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import yaml from 'js-yaml';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const audit = join(root, 'docs/frontend/audits/ui-15');
const IMPLEMENTED = 14;
const SUPPORTING = 2;
const required = [
  'implementation-checklist.md', 'design-inspiration-notes.md', 'visual-language-continuity.md',
  'page-readiness-matrix.json', 'gate-disposition.json', 'route-activation.json', 'page-visual-acceptance.json',
  'audit-security-matrix.json', 'flag-review-matrix.json', 'export-security-matrix.json', 'integrity-truth-matrix.json',
  'browser-proof.json', 'responsive-matrix.json', 'accessibility-matrix.json', 'theme-matrix.json',
  'screenshot-index.json', 'historical-evidence-baseline.json', 'historical-evidence-restoration.json',
  'production-host-proof.json', 'defect-closure.json',
];
const problems = [];
const json = (name) => JSON.parse(readFileSync(join(audit, name), 'utf8'));
const read = (name, fn) => { if (existsSync(join(audit, name))) fn(json(name)); };
for (const name of required) if (!existsSync(join(audit, name))) problems.push(`missing required artifact: ${name}`);

const nav = yaml.load(readFileSync(join(root, 'docs/frontend/navigation/servana-user-account-navigation-map.yaml'), 'utf8'));
const pages = nav.pages.filter((page) => page.account_type === 'merchant_audit');
const byKey = new Map(pages.map((page) => [page.screen_key, page]));
if (pages.length !== 15) problems.push(`canonical Audit count must be 15, found ${pages.length}`);
const counted = pages.reduce((totals, page) => ({ ...totals, [page.implementation_status]: (totals[page.implementation_status] ?? 0) + 1 }), {});
if (counted.implemented !== IMPLEMENTED || counted.disabled_by_gate !== 1 || (counted.planned ?? 0) !== 0 || (counted.removed_by_authority ?? 0) !== 0) {
  problems.push('canonical Audit navigation is not 14 implemented / 1 gated / 0 planned / 0 removed');
}

read('route-activation.json', (routes) => {
  if (routes.implemented_routes.length !== IMPLEMENTED || routes.gated_no_route.length !== 1) problems.push('route activation must name 14 live routes and 1 gated no-route');
  for (const route of routes.implemented_routes) {
    const expected = byKey.get(route.screen_key);
    if (!expected || expected.implementation_status !== 'implemented' || expected.runtime_route_name !== route.route_name || expected.route_path !== route.route_path) problems.push(`route activation mismatch: ${route.screen_key}`);
  }
  for (const key of routes.gated_no_route) {
    const expected = byKey.get(key);
    if (!expected || expected.implementation_status !== 'disabled_by_gate' || expected.runtime_route_name !== null) problems.push(`gated route mismatch: ${key}`);
  }
  const a = routes.arithmetic;
  if (a.implemented + a.disabled_by_gate + a.removed_by_authority !== 15 || a.planned !== 0) problems.push('route arithmetic must be 14 + 1 + 0 = 15 with 0 planned');
});
read('page-readiness-matrix.json', (matrix) => {
  if (matrix.rows.length !== 15) problems.push('readiness matrix must have 15 rows');
  for (const row of matrix.rows) if (byKey.get(row.screen_key)?.implementation_status !== row.final) problems.push(`readiness final status mismatch: ${row.screen_key}`);
});
read('gate-disposition.json', (gates) => {
  if (!gates.external_gate_w_closed || !gates.phase_20d_w_closed || !gates.phase_21n_closed || gates.entries.length !== 1 || gates.entries.some((entry) => entry.route_exists || entry.component_exists || entry.network_runtime_exists)) problems.push('gate disposition must remain closed with exactly one inert entry');
});
read('page-visual-acceptance.json', (visual) => {
  if (visual.entries.length !== IMPLEMENTED || visual.summary.passing !== IMPLEMENTED || visual.summary.failing !== 0) problems.push('visual acceptance must cover and pass all 14 implemented pages');
  for (const entry of visual.entries) {
    const scores = Object.values(entry.scores);
    const total = scores.reduce((sum, score) => sum + score, 0);
    if (scores.length !== 15 || scores.some((score) => score === 0) || total !== entry.total || total < 27) problems.push(`visual rubric threshold failed: ${entry.screen_key}`);
    for (const criterion of visual.rubric.required_twos) if (entry.scores[criterion] !== 2) problems.push(`required visual criterion is not 2: ${entry.screen_key}/${criterion}`);
    for (const file of entry.evidence) if (!existsSync(join(audit, file))) problems.push(`visual evidence missing: ${entry.screen_key}/${file}`);
  }
});
for (const [name, key] of [['audit-security-matrix.json', 'rows'], ['flag-review-matrix.json', 'rows'], ['export-security-matrix.json', 'rows'], ['integrity-truth-matrix.json', 'rows']]) {
  read(name, (matrix) => { if (matrix[key].some((row) => !String(row.result).startsWith('pass') && row.result !== 'recorded')) problems.push(`${name} has a failing row`); });
}
read('responsive-matrix.json', (responsive) => {
  if (responsive.widths.map((row) => row.px).join(',') !== '360,767,768,1024,1025,1280,1440') problems.push('responsive width set is incomplete or unordered');
  if (responsive.widths.some((row) => row.pages !== IMPLEMENTED + SUPPORTING || row.horizontal_overflow !== 0) || responsive.footer_obstruction !== false || !responsive.fixed_footer_reserve_at_least_footer_height || !responsive.zoom_200_percent_equivalent) problems.push('responsive proof is not green across all pages');
});
read('accessibility-matrix.json', (a11y) => {
  if (a11y.axe.light_pages.length !== IMPLEMENTED + SUPPORTING || a11y.axe.dark_pages.length !== IMPLEMENTED + SUPPORTING || a11y.axe.serious !== 0 || a11y.axe.critical !== 0 || a11y.targets.undersized_controls !== 0) problems.push('accessibility proof must cover light/dark pages with axe 0/0 and no undersized controls');
});
read('theme-matrix.json', (theme) => {
  if (theme.fresh_browser_default !== 'light' || !theme.explicit_dark_persists_after_reload || theme.dark_axe_serious !== 0 || theme.dark_axe_critical !== 0 || theme.treatment.mechanically_inverted) problems.push('theme proof must show fresh light and intentional accessible dark');
});
read('browser-proof.json', (browser) => {
  const focused = browser.focused_playwright;
  if (focused.passed !== focused.collected || focused.failed !== 0 || focused.flaky !== 0 || focused.skipped !== 0 || focused.exit_code !== 0) problems.push('focused browser proof must be fully green');
  const whole = browser.whole_product_playwright;
  if (!Number.isInteger(whole.collected) || whole.collected < 1 || whole.passed !== whole.collected || whole.failed !== 0 || whole.flaky !== 0 || whole.skipped !== 0 || whole.exit_code !== 0) problems.push('whole-product browser proof must be fully green with no flaky/skipped cases');
});
read('production-host-proof.json', (production) => {
  if (production.images.length !== 2 || production.images.some((entry) => entry.build_exit_code !== 0)) problems.push('both production images must build');
  if (production.nginx_syntax.exit_code !== 0 || production.canonical_host.failed !== 0 || production.canonical_host.exit_code !== 0) problems.push('production host proof must be green');
  if (production.topology.project_volume_mounted || !production.topology.removed_after_proof) problems.push('production proof must be no-volume and removed');
});
read('historical-evidence-restoration.json', (restoration) => {
  if (!restoration.whole_product_run_complete || !restoration.frozen_predecessor_aggregates_match || restoration.broad_clean_reset_restore_used) problems.push('historical evidence restoration must complete precisely with frozen aggregates restored');
});
read('defect-closure.json', (closure) => {
  if (closure.counts.open !== 0) problems.push('UI-15 has open defects');
  if (closure.counts.total !== closure.closures.length) problems.push('defect counts disagree with closures');
  for (const item of closure.closures.filter((entry) => entry.lifecycle === 'blocked')) if (!item.owner || !item.entry_condition) problems.push(`blocked defect lacks owner/entry condition: ${item.id}`);
});
read('screenshot-index.json', (screenshots) => {
  if (screenshots.captures.filter((capture) => capture.file.includes('/desktop-light-')).length !== IMPLEMENTED + SUPPORTING) problems.push('screenshots must cover all pages in light mode');
  if (screenshots.captures.filter((capture) => capture.file.includes('/desktop-dark-')).length !== IMPLEMENTED + SUPPORTING) problems.push('screenshots must cover all pages in dark mode');
  for (const capture of screenshots.captures) {
    const path = join(audit, capture.file);
    if (!existsSync(path)) { problems.push(`missing screenshot: ${capture.file}`); continue; }
    if (createHash('sha256').update(readFileSync(path)).digest('hex') !== capture.sha256) problems.push(`screenshot hash mismatch: ${capture.file}`);
  }
});

if (problems.length) {
  console.error('UI-15 audit artifacts FAILED:');
  for (const problem of problems) console.error(`  ${problem}`);
  process.exit(1);
}
console.log('UI-15 audit artifacts: OK — 15 pages, 14 implemented, 1 gated, 0 planned, visual 14/14 >= 27/30.');
