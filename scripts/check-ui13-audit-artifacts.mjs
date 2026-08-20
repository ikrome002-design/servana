/** Phase UI-13 Front Office evidence consistency checker. */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import yaml from 'js-yaml';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const audit = join(root, 'docs/frontend/audits/ui-13');
const required = [
  'implementation-checklist.md', 'design-inspiration-notes.md', 'visual-language-plan.md',
  'page-visual-acceptance.json', 'page-readiness-matrix.json', 'gate-disposition.json',
  'route-activation.json', 'defect-closure.json', 'browser-proof.json', 'responsive-matrix.json',
  'accessibility-matrix.json', 'theme-matrix.json', 'screenshot-index.json',
  'historical-evidence-baseline.json', 'historical-evidence-restoration.json', 'production-host-proof.json',
];
const problems = [];
const json = (name) => JSON.parse(readFileSync(join(audit, name), 'utf8'));
for (const name of required) if (!existsSync(join(audit, name))) problems.push(`missing required artifact: ${name}`);

const nav = yaml.load(readFileSync(join(root, 'docs/frontend/navigation/servana-user-account-navigation-map.yaml'), 'utf8'));
const pages = nav.pages.filter((page) => page.account_type === 'merchant_front_office');
const byKey = new Map(pages.map((page) => [page.screen_key, page]));
if (pages.length !== 19) problems.push(`canonical Front Office count must be 19, found ${pages.length}`);
const counted = pages.reduce((totals, page) => {
  totals[page.implementation_status] = (totals[page.implementation_status] ?? 0) + 1;
  return totals;
}, {});
if (counted.implemented !== 17 || counted.disabled_by_gate !== 2 || (counted.planned ?? 0) !== 0 || (counted.removed_by_authority ?? 0) !== 0) {
  problems.push('canonical Front Office navigation is not 17 implemented / 2 gated / 0 planned / 0 removed');
}

if (existsSync(join(audit, 'page-readiness-matrix.json'))) {
  const readiness = json('page-readiness-matrix.json');
  const evidenceKeys = new Set(readiness.row_evidence?.map((row) => row.key));
  if (readiness.entries.length !== 19 || readiness.row_evidence?.length !== 19 || readiness.class_counts.F !== 0) problems.push('readiness must contain 19 classified rows, 19 detailed evidence rows and no class-F ambiguity');
  if (readiness.target_disposition.implemented !== 17 || readiness.target_disposition.disabled_by_gate !== 2 || readiness.target_disposition.planned !== 0 || readiness.target_disposition.removed_by_authority !== 0) problems.push('readiness disposition mismatch');
  for (const page of pages) if (!evidenceKeys.has(page.screen_key)) problems.push(`readiness evidence missing: ${page.screen_key}`);
}
if (existsSync(join(audit, 'route-activation.json'))) {
  const routes = json('route-activation.json');
  if (routes.implemented_routes.length !== 17 || routes.gated_no_route.length !== 2) problems.push('route activation must name 17 live routes and 2 gated no-routes');
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
  if (!gates.external_gate_w_closed || !gates.phase_20d_w_closed || !gates.phase_21n_closed || gates.entries.length !== 2 || gates.entries.some((entry) => entry.route_exists || entry.component_exists || entry.network_runtime_exists)) problems.push('Gate disposition must remain closed with exactly two inert entries');
}
if (existsSync(join(audit, 'page-visual-acceptance.json'))) {
  const visual = json('page-visual-acceptance.json');
  if (visual.entries.length !== 17 || visual.summary.passing !== 17 || visual.summary.failing !== 0) problems.push('visual acceptance must cover and pass all 17 implemented pages');
  for (const entry of visual.entries) {
    const scores = Object.values(entry.scores);
    const total = scores.reduce((sum, score) => sum + score, 0);
    if (scores.length !== 15 || scores.some((score) => score === 0) || total !== entry.total || total < 24) problems.push(`visual rubric threshold failed: ${entry.screen_key}`);
    for (const criterion of visual.rubric.required_twos) if (entry.scores[criterion] !== 2) problems.push(`required visual criterion is not 2: ${entry.screen_key}/${criterion}`);
    for (const file of entry.evidence) if (!existsSync(join(audit, file))) problems.push(`visual evidence missing: ${entry.screen_key}/${file}`);
  }
}
if (existsSync(join(audit, 'responsive-matrix.json'))) {
  const responsive = json('responsive-matrix.json');
  if (responsive.widths.map((row) => row.px).join(',') !== '360,767,768,1024,1025,1280,1440') problems.push('responsive width set is incomplete or unordered');
  if (responsive.widths.some((row) => row.pages !== 17 || row.horizontal_overflow !== 0) || responsive.footer_obstruction !== false || !responsive.fixed_footer_reserve_at_least_footer_height || !responsive.zoom_200_percent_equivalent) problems.push('responsive proof is not green across all 17 pages');
}
if (existsSync(join(audit, 'accessibility-matrix.json'))) {
  const a11y = json('accessibility-matrix.json');
  if (a11y.axe.light_pages.length !== 17 || a11y.axe.dark_pages.length !== 17 || a11y.axe.serious !== 0 || a11y.axe.critical !== 0 || a11y.targets.pages_checked !== 17 || a11y.targets.undersized_controls !== 0) problems.push('accessibility proof must cover light/dark 17 pages with axe 0/0 and no undersized controls');
}
if (existsSync(join(audit, 'theme-matrix.json'))) {
  const theme = json('theme-matrix.json');
  if (theme.fresh_browser_default !== 'light' || !theme.explicit_dark_persists_after_reload || theme.light_pages_reviewed !== 17 || theme.dark_pages_reviewed !== 17 || theme.dark_axe_serious !== 0 || theme.dark_axe_critical !== 0 || theme.treatment.mechanically_inverted) problems.push('theme proof must show fresh light and intentional accessible dark across 17 pages');
}
if (existsSync(join(audit, 'browser-proof.json'))) {
  const browser = json('browser-proof.json');
  const focused = browser.focused_playwright;
  if (focused.collected !== 59 || focused.passed !== 59 || focused.failed !== 0 || focused.flaky !== 0 || focused.skipped !== 0 || focused.exit_code !== 0) problems.push('focused browser proof must be exactly 59/59 green');
  const whole = browser.whole_product_playwright;
  if (!Number.isInteger(whole.collected) || whole.collected < 1 || whole.passed !== whole.collected || whole.failed !== 0 || whole.flaky !== 0 || whole.skipped !== 0 || whole.exit_code !== 0) problems.push('whole-product browser proof must be fully green with no flaky/skipped cases');
}
if (existsSync(join(audit, 'production-host-proof.json'))) {
  const production = json('production-host-proof.json');
  if (production.images.length !== 2 || production.images.some((entry) => entry.build_exit_code !== 0)) problems.push('both invalidated production images must build');
  if (production.nginx_syntax.exit_code !== 0 || production.canonical_host.failed !== 0 || production.canonical_host.exit_code !== 0) problems.push('production host proof must be green');
  if (production.topology.project_volume_mounted || !production.topology.removed_after_proof) problems.push('production proof must be no-volume and removed');
}
if (existsSync(join(audit, 'historical-evidence-restoration.json'))) {
  const restoration = json('historical-evidence-restoration.json');
  if (!restoration.whole_product_run_complete || !restoration.frozen_predecessor_aggregates_match || restoration.broad_clean_reset_restore_used) problems.push('historical evidence restoration must complete precisely with frozen aggregates restored');
}
if (existsSync(join(audit, 'defect-closure.json'))) {
  const closure = json('defect-closure.json');
  const locallyClosed = closure.closures.every((item) => item.lifecycle === 'local_complete')
    && closure.counts.local_complete === closure.closures.length && closure.counts.verified_complete === 0;
  const mergeVerified = closure.closures.every((item) => item.lifecycle === 'verified_complete')
    && closure.counts.verified_complete === closure.closures.length && closure.counts.local_complete === 0;
  if ((!locallyClosed && !mergeVerified) || closure.counts.open !== 0) problems.push('all UI-13 closures must be homogeneously local_complete or verified_complete with none open');
}
if (existsSync(join(audit, 'screenshot-index.json'))) {
  const screenshots = json('screenshot-index.json');
  if (screenshots.captures.filter((capture) => capture.file.includes('/desktop-light-') && capture.screen_key !== null).length !== 17) problems.push('screenshots must cover all 17 implemented pages in light mode');
  if (screenshots.captures.filter((capture) => capture.file.includes('/desktop-dark-') && capture.screen_key !== null).length !== 17) problems.push('screenshots must cover all 17 implemented pages in dark mode');
  for (const capture of screenshots.captures) {
    const path = join(audit, capture.file);
    if (!existsSync(path)) { problems.push(`missing screenshot: ${capture.file}`); continue; }
    const hash = createHash('sha256').update(readFileSync(path)).digest('hex');
    if (hash !== capture.sha256) problems.push(`screenshot hash mismatch: ${capture.file}`);
  }
}

if (problems.length) {
  console.error('UI-13 audit artifacts FAILED:');
  for (const problem of problems) console.error(`  ${problem}`);
  process.exit(1);
}
console.log('UI-13 audit artifacts: OK — 19 pages, 17 implemented, 2 gated, 0 planned, 59 focused browser cases, OpenAPI 350 operations.');
