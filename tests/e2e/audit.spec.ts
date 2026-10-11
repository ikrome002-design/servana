import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 19 E2E — the Audit role's read + flagged-review + export surfaces, and the
 | Finance role's finance-audit surface (Plan §19.3, §70, §80). The SPA preview has no
 | backend; `/me` + `/api/v1` are stubbed to drive the REAL frontend. Genuine
 | branch-scoping, masking, immutability, download-accounting, foreign-tenant
 | non-enumeration, and MFA/step-up enforcement are proven by the backend Feature suite
 | (tests/Feature/Audit/*, tests/Feature/Auth/Permission*); Linux CI is the authoritative
 | browser gate. These specs prove the frontend behaviour, gating, and accessibility.
 */

const AUDIT_PERMS = [
  'audit.branch_events.view',
  'audit.finance.view',
  'audit.compensation.view',
  'audit.export',
  'audit.flagged_event.create',
  'audit.flagged_event.update_status',
  'audit.flagged_event.resolve_metadata',
];

// Phase UI-15: record ids are real ULID-shaped values; the Audit router only accepts ULIDs.
const EVENT = '01JB0000000000000000E00001';
const FLAG = '01JB0000000000000000F00002';
const EXPORT = '01JB0000000000000000X00003';

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function err(status: number, code: string) {
  return { status, contentType: 'application/json', body: JSON.stringify({ error: { code, message: code } }) };
}

async function stubMe(
  page: Page,
  role: string,
  permissions: string[],
  opts: {
 branchIds?: string[]; mfa?: Record<string, unknown> } = {},
): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, role, false);

  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'u@salon.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
        merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: { id: 'mm1', role, status: 'active' }, memberships: [{ id: 'mm1', role, status: 'active' }],
        account_keys: [accountKeyForRole(role, false)],
        permissions, setup: { required: false, current_step: null, completed_at: null }, branch_ids: opts.branchIds ?? ['b1'],
        mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0, ...(opts.mfa ?? {}) },
      },
    })),
  );
}

function auditEvent(overrides: Record<string, unknown> = {}) {
  return {
    id: EVENT, action: 'invoice.created', severity: 'info', actor: 'j***@salon.co.ke', branch: 'b1',
    subject_type: 'Invoice', context: { amount: '***' }, correlation_id: 'corr-1', created_at: '2026-07-05T09:00:00Z',
    can: { view: true }, ...overrides,
  };
}
function flagged(status: string, can: Record<string, boolean>, overrides: Record<string, unknown> = {}) {
  return {
    id: FLAG, status, review_notes: null, assigned_to: null, resolved_by: null,
    created_at: '2026-07-05T09:00:00Z', updated_at: '2026-07-05T09:00:00Z',
    audit_event: { id: EVENT, action: 'invoice.created', severity: 'info', actor: 'j***@salon.co.ke', subject_type: 'Invoice', context: {}, occurred_at: '2026-07-05T09:00:00Z' },
    can, ...overrides,
  };
}
function auditExport(overrides: Record<string, unknown> = {}) {
  return {
    id: EXPORT, branch: { id: 'b1', name: 'Westlands' }, status: 'ready', reason: 'quarterly review',
    scope: { domains: ['finance'], severities: [], has_date_from: false, has_date_to: false },
    row_count: 12, download_count: 0, requested_at: '2026-07-05T09:00:00Z', generated_at: '2026-07-05T09:01:00Z',
    expires_at: '2026-07-12T09:00:00Z', first_downloaded_at: null, last_downloaded_at: null,
    failure_code: null, failure_message: null, created_at: '2026-07-05T09:00:00Z',
    can: { view: true, download: true, revoke: true }, ...overrides,
  };
}

/* ---------------------------------------------------------------- 6.1 reads */

test.describe('Audit event reads', () => {
  test('shows branch-scoped masked events and never leaks hashes/paths/ids', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route('**/api/v1/audit-logs?**', (r) => r.fulfill(ok({ data: [auditEvent()], meta: { current_page: 1, last_page: 1, total: 1 } })));

    await page.goto('/audit');
    await expect(page.getByTestId('audit-event-row').first()).toBeVisible();
    await expect(page.getByTestId('audit-severity').first()).toHaveAttribute('data-severity', 'info');
    expect(await page.getByText('j***@salon.co.ke').count()).toBeGreaterThan(0);

    const html = await page.content();
    expect(html).not.toContain('previous_hash');
    expect(html).not.toContain('/storage/');
    expect(html).not.toMatch(/[0-9a-f]{64}/); // no full hash
  });

  test('opens an immutable event detail with no source-mutation controls', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route(`**/api/v1/audit-logs/${EVENT}`, (r) => r.fulfill(ok({ data: auditEvent() })));

    await page.goto(`/audit/${EVENT}`);
    await expect(page.getByTestId('audit-detail-action')).toHaveText('Invoice — created');
    await expect(page.getByText('invoice.created').first()).toBeVisible();
    // No edit/delete/mutation controls on the immutable record.
    await expect(page.getByRole('button', { name: /save|edit|delete|update/i })).toHaveCount(0);
  });

  test('renders a graceful not-found state for a foreign/unknown ulid (backend 404, no enumeration)', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    const unknown = '01JB0000000000000000Z99999';
    await page.route(`**/api/v1/audit-logs/${unknown}`, (r) => r.fulfill(err(404, 'not_found')));

    await page.goto(`/audit/${unknown}`);
    await expect(page.getByTestId('audit-detail-unavailable')).toBeVisible();
    await expect(page.getByTestId('audit-detail-action')).toHaveCount(0);
  });
});

/* ------------------------------------------------------ 6.2 flagged workflow */

test.describe('Flagged-event review workflow', () => {
  test('shows only start-review for an open event with the update_status capability', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route(`**/api/v1/audit-flagged-events/${FLAG}`, (r) => r.fulfill(ok({ data: flagged('open', { update_status: true }) })));

    await page.goto(`/audit/flags/${FLAG}`);
    await expect(page.getByTestId('flagged-start-review')).toBeVisible();
    await expect(page.getByTestId('flagged-resolve')).toHaveCount(0);
    await expect(page.getByTestId('flagged-source')).toBeVisible(); // source shown read-only
  });

  test('resolves an under-review event and enforces required notes', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route(`**/api/v1/audit-flagged-events/${FLAG}`, (r) => r.fulfill(ok({ data: flagged('under_review', { resolve_metadata: true, update_status: true }) })));
    let resolved = false;
    await page.route(`**/api/v1/audit-flagged-events/${FLAG}/resolve`, (r) => { resolved = true; return r.fulfill(ok({ data: flagged('resolved', {}) })); });

    await page.goto(`/audit/flags/${FLAG}`);
    await page.getByTestId('flagged-resolve').click();
    // Confirm dialog: the confirm button is disabled until notes ≥ 3 chars.
    await expect(page.getByTestId('flagged-confirm')).toBeDisabled();
    await page.locator('#flagged-review-notes').fill('resolved after review');
    await expect(page.getByTestId('flagged-confirm')).toBeEnabled();
    await page.getByTestId('flagged-confirm').click();
    await expect.poll(() => resolved).toBe(true);
  });

  test('reopens a resolved event', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route(`**/api/v1/audit-flagged-events/${FLAG}`, (r) => r.fulfill(ok({ data: flagged('resolved', { update_status: true }) })));
    let reopened = false;
    await page.route(`**/api/v1/audit-flagged-events/${FLAG}/reopen`, (r) => { reopened = true; return r.fulfill(ok({ data: flagged('reopened', { update_status: true }) })); });

    await page.goto(`/audit/flags/${FLAG}`);
    await page.getByTestId('flagged-reopen').click();
    await expect.poll(() => reopened).toBe(true);
  });

  test('displays an invalid-state-transition error without weakening the source', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route(`**/api/v1/audit-flagged-events/${FLAG}`, (r) => r.fulfill(ok({ data: flagged('open', { update_status: true }) })));
    await page.route(`**/api/v1/audit-flagged-events/${FLAG}/start-review`, (r) => r.fulfill(err(422, 'invalid_state_transition')));

    await page.goto(`/audit/flags/${FLAG}`);
    await page.getByTestId('flagged-start-review').click();
    await expect(page.getByTestId('flagged-action-error')).toBeVisible();
  });
});

/* ----------------------------------------------------------- 6.3 finance audit */

test.describe('Finance audit surfaces', () => {
  test('the Audit role reads the finance segment (audit.finance.view)', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    let hit = false;
    await page.route('**/api/v1/audit-logs/finance?**', (r) => { hit = true; return r.fulfill(ok({ data: [auditEvent({ action: 'invoice.voided' })] })); });

    await page.goto('/audit/finance');
    await expect(page.getByTestId('audit-event-row').first()).toBeVisible();
    expect(hit).toBe(true);
  });

  test('the Finance role reads its own finance-audit surface (finance.audit.view) with an MFA note', async ({ page }) => {
    await stubMe(page, 'finance', ['finance.audit.view'], { mfa: { required: true, confirmed: true, verified: true } });
    await page.route('**/api/v1/audit-logs/finance?**', (r) => r.fulfill(ok({ data: [auditEvent({ action: 'invoice.voided' })] })));

    await page.goto('/finance/audit');
    await expect(page.getByTestId('audit-domain-mfa-note')).toBeVisible();
    await expect(page.getByTestId('audit-domain-row').first()).toBeVisible();
  });

  test('a Finance user denied by the backend (missing MFA) sees the error boundary, not data', async ({ page }) => {
    await stubMe(page, 'finance', ['finance.audit.view']);
    await page.route('**/api/v1/audit-logs/finance?**', (r) => r.fulfill(err(403, 'mfa_challenge_required')));

    await page.goto('/finance/audit');
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByTestId('audit-domain-row')).toHaveCount(0);
  });
});

/* ------------------------------------------------------ 6.4 compensation audit */

test.describe('Compensation audit', () => {
  test('loads the real route and shows an honest empty state (no fabricated data)', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route('**/api/v1/audit-logs/compensation?**', (r) => r.fulfill(ok({ data: [] })));

    await page.goto('/audit/compensation');
    await expect(page.getByText(/No compensation events match/i)).toBeVisible();
    await expect(page.getByTestId('audit-event-row')).toHaveCount(0);
  });
});

/* ---------------------------------------------------------- 6.5 audit export */
// Phase UI-15: Audit Exports (`/exports`) is an inline request workspace; the record lives at
// `/exports/:exportUlid`. The submit button keeps the `audit-export-open` hook.

test.describe('Audit export workflow', () => {
  test('requires a reason and an assigned branch, then requests (fresh step-up server-enforced)', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    let requested = false;
    await page.route('**/api/v1/audit-exports**', (r) => {
      if (r.request().method() === 'POST') { requested = true; return r.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: auditExport({ status: 'queued' }) }) }); }
      return r.fulfill(ok({ data: [] }));
    });

    await page.goto('/exports');
    // Submitting without a reason is refused in the browser and sends nothing.
    await page.getByTestId('audit-export-open').click();
    await expect(page.getByText(/Give a reason of at least three characters/)).toBeVisible();
    expect(requested).toBe(false);
    await page.locator('#audit-export-reason').fill('quarterly review');
    await page.getByTestId('audit-export-open').click();
    await expect.poll(() => requested).toBe(true);
    await expect(page.getByTestId('audit-export-created')).toBeVisible();
  });

  test('surfaces a step-up denial (403) as guidance without leaking internals', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route('**/api/v1/audit-exports**', (r) => {
      if (r.request().method() === 'POST') return r.fulfill(err(403, 'step_up_required'));
      return r.fulfill(ok({ data: [] }));
    });

    await page.goto('/exports');
    await page.locator('#audit-export-reason').fill('quarterly review');
    await page.getByTestId('audit-export-open').click();
    await expect(page.getByTestId('audit-export-step-up')).toBeVisible();
    expect(await page.content()).not.toContain('step_up_required');
  });

  test('cannot request an export with no assigned branch', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS, { branchIds: [] });
    await page.route('**/api/v1/audit-exports**', (r) => r.fulfill(ok({ data: [] })));

    await page.goto('/exports');
    await expect(page.getByTestId('audit-export-no-branch')).toBeVisible();
    await expect(page.getByTestId('audit-export-open')).toBeDisabled();
  });

  test('downloads a ready export via an on-demand signed link that is never rendered; count refreshes', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    let downloadCount = 0;
    await page.route(`**/api/v1/audit-exports/${EXPORT}`, (r) => r.fulfill(ok({ data: auditExport({ download_count: downloadCount }) })));
    let linkRequested = false;
    await page.route(`**/api/v1/audit-exports/${EXPORT}/download-link`, (r) => { linkRequested = true; downloadCount = 1; return r.fulfill(ok({ data: { url: 'https://signed.example/audit.csv?sig=SECRET', expires_at: '2026-07-05T09:05:00Z' } })); });

    await page.goto(`/exports/${EXPORT}`);
    await expect(page.getByTestId('audit-export-detail-status')).toHaveAttribute('data-status', 'ready');
    await page.getByTestId('audit-export-download').click();
    await expect.poll(() => linkRequested).toBe(true);
    // The signed URL / signature must never be rendered into the DOM.
    expect(await page.content()).not.toContain('sig=SECRET');
    // Download accounting refreshes after the stream.
    await expect(page.getByTestId('audit-export-download-count')).toHaveText('1');
  });

  test('a revoked export cannot be downloaded', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route(`**/api/v1/audit-exports/${EXPORT}`, (r) => r.fulfill(ok({ data: auditExport({ status: 'revoked', can: { view: true, download: false, revoke: false } }) })));

    await page.goto(`/exports/${EXPORT}`);
    await expect(page.getByTestId('audit-export-download')).toHaveCount(0);
    await expect(page.getByText(/revoked/i).first()).toBeVisible();
  });

  test('a failed export shows only redacted information (no file_id/path/signature)', async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await page.route(`**/api/v1/audit-exports/${EXPORT}`, (r) => r.fulfill(ok({ data: auditExport({ status: 'failed', row_count: null, failure_code: 'generation_error', failure_message: 'Export generation failed.', can: { view: true, download: false, revoke: false } }) })));

    await page.goto(`/exports/${EXPORT}`);
    await expect(page.getByText('Export generation failed.')).toBeVisible();
    const html = await page.content();
    expect(html).not.toContain('file_id');
    expect(html).not.toContain('/storage/');
  });
});

/* ------------------------------------------- 7. responsive / dark / a11y / keyboard */

const SURFACES: Array<{ name: string; url: string; ready: string; setup: (p: Page) => Promise<void> }> = [
  { name: 'event list', url: '/audit', ready: 'audit-event-row', setup: async (p) => { await p.route('**/api/v1/audit-logs?**', (r) => r.fulfill(ok({ data: [auditEvent()], meta: { current_page: 1, last_page: 2, total: 30 } }))); } },
  { name: 'event detail', url: `/audit/${EVENT}`, ready: 'audit-detail-action', setup: async (p) => { await p.route(`**/api/v1/audit-logs/${EVENT}`, (r) => r.fulfill(ok({ data: auditEvent() }))); } },
  { name: 'flagged queue', url: '/audit/flags', ready: 'flagged-row', setup: async (p) => { await p.route('**/api/v1/audit-flagged-events?**', (r) => r.fulfill(ok({ data: [flagged('open', {})], meta: { current_page: 1, last_page: 1, total: 1 } }))); } },
  { name: 'flagged detail', url: `/audit/flags/${FLAG}`, ready: 'flagged-detail-status', setup: async (p) => { await p.route(`**/api/v1/audit-flagged-events/${FLAG}`, (r) => r.fulfill(ok({ data: flagged('under_review', { resolve_metadata: true }) }))); } },
  { name: 'finance audit', url: '/audit/finance', ready: 'audit-event-row', setup: async (p) => { await p.route('**/api/v1/audit-logs/finance?**', (r) => r.fulfill(ok({ data: [auditEvent()] }))); } },
  { name: 'export list', url: '/exports', ready: 'audit-export-open', setup: async (p) => { await p.route('**/api/v1/audit-exports?**', (r) => r.fulfill(ok({ data: [auditExport()] }))); } },
  { name: 'export detail', url: `/exports/${EXPORT}`, ready: 'audit-export-detail-status', setup: async (p) => { await p.route(`**/api/v1/audit-exports/${EXPORT}`, (r) => r.fulfill(ok({ data: auditExport() }))); } },
];

for (const surface of SURFACES) {
  test(`${surface.name}: no serious/critical axe (light + dark) and no overflow at 360/768/1280`, async ({ page }) => {
    await stubMe(page, 'audit', AUDIT_PERMS);
    await surface.setup(page);

    await page.goto(surface.url);
    await expect(page.getByTestId(surface.ready).first()).toBeVisible();

    for (const theme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: theme });
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${surface.name} ${theme}: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }

    for (const width of [360, 768, 1280]) {
      await page.setViewportSize({ width, height: 800 });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow, `${surface.name} overflow at ${width}`).toBe(false);
    }
  });
}

test('keyboard-only: the export request form is reachable and its controls are focusable', async ({ page }) => {
  await stubMe(page, 'audit', AUDIT_PERMS);
  await page.route('**/api/v1/audit-exports?**', (r) => r.fulfill(ok({ data: [auditExport()] })));

  await page.goto('/exports');
  const reason = page.locator('#audit-export-reason');
  await reason.focus();
  await expect(reason).toBeFocused();
  await page.keyboard.type('quarterly review');
  const submit = page.getByTestId('audit-export-open');
  await submit.focus();
  await expect(submit).toBeFocused();
});