import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type Route } from '@playwright/test';
import { writeEvidenceFile, writeEvidenceScreenshot } from './support/evidenceScreenshot';
import { AUDIT_INSTANT_UTC, IDS, prepare, type AuditScreen } from './support/releaseAudit';
import { assertNoHorizontalScroll } from './support/roleBootstrap';

/*
 | Phase UI-15 — Audit account browser proof (audit.servana.ke; UI/UX plan Appendix A §12).
 |
 | The preview has no backend: `/me` and `/api/v1` are stubbed with deterministic, masked,
 | branch-scoped synthetic data so the REAL frontend renders every live page. Authorization,
 | branch isolation, masking, immutability and integrity truth are proven server-side by
 | tests/Feature/Audit/* (including Ui15AuditExperienceApiTest); this proves the browser behaviour.
 */

const SHOTS = resolve(process.cwd(), 'docs/frontend/audits/ui-15/screenshots');
mkdirSync(SHOTS, { recursive: true });

const PERMISSIONS = [
  'audit.branch_events.view', 'audit.finance.view', 'audit.compensation.view', 'audit.export',
  'audit.flagged_event.create', 'audit.flagged_event.update_status', 'audit.flagged_event.resolve_metadata',
  'platform_fee.view',
];

const EVENT = IDS.auditEvent;
const FLAG = IDS.flagged;
const EXPORT = IDS.auditExport;

const PAGES = [
  { screen: 'dashboard', path: '/dashboard', ready: '[data-testid="audit-dashboard"]', api: '/audit-integrity' },
  { screen: 'get-started', path: '/get-started', ready: 'main h1', api: null },
  { screen: 'audit', path: '/audit', ready: '[data-testid="audit-event-row"]', api: '/audit-logs?' },
  { screen: 'audit-event-detail', path: `/audit/${EVENT}`, ready: '[data-testid="audit-detail-action"]', api: `/audit-logs/${EVENT}` },
  { screen: 'audit-flags', path: '/audit/flags', ready: '[data-testid="flagged-row"]', api: '/audit-flagged-events' },
  { screen: 'audit-operations', path: '/audit/operations', ready: '[data-testid="audit-event-row"]', api: 'view=operational' },
  { screen: 'audit-finance', path: '/audit/finance', ready: '[data-testid="audit-event-row"]', api: '/audit-logs/finance' },
  { screen: 'audit-compensation', path: '/audit/compensation', ready: '[data-testid="audit-event-row"]', api: '/audit-logs/compensation' },
  { screen: 'audit-access', path: '/audit/access', ready: '[data-testid="audit-event-feed"] li', api: 'view=access' },
  { screen: 'audit-privacy-preferred', path: '/audit/privacy-preferred', ready: '[data-testid="audit-event-row"]', api: 'view=privacy_preferred' },
  { screen: 'exports', path: '/exports', ready: '[data-testid="audit-export-row"]', api: '/audit-exports' },
  { screen: 'exports-history', path: '/exports/history', ready: '[data-testid="audit-export-history-page"] h1', api: '/audit-exports' },
  { screen: 'audit-integrity', path: '/audit/integrity', ready: '[data-testid="audit-integrity-result"]', api: '/audit-integrity' },
  { screen: 'account', path: '/account', ready: '[data-testid="audit-account-page"] h1', api: '/auth/sessions' },
] as const;

const SUPPORTING = [
  { screen: 'flag-review', path: `/audit/flags/${FLAG}`, ready: '[data-testid="flagged-detail-status"]' },
  { screen: 'export-record', path: `/exports/${EXPORT}`, ready: '[data-testid="audit-export-detail-status"]' },
] as const;

type Entry = { screen: string; path: string; ready: string };

const auditScreen = (entry: Entry, permissions: string[] = PERMISSIONS): AuditScreen => ({
  key: `audit-ui15-${entry.screen}`,
  route: null,
  path: entry.path,
  role: 'merchant_audit',
  state: 'populated',
  ready: entry.ready,
  bootstrap: { permissions },
});

// --- deterministic synthetic Audit data ------------------------------------------------------

const at = (minutesAgo: number): string => new Date(Date.parse(AUDIT_INSTANT_UTC) - minutesAgo * 60_000).toISOString();
const ulid = (label: string): string => `01HZZ${label}`.padEnd(26, '0').slice(0, 26);

interface FixtureEvent {
  id: string; action: string; severity: string; actor: string | null; branch: string; subject_type: string | null;
  context: Record<string, unknown>; correlation_id: string | null; created_at: string;
  review: { flag: string; status: string; flag_count: number } | null; can: { view: boolean };
  views: string[];
}

const event = (label: string, action: string, severity: string, minutesAgo: number, views: string[], extra: Partial<FixtureEvent> = {}): FixtureEvent => ({
  id: ulid(label), action, severity, actor: 'a***@glowstudio.co.ke', branch: IDS.branch, subject_type: null,
  context: {}, correlation_id: ulid(`COR${label}`), created_at: at(minutesAgo), review: null, can: { view: true }, views, ...extra,
});

const GENERAL: FixtureEvent[] = [
  event('EV', 'branch.profile_updated', 'high', 12, ['operational'], {
    id: EVENT, subject_type: 'MerchantBranch',
    context: { old_values: { name: 'Westlands', phone: '***678', closing_time: '19:00' }, new_values: { name: 'Westlands Studio', phone: '***678', closing_time: '21:00' }, reason: 'Extended evening hours approved by owner' },
    review: { flag: FLAG, status: 'under_review', flag_count: 1 },
  }),
  event('UA1', 'unauthorized_access', 'critical', 20, ['access'], { actor: 'k***@glowstudio.co.ke', subject_type: 'Invoice', context: { attempted: 'foreign_branch_invoice', response: '404' } }),
  event('UA2', 'unauthorized_access', 'critical', 21, ['access'], { actor: 'k***@glowstudio.co.ke', subject_type: 'Invoice', context: { attempted: 'foreign_branch_invoice', response: '404' } }),
  event('UA3', 'unauthorized_access', 'critical', 22, ['access'], { actor: 'k***@glowstudio.co.ke', subject_type: 'Invoice', context: { attempted: 'foreign_branch_invoice', response: '404' } }),
  event('SE', 'permission.override.denied_self_escalation', 'warning', 45, ['access'], { subject_type: 'MerchantUserPermissionOverride', context: { permission: 'audit.export', outcome: 'denied' } }),
  event('XB', 'personnel.sms.export_attempt_blocked', 'high', 60, ['access', 'privacy_preferred'], { actor: 'm***@glowstudio.co.ke', context: { route_shape: 'contacts.csv', response: '404' } }),
  event('CO', 'client_consent.opted_out', 'info', 75, ['privacy_preferred'], { subject_type: 'Client', context: { client_phone: '***678', channel: 'sms' } }),
  event('QT', 'queue_entry.transferred', 'notice', 90, ['operational', 'privacy_preferred'], { subject_type: 'QueueEntry', context: { previous_personnel: 'Brian O.', new_personnel: 'Amina W.', preferred_personnel_overridden: true, reason: 'Preferred stylist on leave' } }),
  event('DR', 'branch.day_reopened', 'warning', 120, ['operational'], { subject_type: 'BranchDayRecord', context: { previous_state: 'closed', new_state: 'open', reason: 'Late cash-up correction' } }),
  event('AP', 'appointment.rescheduled', 'info', 150, ['operational'], { subject_type: 'Appointment', context: { previous_start: '2026-07-15 14:00', new_start: '2026-07-15 16:30' } }),
];

const FINANCE: FixtureEvent[] = [
  event('FO', 'customer_payment.duplicate_override_approved', 'high', 30, [], { subject_type: 'PaymentRecordingGroup', context: { reference: '***7Q2X', maker: 'f***@glowstudio.co.ke', checker: 'n***@glowstudio.co.ke', override_reason: 'Two M-Pesa payments for split service', amount_minor: 450000, currency: 'KES' } }),
  event('FP', 'financial_period.reopened', 'critical', 95, [], { subject_type: 'FinancialPeriodLock', context: { period: '2026-06', previous_state: 'locked', new_state: 'reopened', approver: 'o***@glowstudio.co.ke' } }),
  event('FC', 'cash_up.approved', 'info', 200, [], { subject_type: 'CashUp', context: { expected_minor: 1250000, counted_minor: 1250000, currency: 'KES' } }),
];

const COMPENSATION: FixtureEvent[] = [
  event('CB', 'compensation.plan.backdated_change_approved', 'critical', 40, [], { subject_type: 'CompensationPlan', context: { salary_amount_minor: '[restricted]', effective_from: '2026-06-01', previous_state: 'submitted', new_state: 'approved' } }),
  event('CP', 'payout_run.marked_paid', 'critical', 130, [], { subject_type: 'PayoutRun', context: { gross_amount_minor: 3245000, currency: 'KES', external_reference: '***88QA' } }),
  event('CR', 'commission_rule.approved', 'high', 260, [], { subject_type: 'CommissionRule', context: { percentage_basis_points: 1000, applies_to: 'all_services' } }),
];

const FLAGS = [
  { id: FLAG, status: 'under_review', review_notes: 'Closing time extended without a branch-day note.', assigned_to: 'r***@glowstudio.co.ke', resolved_by: null, created_at: at(60 * 26), updated_at: at(60), audit_event: { id: EVENT, action: 'branch.profile_updated', severity: 'high', actor: 'a***@glowstudio.co.ke', subject_type: 'MerchantBranch', context: {}, occurred_at: at(12) }, can: { update_status: true, resolve_metadata: true } },
  { id: ulid('FLAG2'), status: 'open', review_notes: null, assigned_to: null, resolved_by: null, created_at: at(60 * 52), updated_at: at(60 * 52), audit_event: { id: ulid('UA1'), action: 'unauthorized_access', severity: 'critical', actor: 'k***@glowstudio.co.ke', subject_type: 'Invoice', context: {}, occurred_at: at(20) }, can: { update_status: true, resolve_metadata: true } },
  { id: ulid('FLAG3'), status: 'resolved', review_notes: 'Override matched two genuine split payments.', assigned_to: 'r***@glowstudio.co.ke', resolved_by: 'r***@glowstudio.co.ke', created_at: at(60 * 80), updated_at: at(60 * 70), audit_event: { id: ulid('FO'), action: 'customer_payment.duplicate_override_approved', severity: 'high', actor: 'f***@glowstudio.co.ke', subject_type: 'PaymentRecordingGroup', context: {}, occurred_at: at(30) }, can: { update_status: true, resolve_metadata: true } },
];

const exportRecord = (id: string, status: string, overrides: Record<string, unknown> = {}) => ({
  id, branch: { id: IDS.branch, name: 'Westlands Branch' }, status, reason: 'Quarterly branch review for the owner',
  scope: { domains: ['general', 'finance'], severities: ['high', 'critical'], has_date_from: true, has_date_to: true },
  row_count: status === 'ready' || status === 'expired' ? 128 : null, download_count: status === 'ready' ? 1 : 0,
  requested_at: at(300), generated_at: status === 'ready' ? at(295) : null, expires_at: status === 'ready' ? '2026-07-22T06:02:00.000Z' : null,
  first_downloaded_at: null, last_downloaded_at: status === 'ready' ? at(200) : null, failure_code: null, failure_message: null, created_at: at(300),
  can: { view: true, download: status === 'ready', revoke: status === 'ready' }, ...overrides,
});
const EXPORTS = [
  exportRecord(EXPORT, 'ready'),
  exportRecord(ulid('EXPPROC'), 'processing', { reason: 'Contact-protection review' }),
  exportRecord(ulid('EXPOLD'), 'expired', { reason: 'Previous quarter close', expires_at: '2026-07-01T00:00:00.000Z' }),
];

const INTEGRITY = {
  status: 'partial', checked_at: AUDIT_INSTANT_UTC, segment_limit: 1000, complete_history: false,
  coverage_from: '2026-06-02T07:00:00.000Z', coverage_to: at(2), branch_records_checked: 412,
  first_break: null, scheduled_verification_exposed: false,
};

const strip = (fixture: FixtureEvent) => {
  const { views, ...rest } = fixture;
  void views; // views drive the stub's server-side lens; they are not part of the API payload.
  return rest;
};
const json = (route: Route, body: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });

function page(list: unknown[], perPage: number, total = list.length) {
  return { data: list.slice(0, perPage), meta: { current_page: 1, last_page: Math.max(1, Math.ceil(total / perPage)), total, per_page: perPage } };
}

async function stubAuditApi(p: Page, overrides: { integrity?: unknown; flags?: unknown[] } = {}): Promise<void> {
  await p.route('**/api/v1/audit-logs**', (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace(/^\/api\/v1/, '');
    const q = url.searchParams;
    const perPage = Number(q.get('per_page') ?? 25);
    const detail = path.match(/^\/audit-logs\/([0-9A-Za-z]{26})$/);
    if (detail) {
      const found = [...GENERAL, ...FINANCE, ...COMPENSATION].find((e) => e.id === detail[1]);
      return found ? json(route, { data: strip(found) }) : json(route, { error: { code: 'not_found', message: 'Not found.', fields: {}, meta: {} } }, 404);
    }
    let list = path.endsWith('/finance') ? FINANCE : path.endsWith('/compensation') ? COMPENSATION : GENERAL;
    if (q.get('view')) list = list.filter((e) => e.views.includes(String(q.get('view'))));
    if (q.get('severity')) list = list.filter((e) => e.severity === q.get('severity'));
    if (q.get('flagged') === 'flagged') list = list.filter((e) => e.review !== null);
    if (q.get('flagged') === 'unflagged') list = list.filter((e) => e.review === null);
    if (q.get('correlation_id')) list = list.filter((e) => e.correlation_id === q.get('correlation_id'));
    return json(route, page(list.map(strip), perPage));
  });
  await p.route('**/api/v1/audit-flagged-events**', (route) => {
    const url = new URL(route.request().url());
    const flags = overrides.flags ?? FLAGS;
    const detail = url.pathname.match(/audit-flagged-events\/([0-9A-Za-z]{26})$/);
    if (route.request().method() === 'POST') return json(route, { data: { ...FLAGS[1], id: ulid('FLAGNEW'), status: 'open' } }, 201);
    if (detail) return json(route, { data: (flags as typeof FLAGS).find((f) => f.id === detail[1]) ?? FLAGS[0] });
    const status = url.searchParams.get('status');
    const list = (flags as typeof FLAGS).filter((f) => !status || f.status === status);
    return json(route, page(list, Number(url.searchParams.get('per_page') ?? 25)));
  });
  await p.route('**/api/v1/audit-exports**', (route) => {
    const url = new URL(route.request().url());
    if (route.request().method() === 'POST' && url.pathname.endsWith('/audit-exports')) return json(route, { data: exportRecord(ulid('EXPNEW'), 'queued') }, 201);
    const detail = url.pathname.match(/audit-exports\/([0-9A-Za-z]{26})$/);
    if (detail) return json(route, { data: EXPORTS.find((e) => e.id === detail[1]) ?? EXPORTS[0] });
    const status = url.searchParams.get('status');
    const list = EXPORTS.filter((e) => !status || e.status === status);
    return json(route, page(list, Number(url.searchParams.get('per_page') ?? 25)));
  });
  await p.route('**/api/v1/audit-integrity', (route) => json(route, { data: overrides.integrity ?? INTEGRITY }));
  await p.route('**/api/v1/auth/account-contexts', (route) => json(route, { data: [{
    context_id: 'ctx-audit', account_key: 'merchant_audit', display_name: 'Audit', target_host: 'audit.servana.ke', default_route: '/dashboard',
    requires_mfa: false, merchant_id: 'm1', merchant_name: 'Glow Studio Westlands', branch_id: IDS.branch, branch_name: 'Westlands Branch',
    role_label: 'Audit', is_current: true,
  }] }));
}

async function waitForPreview(p: Page): Promise<void> {
  let lastError: unknown;
  for (let attempt = 0; attempt < 20; attempt += 1) {
    try {
      const response = await p.request.get('/', { timeout: 1_000 });
      if (response.ok()) return;
      lastError = new Error(`Preview readiness returned HTTP ${response.status()}`);
    } catch (error: unknown) { lastError = error; }
    await p.waitForTimeout(250);
  }
  throw lastError;
}

async function openAudit(p: Page, entry: Entry, opts: { theme?: 'light' | 'dark'; permissions?: string[]; integrity?: unknown } = {}) {
  const errors: string[] = [];
  const failed: string[] = [];
  const requests: string[] = [];
  p.on('pageerror', (error) => errors.push(error.message));
  p.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
  p.on('requestfailed', (request) => failed.push(request.url()));
  p.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
  await prepare(p, auditScreen(entry, opts.permissions), opts.theme ? { theme: opts.theme } : undefined);
  await stubAuditApi(p, { integrity: opts.integrity });
  await waitForPreview(p);
  await p.goto(entry.path);
  await expect(p.locator(entry.ready).first()).toBeVisible();
  return { errors, failed, requests };
}

async function settle(p: Page): Promise<void> {
  await p.evaluate(async () => {
    await document.fonts.ready;
    window.scrollTo(0, 0);
    document.querySelectorAll<HTMLElement>('main, [data-testid="sidebar-primary-nav"]').forEach((element) => element.scrollTo(0, 0));
    await new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done())));
  });
}

async function axeSeriousOrCritical(p: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page: p }).analyze();
  return results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${v.id}: ${v.nodes.length}`);
}

// --- all fourteen live pages -----------------------------------------------------------------

test.describe('all fourteen implemented Audit pages', () => {
  for (const entry of PAGES) {
    test(`${entry.screen} renders its canonical audit.servana.ke route from real reads`, async ({ page: p }) => {
      await p.setViewportSize({ width: 1440, height: 900 });
      const health = await openAudit(p, entry);
      await expect(p.getByTestId('public-not-found')).toHaveCount(0);
      if (entry.api) expect(health.requests.some((url) => url.includes(entry.api as string)), `${entry.screen} never requested ${entry.api}`).toBe(true);
      expect(health.errors, `${entry.screen} browser errors`).toEqual([]);
      expect(health.failed, `${entry.screen} failed requests`).toEqual([]);
      expect(await axeSeriousOrCritical(p), `${entry.screen} light axe`).toEqual([]);
      await settle(p);
      await writeEvidenceScreenshot(p, join(SHOTS, `desktop-light-${entry.screen}.png`), { animations: 'disabled' });
    });
  }

  for (const entry of SUPPORTING) {
    test(`${entry.screen} supporting record renders from its ULID deep link`, async ({ page: p }) => {
      await p.setViewportSize({ width: 1440, height: 900 });
      const health = await openAudit(p, entry);
      expect(health.errors).toEqual([]);
      expect(await axeSeriousOrCritical(p)).toEqual([]);
      await settle(p);
      await writeEvidenceScreenshot(p, join(SHOTS, `desktop-light-${entry.screen}.png`), { animations: 'disabled' });
    });
  }

  test('all pages intentionally render dark with zero serious or critical axe violations', async ({ page: p }) => {
    test.setTimeout(240_000);
    await p.setViewportSize({ width: 1440, height: 900 });
    for (const entry of [...PAGES, ...SUPPORTING]) {
      await openAudit(p, entry, { theme: 'dark' });
      await expect(p.locator('html')).toHaveClass(/dark/);
      await assertNoHorizontalScroll(p);
      expect(await axeSeriousOrCritical(p), `${entry.screen} dark axe`).toEqual([]);
      await settle(p);
      await writeEvidenceScreenshot(p, join(SHOTS, `desktop-dark-${entry.screen}.png`), { animations: 'disabled' });
    }
  });
});

// --- gate, host, role and session boundaries --------------------------------------------------

test.describe('Notifications gate and entry safety', () => {
  test('Notifications stays visible, disabled and names Phase 21N and External Gate W', async ({ page: p }) => {
    await p.setViewportSize({ width: 1440, height: 900 });
    await openAudit(p, PAGES[0]);
    const item = p.getByTestId('sidebar-primary-nav').getByTestId('nav-stacked-gated-merchant_audit.notifications');
    await item.scrollIntoViewIfNeeded();
    await expect(item).toBeVisible();
    await expect(item).toHaveAttribute('aria-disabled', 'true');
    await expect(item).toContainText(/Phase 21N/i);
    await expect(item).toContainText(/External Gate W/i);
    await expect(item).not.toHaveAttribute('href');
  });

  test('Notifications has no route, component, unread count or network runtime', async ({ page: p }) => {
    const requests: string[] = [];
    p.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
    await prepare(p, auditScreen(PAGES[0]));
    await stubAuditApi(p);
    await p.goto('/notifications');
    await expect(p.getByTestId('public-not-found')).toBeVisible();
    await expect(p.getByText(/unread|mark all read|notification preferences/i)).toHaveCount(0);
    expect(requests.some((url) => /notifications/i.test(url))).toBe(false);
    await settle(p);
    await writeEvidenceScreenshot(p, join(SHOTS, 'gated-notifications.png'), { animations: 'disabled' });
  });

  test('a wrong account role cannot render the Audit shell', async ({ page: p }) => {
    await prepare(p, { ...auditScreen(PAGES[0]), role: 'merchant_finance', bootstrap: { permissions: ['invoice.view'], hostAccountKey: 'merchant_audit' } });
    await stubAuditApi(p);
    await p.goto('/dashboard');
    await expect(p.getByTestId('audit-dashboard')).toHaveCount(0);
    await expect(p.getByRole('heading', { name: 'You do not have access to this page' })).toBeVisible();
  });

  test('a mismatched host context fails closed before an Audit page mounts', async ({ page: p }) => {
    const screen = auditScreen(PAGES[2]);
    await prepare(p, { ...screen, bootstrap: { ...screen.bootstrap, hostAccountKey: 'merchant_finance' } });
    await stubAuditApi(p);
    await p.goto('/audit');
    await expect(p.getByTestId('audit-log-page')).toHaveCount(0);
  });

  test('an inactive session is redirected to Magic Link sign-in without evidence', async ({ page: p }) => {
    const screen = auditScreen(PAGES[2]);
    await prepare(p, { ...screen, bootstrap: { ...screen.bootstrap, authenticated: false } });
    await stubAuditApi(p);
    await p.goto('/audit');
    await expect(p.getByTestId('audit-event-row')).toHaveCount(0);
    await expect(p).toHaveURL(/\/auth\/login/);
  });

  test('legacy Phase 19 paths land on their canonical pages with filters intact', async ({ page: p }) => {
    await openAudit(p, PAGES[0]);
    await p.goto('/audit/events?severity=critical');
    await expect(p).toHaveURL(/\/audit\?severity=critical$/);
    await expect(p.getByTestId('audit-filter-severity-critical')).toHaveAttribute('aria-pressed', 'true');
    await p.goto(`/audit/flagged/${FLAG}`);
    await expect(p).toHaveURL(new RegExp(`/audit/flags/${FLAG}$`));
    await p.goto(`/audit/exports/${EXPORT}`);
    await expect(p).toHaveURL(new RegExp(`/exports/${EXPORT}$`));
  });
});

// --- authority, masking and integrity truth ---------------------------------------------------

test.describe('read-only authority, masking and integrity truth', () => {
  test('no source-mutation control exists on any Audit page', async ({ page: p }) => {
    test.setTimeout(180_000);
    const forbidden = /^(edit|delete|save|validate|approve|reject|refund|reverse|reissue|lock period|reopen period|mark paid|reassign|escalate|unmask|repair|re-?hash|export contacts)\b/i;
    for (const entry of [...PAGES, ...SUPPORTING]) {
      await openAudit(p, entry);
      await expect(p.getByRole('button', { name: forbidden }), entry.screen).toHaveCount(0);
      await expect(p.getByRole('link', { name: forbidden }), entry.screen).toHaveCount(0);
    }
  });

  test('evidence never renders hashes, raw JSON, signed URLs or storage paths', async ({ page: p }) => {
    for (const entry of [PAGES[2], PAGES[3], PAGES[10], SUPPORTING[1]]) {
      await openAudit(p, entry);
      const html = await p.content();
      expect(html).not.toMatch(/[0-9a-f]{64}/);
      expect(html).not.toContain('previous_hash');
      expect(html).not.toContain('{"old_values"');
      expect(html).not.toMatch(/signature=|\/storage\//);
    }
  });

  test('event detail is a structured before/after comparison with masked values marked', async ({ page: p }) => {
    await p.setViewportSize({ width: 1440, height: 900 });
    await openAudit(p, PAGES[3]);
    const diff = p.getByTestId('audit-diff');
    await expect(diff).toContainText('Westlands Studio');
    await expect(diff).toContainText('21:00');
    await expect(p.getByTestId('audit-review-badge').first()).toHaveAttribute('data-review', 'under_review');
    await expect(p.getByTestId('audit-severity').first()).toHaveAttribute('data-severity', 'high');
    await expect(p.locator('main')).toContainText('Masked:');
  });

  test('an unknown or foreign event id is unavailable without enumeration', async ({ page: p }) => {
    await openAudit(p, PAGES[0]);
    await p.goto(`/audit/${ulid('NOSUCH')}`);
    await expect(p.getByTestId('audit-detail-unavailable')).toBeVisible();
    await expect(p.getByTestId('audit-detail-action')).toHaveCount(0);
  });

  test('a partial chain check is never presented as verified', async ({ page: p }) => {
    await openAudit(p, PAGES[12]);
    await expect(p.getByTestId('audit-integrity-status')).toContainText('Recent records verified');
    await expect(p.getByTestId('audit-integrity-status')).not.toHaveText(/^Integrity:\s*Verified$/);
    await expect(p.locator('main')).toContainText('not stored for this account to read');
  });

  test('a break outside the branch is reported without naming the record', async ({ page: p }) => {
    await p.setViewportSize({ width: 1440, height: 900 });
    await openAudit(p, PAGES[12], { integrity: { ...INTEGRITY, status: 'broken', complete_history: true, first_break: { category: 'hash_mismatch', within_branch_scope: false, event: null, occurred_at: null } } });
    await expect(p.getByTestId('audit-integrity-break')).toContainText('another branch');
    await expect(p.getByTestId('audit-integrity-open-event')).toHaveCount(0);
    await settle(p);
    await writeEvidenceScreenshot(p, join(SHOTS, 'integrity-broken-outside-branch.png'), { animations: 'disabled' });
  });

  test('a reader without export or flag permissions sees neither control and cannot enter exports', async ({ page: p }) => {
    await openAudit(p, PAGES[2], { permissions: ['audit.branch_events.view'] });
    await expect(p.getByTestId('audit-event-flag')).toHaveCount(0);
    await expect(p.getByRole('link', { name: 'Request masked export' })).toHaveCount(0);
    await expect(p.getByTestId('sidebar-primary-nav').getByRole('link', { name: 'Audit Exports' })).toHaveCount(0);
    await p.goto('/exports');
    await expect(p.getByTestId('audit-exports-page')).toHaveCount(0);
  });

  test('the dashboard reports an unreadable slice as not permitted, never zero', async ({ page: p }) => {
    await openAudit(p, PAGES[0], { permissions: ['audit.branch_events.view'] });
    await expect(p.getByTestId('audit-dashboard-slice-finance')).toContainText('Not permitted');
    await expect(p.getByTestId('audit-dashboard-slice-compensation')).toContainText('Not permitted');
  });

  test('access view clusters the repeated attempts while every event stays listed', async ({ page: p }) => {
    await p.setViewportSize({ width: 1280, height: 900 });
    await openAudit(p, PAGES[8]);
    const group = p.getByTestId('audit-repeat-group').first();
    await expect(group).toContainText('3');
    await group.locator('summary').click();
    await expect(group.getByTestId('audit-event-row')).toHaveCount(3);
    await settle(p);
    await writeEvidenceScreenshot(p, join(SHOTS, 'access-repeated-attempts.png'), { animations: 'disabled' });
  });
});

// --- review and export workflows -------------------------------------------------------------

test.describe('flag and export workflows', () => {
  test('flagging an event sends review metadata only and confirms', async ({ page: p }) => {
    await p.setViewportSize({ width: 1280, height: 900 });
    const posts: Array<{ url: string; body: unknown }> = [];
    p.on('request', (r) => { if (r.method() === 'POST') posts.push({ url: r.url(), body: r.postDataJSON() }); });
    await openAudit(p, PAGES[5]);
    await p.getByTestId('audit-event-flag').first().click();
    await expect(p.getByTestId('audit-flag-dialog')).toBeVisible();
    await p.locator('#audit-flag-note').fill('Day reopened after cash-up without a note.');
    await settle(p);
    await writeEvidenceScreenshot(p, join(SHOTS, 'flag-dialog.png'), { animations: 'disabled' });
    await p.getByTestId('audit-flag-confirm').click();
    await expect(p.getByTestId('audit-flag-success')).toBeVisible();
    expect(posts).toHaveLength(1);
    expect(posts[0].url).toContain('/api/v1/audit-flagged-events');
    expect(Object.keys(posts[0].body as object).sort()).toEqual(['audit_log', 'note']);
  });

  test('the export request requires a reason, previews masking and sends the scoped request', async ({ page: p }) => {
    await p.setViewportSize({ width: 1440, height: 1000 });
    const posts: unknown[] = [];
    p.on('request', (r) => { if (r.method() === 'POST' && r.url().endsWith('/api/v1/audit-exports')) posts.push(r.postDataJSON()); });
    await openAudit(p, PAGES[10]);
    await p.getByTestId('audit-export-open').click();
    await expect(p.getByText(/Give a reason of at least three characters/)).toBeVisible();
    expect(posts).toHaveLength(0);
    await expect(p.getByTestId('audit-export-preview')).toContainText('[restricted]');
    await settle(p);
    await writeEvidenceScreenshot(p, join(SHOTS, 'export-form-validation.png'), { animations: 'disabled' });
    await p.locator('#audit-export-reason').fill('Quarterly branch review for the owner');
    await p.getByTestId('audit-export-open').click();
    await expect(p.getByTestId('audit-export-created')).toBeVisible();
    expect(posts).toHaveLength(1);
    expect(posts[0]).toMatchObject({ branch: IDS.branch, reason: 'Quarterly branch review for the owner' });
  });

  test('a ready export downloads through an on-demand link that is never rendered', async ({ page: p }) => {
    await openAudit(p, SUPPORTING[1]);
    let issued = false;
    await p.route(`**/api/v1/audit-exports/${EXPORT}/download-link`, (route) => { issued = true; return json(route, { data: { url: 'https://signed.example/audit.csv?signature=SECRET', expires_at: AUDIT_INSTANT_UTC } }); });
    await p.evaluate(() => { window.open = () => null; });
    await p.getByTestId('audit-export-download').click();
    await expect.poll(() => issued).toBe(true);
    expect(await p.content()).not.toContain('signature=SECRET');
  });
});

// --- responsive, theme, motion, keyboard, targets ----------------------------------------------

test.describe('responsive, theme, motion, keyboard and accessibility', () => {
  for (const width of [360, 767, 768, 1024, 1025, 1280, 1440]) {
    test(`all implemented pages avoid horizontal overflow at ${width}px`, async ({ page: p }) => {
      test.setTimeout(240_000);
      await p.setViewportSize({ width, height: 900 });
      for (const entry of [...PAGES, ...SUPPORTING]) {
        await openAudit(p, entry);
        await assertNoHorizontalScroll(p);
      }
      await openAudit(p, PAGES[0]);
      await settle(p);
      await writeEvidenceScreenshot(p, join(SHOTS, `responsive-dashboard-${width}.png`), { animations: 'disabled' });
    });
  }

  test('captures mobile and tablet transformations of the complex archetypes', async ({ page: p }) => {
    test.setTimeout(180_000);
    await p.setViewportSize({ width: 360, height: 780 });
    for (const entry of [PAGES[0], PAGES[2], PAGES[3], PAGES[4], PAGES[10], PAGES[11], PAGES[12], PAGES[13]]) {
      await openAudit(p, entry);
      await assertNoHorizontalScroll(p);
      await settle(p);
      await writeEvidenceScreenshot(p, join(SHOTS, `mobile-${entry.screen}.png`), { animations: 'disabled', fullPage: true });
    }
    await p.setViewportSize({ width: 768, height: 1024 });
    for (const entry of [PAGES[0], PAGES[3], PAGES[6], PAGES[11]]) {
      await openAudit(p, entry);
      await assertNoHorizontalScroll(p);
      await settle(p);
      await writeEvidenceScreenshot(p, join(SHOTS, `tablet-${entry.screen}.png`), { animations: 'disabled', fullPage: true });
    }
  });

  test('representative pages stay usable at a 200% equivalent with the footer reserved', async ({ page: p }) => {
    test.setTimeout(180_000);
    await p.setViewportSize({ width: 640, height: 450 });
    for (const entry of [PAGES[0], PAGES[2], PAGES[3], PAGES[4], PAGES[10], PAGES[12], PAGES[13]]) {
      await openAudit(p, entry);
      await assertNoHorizontalScroll(p);
      const [footer, reserve] = await Promise.all([
        p.getByTestId('sv-fixed-footer').boundingBox(),
        p.locator('.sv-footer-reserve').evaluate((root) => Number.parseFloat(getComputedStyle(root).paddingBottom)),
      ]);
      expect(footer && reserve >= footer.height, `${entry.screen} footer reserve`).toBe(true);
      await settle(p);
      await writeEvidenceScreenshot(p, join(SHOTS, `zoom-200-${entry.screen}.png`), { animations: 'disabled', fullPage: true });
    }
  });

  test('fresh light is the default and a deliberate dark choice persists after reload', async ({ page: p }) => {
    await openAudit(p, PAGES[0]);
    await expect(p.locator('html')).not.toHaveClass(/dark/);
    await p.getByTestId('theme-toggle').first().click();
    await expect(p.locator('html')).toHaveClass(/dark/);
    await p.reload();
    await expect(p.locator('html')).toHaveClass(/dark/);
  });

  test('reduced motion removes transitions on evidence cards', async ({ page: p }) => {
    await p.emulateMedia({ reducedMotion: 'reduce' });
    await openAudit(p, PAGES[2]);
    const longest = await p.locator('[data-testid="audit-event-row"]').evaluateAll((nodes) => Math.max(...nodes.map((node) => Number.parseFloat(getComputedStyle(node).transitionDuration) || 0)));
    expect(longest).toBeLessThanOrEqual(0.00001);
  });

  test('mobile navigation traps focus, closes with Escape and restores the trigger', async ({ page: p }) => {
    await p.setViewportSize({ width: 360, height: 780 });
    await openAudit(p, PAGES[0]);
    const trigger = p.getByTestId('nav-drawer-trigger').first();
    await trigger.focus();
    await p.keyboard.press('Enter');
    await expect(p.getByTestId('nav-drawer-close')).toBeVisible();
    await settle(p);
    await writeEvidenceScreenshot(p, join(SHOTS, 'mobile-navigation.png'), { animations: 'disabled' });
    await p.keyboard.press('Escape');
    await expect(p.getByTestId('nav-drawer-close')).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });

  test('the flag dialog traps focus and returns it on Escape', async ({ page: p }) => {
    await openAudit(p, PAGES[2]);
    const open = p.getByTestId('audit-event-flag').first();
    await open.focus();
    await p.keyboard.press('Enter');
    await expect(p.getByTestId('audit-flag-dialog')).toBeVisible();
    await p.keyboard.press('Escape');
    await expect(p.getByTestId('audit-flag-dialog')).toHaveCount(0);
    await expect(open).toBeFocused();
  });

  test('interactive controls on every implemented page meet the 44px target floor', async ({ page: p }) => {
    test.setTimeout(240_000);
    await p.setViewportSize({ width: 360, height: 780 });
    for (const entry of [...PAGES, ...SUPPORTING]) {
      await openAudit(p, entry);
      const undersized = await p.locator('main a, main button, main summary, main input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), main select, main textarea')
        .evaluateAll((nodes) => nodes
          .filter((node) => {
            const style = getComputedStyle(node);
            const box = node.getBoundingClientRect();
            return style.display !== 'none' && style.visibility !== 'hidden' && !node.hasAttribute('disabled') && box.width > 0 && box.height > 0;
          })
          .map((node) => ({ label: node.getAttribute('aria-label') ?? node.textContent?.trim().slice(0, 60) ?? node.tagName, height: Math.round(node.getBoundingClientRect().height) }))
          .filter((control) => control.height < 44));
      expect(undersized, `${entry.screen} has undersized interactive controls`).toEqual([]);
    }
  });
});

test('writes the deterministic UI-15 screenshot evidence index', async () => {
  const files = readdirSync(SHOTS).filter((file) => file.endsWith('.png')).sort();
  const byScreen = new Map<string, Entry>([...PAGES, ...SUPPORTING].map((entry) => [entry.screen, entry]));
  const captures = files.map((file) => {
    const key = file
      .replace(/^desktop-(?:light|dark)-/, '').replace(/^mobile-/, '').replace(/^tablet-/, '')
      .replace(/^zoom-200-/, '').replace(/\.png$/, '');
    const entry = byScreen.get(key);
    const viewport = file.startsWith('mobile-') ? '360x780'
      : file.startsWith('tablet-') ? '768x1024'
        : file.startsWith('zoom-200-') ? '640x450 (200% equivalent)'
          : file.startsWith('responsive-dashboard-') ? `${file.match(/(\d+)\.png$/)?.[1]}x900`
            : file === 'export-form-validation.png' ? '1440x1000'
              : file === 'access-repeated-attempts.png' || file === 'flag-dialog.png' ? '1280x900'
                : '1440x900';
    return {
      route: entry?.path ?? (file === 'gated-notifications.png' ? '/notifications' : null),
      account_fixture: 'merchant_audit / deterministic branch-scoped masked synthetic fixture',
      viewport,
      theme: file.includes('-dark-') ? 'dark' : 'light',
      state: file.includes('gated') ? 'disabled_by_gate' : file.includes('validation') ? 'validation' : file.includes('broken') ? 'integrity_broken' : file.includes('dialog') ? 'dialog' : 'settled_populated',
      purpose: file.replace(/\.png$/, '').replaceAll('-', ' '),
      file: `screenshots/${file}`,
      bytes: statSync(join(SHOTS, file)).size,
      sha256: createHash('sha256').update(readFileSync(join(SHOTS, file))).digest('hex'),
      source_build: 'Vite production preview from the UI-15 working tree',
      source_commit: 'pre-commit UI-15 tree; the committed tree is recorded by the Codex release',
    };
  });
  await writeEvidenceFile(resolve(SHOTS, '..', 'screenshot-index.json'), `${JSON.stringify({
    schema: 'servana.ui15.screenshot-index.v1', phase: 'UI-15', account: 'merchant_audit',
    host: 'audit.servana.ke', data_provenance: 'synthetic branch-scoped data; masked actors, contacts and references; no real person, credential, token, hash or signed URL',
    captures,
    totals: {
      captures: files.length,
      desktop_light_pages: files.filter((file) => file.startsWith('desktop-light-')).length,
      desktop_dark_pages: files.filter((file) => file.startsWith('desktop-dark-')).length,
    },
  }, null, 2)}\n`);
});
