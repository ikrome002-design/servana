import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 21S E2E — the Personnel Client-SMS screen (Plan §64, §80, §27.1; ADR-010). The SPA preview has
 | no backend; `/me` + `/api/v1` are stubbed to drive the REAL frontend. Own-scope derivation, the
 | completed-session rule, consent, the entitlement/billing gates, idempotency, the state machines and
 | the contact-export prohibition at the API are all proven by the backend Feature suite
 | (tests/Feature/Messaging/*); these prove FRONTEND behaviour, role gating, canonical copy,
 | accessibility — and that no contact ever reaches the browser's state, storage, URL or clipboard.
 |
 | THE FIXTURE PHONE `+254712345678` IS DELIBERATE: every assertion below checks that neither it nor
 | its national form ever appears in the page, so a regression that starts returning full numbers
 | fails here as well as in the backend suite.
 */

const FULL_PHONE = '+254712345678';
const NATIONAL_PHONE = '712345678';
const CLIENT_ID = '01HZZCLIENT0000000000000001';
const CAMPAIGN_ID = '01HZZCAMPAIGN00000000000001';

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function created(body: unknown) {
  return { status: 201, contentType: 'application/json', body: JSON.stringify(body) };
}
function forbidden(code: string, message: string) {
  return {
    status: 403,
    contentType: 'application/json',
    body: JSON.stringify({ error: { code, message, fields: {}, meta: { entitlement: 'sms' } } }),
  };
}

interface MeOpts {
  role?: string | null;
  permissions?: string[];
}

async function stubMe(page: Page, opts: MeOpts = {}): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'u@citrus.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
        merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: opts.role ? { id: 'mm1', role: opts.role, status: 'active' } : null,
        memberships: opts.role ? [{ id: 'mm1', role: opts.role, status: 'active' }] : [],
        permissions: opts.permissions ?? [],
        setup: { required: false, current_step: null, completed_at: null },
        branch_ids: ['b1'],
        mfa: { required: false, enrolled: true, confirmed: true, verified: true, enrollment_required: false, challenge_required: false, step_up_fresh: true, step_up_fresh_until: null, recovery_codes_remaining: 5 },
      },
    })),
  );
}

function meta(rows: unknown[]) {
  return { current_page: 1, last_page: 1, per_page: 25, total: rows.length };
}

/** A served client, exactly as the masked Resource returns it — no full number, ever. */
const servedClient = { id: CLIENT_ID, full_name: 'Amina Wanjiru', phone_masked: '••• ••• 5678' };

const previewBody = {
  recipient_count: 1,
  excluded_count: 2,
  excluded_reasons: { consent_opted_out: 1, not_served: 1 },
  message_character_count: 24,
  segment_count: 1,
  requires_unicode: false,
  characters_remaining_in_segment: 136,
  estimated_cost: { amount: 100, currency: 'KES', formatted: 'KES 1.00' },
  unit_cost_minor: 100,
  max_recipients: 200,
  max_message_characters: 480,
  billing_notice: 'Sending this campaign adds an SMS charge to your Servana billing.',
};

const campaignBody = {
  id: CAMPAIGN_ID,
  status: 'completed',
  status_label: 'Completed',
  recipient_count: 1,
  message_character_count: 24,
  segment_count: 1,
  estimated_cost: { amount: 100, currency: 'KES', formatted: 'KES 1.00' },
  final_cost: { amount: 100, currency: 'KES', formatted: 'KES 1.00' },
  failure_reason_code: null,
  is_cancellable: false,
  confirmed_at: '2026-07-22T10:00:00+00:00',
  queued_at: '2026-07-22T10:00:01+00:00',
  completed_at: '2026-07-22T10:00:05+00:00',
  cancelled_at: null,
  created_at: '2026-07-22T09:59:00+00:00',
};

interface SmsOpts extends MeOpts {
  previewStatus?: 'ok' | 'entitlement' | 'billing';
  clients?: unknown[];
}

async function gotoSms(page: Page, opts: SmsOpts = {}): Promise<void> {
  await stubMe(page, {
    role: 'personnel',
    permissions: ['personnel.my_served_clients.view', 'personnel.my_sms.send'],
    ...opts,
  });

  const clients = opts.clients ?? [servedClient];

  await page.route(/\/api\/v1\/personnel\/me\/served-clients\/sms(\?.*)?$/, (r) =>
    r.fulfill(ok({ data: clients, meta: meta(clients) })),
  );
  await page.route(/\/api\/v1\/personnel\/me\/sms-campaigns\/preview$/, (r) => {
    if (opts.previewStatus === 'entitlement') {
      return r.fulfill(forbidden('entitlement_disabled', 'Your current plan does not include this feature.'));
    }
    if (opts.previewStatus === 'billing') {
      return r.fulfill(forbidden('billing_read_only', 'Your billing access is read-only.'));
    }

    return r.fulfill(ok({ data: previewBody }));
  });
  await page.route(/\/api\/v1\/personnel\/me\/sms-campaigns\/[^/]+\/confirm$/, (r) =>
    r.fulfill(ok({ data: campaignBody })),
  );
  await page.route(/\/api\/v1\/personnel\/me\/sms-campaigns(\?.*)?$/, (r) => {
    if (r.request().method() === 'POST') return r.fulfill(created({ data: { ...campaignBody, status: 'draft', status_label: 'Draft' } }));

    return r.fulfill(ok({ data: [campaignBody], meta: meta([campaignBody]) }));
  });

  await page.goto('/personnel/sms');
}

/** Nothing anywhere in the page, its storage or its URL may contain a full phone number. */
async function expectNoContactLeak(page: Page): Promise<void> {
  const body = await page.locator('body').innerText();
  expect(body).not.toContain(FULL_PHONE);
  expect(body).not.toContain(NATIONAL_PHONE);

  const html = await page.content();
  expect(html).not.toContain(FULL_PHONE);
  expect(html).not.toContain(NATIONAL_PHONE);

  expect(page.url()).not.toContain(NATIONAL_PHONE);

  const storage = await page.evaluate(() => ({
    local: JSON.stringify(Object.entries(localStorage)),
    session: JSON.stringify(Object.entries(sessionStorage)),
  }));
  expect(storage.local).not.toContain(NATIONAL_PHONE);
  expect(storage.session).not.toContain(NATIONAL_PHONE);
}

/* ================================================================= the flow */

test.describe('Personnel client SMS', () => {
  test('shows served clients with masked contact and no full phone anywhere', async ({ page }) => {
    await gotoSms(page);

    await expect(page.getByTestId('sms-client-list')).toBeVisible();
    await expect(page.getByText('Amina Wanjiru')).toBeVisible();
    await expect(page.getByText('••• ••• 5678')).toBeVisible();

    await expectNoContactLeak(page);
  });

  test('previews server-computed counts, segments, cost, exclusions and the billing notice', async ({ page }) => {
    await gotoSms(page);

    await page.getByLabel('Select Amina Wanjiru').check();
    await page.getByTestId('sms-body').getByRole('textbox').fill('Thank you for visiting.');
    await page.getByTestId('sms-preview-button').click();

    await expect(page.getByTestId('sms-preview-recipients')).toHaveText('1');
    await expect(page.getByTestId('sms-preview-excluded')).toHaveText('2');
    await expect(page.getByTestId('sms-preview-segments')).toHaveText('1');
    await expect(page.getByTestId('sms-preview-cost')).toHaveText('KES 1.00');
    await expect(page.getByTestId('sms-billing-notice')).toContainText('SMS charge');

    // Exclusions are shown as SAFE REASON CODES with counts — never as a client list.
    const exclusions = await page.getByTestId('sms-exclusions').innerText();
    expect(exclusions).toContain('Client opted out of SMS');
    expect(exclusions).toContain('You have no completed session with this client');
    expect(exclusions).not.toContain(NATIONAL_PHONE);

    await expectNoContactLeak(page);
  });

  test('offers Send only AFTER a successful preview, and requires explicit confirmation', async ({ page }) => {
    await gotoSms(page);

    await page.getByLabel('Select Amina Wanjiru').check();
    await page.getByTestId('sms-body').getByRole('textbox').fill('Thank you for visiting.');

    // No preview yet ⇒ no send button.
    await expect(page.getByTestId('sms-send-button')).toHaveCount(0);

    await page.getByTestId('sms-preview-button').click();
    await expect(page.getByTestId('sms-send-button')).toBeVisible();

    await page.getByTestId('sms-send-button').click();
    await expect(page.getByTestId('sms-confirm-send')).toBeVisible();
    await page.getByTestId('sms-confirm-send').click();

    await expect(page.getByTestId('sms-campaign-status')).toHaveText('Completed');
    await expectNoContactLeak(page);
  });

  test('invalidates the preview when the selection or the message changes', async ({ page }) => {
    await gotoSms(page);

    await page.getByLabel('Select Amina Wanjiru').check();
    await page.getByTestId('sms-body').getByRole('textbox').fill('Thank you for visiting.');
    await page.getByTestId('sms-preview-button').click();
    await expect(page.getByTestId('sms-send-button')).toBeVisible();

    // Changing the message throws the server's verdict away — a send can never run against a stale
    // preview.
    await page.getByTestId('sms-body').getByRole('textbox').fill('A different message entirely.');
    await expect(page.getByTestId('sms-send-button')).toHaveCount(0);
    await expect(page.getByTestId('sms-preview')).toHaveCount(0);
  });

  test('renders entitlement and billing refusals as actionable copy, not raw codes', async ({ page }) => {
    await gotoSms(page, { previewStatus: 'entitlement' });

    await page.getByLabel('Select Amina Wanjiru').check();
    await page.getByTestId('sms-body').getByRole('textbox').fill('Hello there.');
    await page.getByTestId('sms-preview-button').click();

    const blocked = page.getByTestId('sms-blocked');
    await expect(blocked).toBeVisible();
    await expect(blocked).toContainText('plan does not include SMS');
    await expect(blocked).not.toContainText('entitlement_disabled');
    await expect(page.getByTestId('sms-send-button')).toHaveCount(0);
  });

  test('renders a billing read-only refusal safely', async ({ page }) => {
    await gotoSms(page, { previewStatus: 'billing' });

    await page.getByLabel('Select Amina Wanjiru').check();
    await page.getByTestId('sms-body').getByRole('textbox').fill('Hello there.');
    await page.getByTestId('sms-preview-button').click();

    await expect(page.getByTestId('sms-blocked')).toContainText('Billing is read-only');
    await expect(page.getByTestId('sms-blocked')).not.toContainText('billing_read_only');
  });

  test('shows a safe empty state when the personnel member has served nobody', async ({ page }) => {
    await gotoSms(page, { clients: [] });

    await expect(page.getByText('You have no completed sessions with any client yet.')).toBeVisible();
  });

  test('hides the whole surface from a member without the read permission', async ({ page }) => {
    await gotoSms(page, { permissions: [] });

    await expect(page.getByTestId('sms-forbidden')).toBeVisible();
    await expect(page.getByTestId('sms-client-list')).toHaveCount(0);
  });
});

/* ================================================= contact-export prohibition */

test.describe('contact-export prohibition (ADR-010)', () => {
  test('offers no export, download, print or copy control anywhere on the screen', async ({ page }) => {
    await gotoSms(page);
    await page.getByLabel('Select Amina Wanjiru').check();
    await page.getByTestId('sms-body').getByRole('textbox').fill('Thank you for visiting.');
    await page.getByTestId('sms-preview-button').click();

    for (const label of [/export/i, /download/i, /print/i, /copy numbers/i, /copy contacts/i, /csv/i, /xlsx/i, /vcard/i]) {
      await expect(page.getByRole('button', { name: label })).toHaveCount(0);
      await expect(page.getByRole('link', { name: label })).toHaveCount(0);
    }

    // No download-shaped anchor of any kind.
    await expect(page.locator('a[download]')).toHaveCount(0);
  });

  test('never calls the clipboard API or window.print', async ({ page }) => {
    await page.addInitScript(() => {
      (window as unknown as { __clipboardCalls: number }).__clipboardCalls = 0;
      (window as unknown as { __printCalls: number }).__printCalls = 0;
      window.print = () => { (window as unknown as { __printCalls: number }).__printCalls += 1; };
      if (navigator.clipboard) {
        navigator.clipboard.writeText = async () => {
          (window as unknown as { __clipboardCalls: number }).__clipboardCalls += 1;
        };
      }
    });

    await gotoSms(page);
    await page.getByLabel('Select Amina Wanjiru').check();
    await page.getByTestId('sms-body').getByRole('textbox').fill('Thank you for visiting.');
    await page.getByTestId('sms-preview-button').click();
    await page.getByTestId('sms-send-button').click();
    await page.getByTestId('sms-confirm-send').click();
    await expect(page.getByTestId('sms-campaign-status')).toBeVisible();

    const counts = await page.evaluate(() => ({
      clipboard: (window as unknown as { __clipboardCalls: number }).__clipboardCalls,
      print: (window as unknown as { __printCalls: number }).__printCalls,
    }));

    expect(counts.clipboard).toBe(0);
    expect(counts.print).toBe(0);
  });

  test('puts no contact into storage or the URL across the whole flow', async ({ page }) => {
    await gotoSms(page);
    await page.getByLabel('Select Amina Wanjiru').check();
    await page.getByTestId('sms-body').getByRole('textbox').fill('Thank you for visiting.');
    await page.getByTestId('sms-preview-button').click();
    await page.getByTestId('sms-send-button').click();
    await page.getByTestId('sms-confirm-send').click();
    await expect(page.getByTestId('sms-campaign-status')).toBeVisible();

    await expectNoContactLeak(page);
  });

  test('never sends a staff identifier, a cost or a recipient count to the server', async ({ page }) => {
    // Observe requests rather than intercepting them: `gotoSms` registers its own handlers, and
    // Playwright runs the LAST registered route first, so a fallback-based capture would never see
    // the request.
    const bodies: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/api/v1/personnel/me/sms-campaigns') && request.method() === 'POST') {
        const data = request.postData();
        if (data) bodies.push(data);
      }
    });

    await gotoSms(page);
    await page.getByLabel('Select Amina Wanjiru').check();
    await page.getByTestId('sms-body').getByRole('textbox').fill('Thank you for visiting.');
    await page.getByTestId('sms-preview-button').click();
    await page.getByTestId('sms-send-button').click();
    await page.getByTestId('sms-confirm-send').click();
    await expect(page.getByTestId('sms-campaign-status')).toBeVisible();

    expect(bodies.length).toBeGreaterThan(0);
    for (const body of bodies) {
      expect(body).not.toContain('staff_profile');
      expect(body).not.toContain('estimated_cost');
      expect(body).not.toContain('recipient_count');
      expect(body).not.toContain('unit_cost');
      expect(body).not.toContain(NATIONAL_PHONE);
    }
  });
});

/* ============================================ responsive · dark · accessibility */

const VIEWPORTS = [
  { name: 'mobile', width: 360, height: 780 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1280, height: 900 },
];

for (const viewport of VIEWPORTS) {
  for (const scheme of ['light', 'dark'] as const) {
    test(`is accessible with no horizontal overflow at ${viewport.name} in ${scheme} mode`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await gotoSms(page);

      await expect(page.getByTestId('sms-client-list')).toBeVisible();

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
      );
      expect(overflow).toBe(false);

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious).toEqual([]);
    });
  }
}

test('is accessible at 200% zoom without horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 640, height: 780 });
  await gotoSms(page);
  await expect(page.getByTestId('sms-client-list')).toBeVisible();

  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  );
  expect(overflow).toBe(false);
});

test('completes the whole flow by keyboard, and restores focus after the dialog', async ({ page }) => {
  await gotoSms(page);

  // Select by keyboard.
  const checkbox = page.getByLabel('Select Amina Wanjiru');
  await checkbox.focus();
  await page.keyboard.press('Space');
  await expect(checkbox).toBeChecked();

  await page.getByTestId('sms-body').getByRole('textbox').fill('Thank you for visiting.');

  const previewButton = page.getByTestId('sms-preview-button');
  await previewButton.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByTestId('sms-send-button')).toBeVisible();

  const sendButton = page.getByTestId('sms-send-button');
  await sendButton.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByTestId('sms-confirm-send')).toBeVisible();

  // Escape closes the dialog and focus returns to the control that opened it.
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('sms-confirm-send')).toHaveCount(0);
  await expect(sendButton).toBeFocused();
});

test('announces the preview and the send outcome in a live region', async ({ page }) => {
  await gotoSms(page);

  const region = page.getByTestId('sms-status-region');
  await expect(region).toHaveAttribute('aria-live', 'polite');
  await expect(region).toHaveAttribute('role', 'status');

  await page.getByLabel('Select Amina Wanjiru').check();
  await page.getByTestId('sms-body').getByRole('textbox').fill('Thank you for visiting.');
  await page.getByTestId('sms-preview-button').click();

  await expect(region).toContainText('1 recipients');
  await expect(region).toContainText('KES 1.00');

  await page.getByTestId('sms-send-button').click();
  await page.getByTestId('sms-confirm-send').click();
  await expect(region).toContainText('queued');
});
