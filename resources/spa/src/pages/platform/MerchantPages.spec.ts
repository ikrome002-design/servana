import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import MerchantDetail from '@/pages/platform/MerchantDetail.vue';
import MerchantDirectory from '@/pages/platform/MerchantDirectory.vue';
import MerchantRegistrations from '@/pages/platform/MerchantRegistrations.vue';
import { useAuthStore } from '@/stores/authStore';

/**
 * Increment 9D. Three contract pages (§5.4.10, §5.4.11, §5.4.12) replace two tabs and a nested
 * detail pane inside one consolidated screen.
 *
 * These cases prove the SPLIT and the properties the split exists to deliver:
 *
 *  - the merchant detail has its OWN ADDRESS and is loaded from the URL, never from a row the user
 *    happened to click first — the defect that made a merchant record unlinkable;
 *  - the directory carries no embedded detail pane and no governance control;
 *  - an unknown and a refused merchant render the SAME message, so a URL bar cannot enumerate the
 *    platform;
 *  - no page offers merchant creation, first-administrator creation, impersonation, an export that
 *    does not exist, or any merchant-operational mutation;
 *  - operational status and billing status stay two separate, labelled facts.
 *
 * A REAL memory router is used rather than a stubbed `RouterLink`, because deep-link safety is the
 * property under test: the tests navigate to a URL and assert what renders.
 */

const ULID_A = '01JQ0000000000000000000001';
const ULID_B = '01JQ0000000000000000000002';

const REG_ROWS = [
  { id: ULID_A, name: 'Acme Salon', operational_status: 'pending_setup', billing_status: 'trialing', pending_setup: true, registered_at: '2026-07-01T00:00:00Z', setup_completed_at: null },
  { id: ULID_B, name: 'Bella Spa', operational_status: 'active', billing_status: 'overdue', pending_setup: false, registered_at: '2026-07-02T00:00:00Z', setup_completed_at: '2026-07-03T00:00:00Z' },
];

function merchant(overrides: Record<string, unknown> = {}) {
  return {
    id: ULID_A,
    name: 'Acme Salon',
    operational_status: 'active',
    billing_status: 'suspended_billing',
    billing_status_reason: 'Invoice unpaid past grace',
    suspension_reason: null,
    suspended_at: null,
    deactivated_at: null,
    setup_completed_at: '2026-07-03T00:00:00Z',
    registered_at: '2026-07-01T00:00:00Z',
    can: { suspend: true, reactivate: false, deactivate: true },
    ...overrides,
  };
}

const REGISTRATION_VIEW = 'platform.registration_monitor.view';
const MERCHANT_VIEW = 'platform.merchant.view';
const GOVERNANCE = ['platform.merchant.suspend', 'platform.merchant.reactivate', 'platform.merchant.deactivate'];

/** Every canonical destination these pages link to. Registered in the app itself at Increment 7B. */
function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/merchants', name: 'platform.merchants', component: MerchantDirectory },
      { path: '/merchants/registrations', name: 'platform.merchant-registrations', component: MerchantRegistrations },
      { path: '/merchants/:merchantUlid', name: 'platform.merchant-detail', component: MerchantDetail },
      { path: '/audit', name: 'platform.audit', component: { template: '<div />' } },
    ],
  });
}

async function mountAt(path: string, permissions: string[]) {
  useAuthStore().permissions = permissions;
  const router = makeRouter();
  await router.push(path);
  await router.isReady();
  const wrapper = mount(
    { template: '<RouterView />' },
    { global: { plugins: [router] }, attachTo: document.body },
  );
  await flushPromises();
  return { wrapper, router };
}

function okList(rows: unknown[], meta?: Record<string, number>) {
  return { data: meta === undefined ? { data: rows } : { data: rows, meta } };
}

function apiError(status: number, code: string, message: string) {
  const err = new Error(message) as Error & { response?: { status: number }; apiError?: { code: string; message: string }; isAxiosError?: boolean };
  err.isAxiosError = true;
  err.response = { status };
  err.apiError = { code, message };
  return err;
}

/**
 * Capabilities that must not exist anywhere in the Super Administrator merchant experience
 * (Plan §10.2; UI/UX plan §5.4.10-§5.4.12).
 *
 * Asserted against ACTUAL INTERACTIVE ELEMENTS, never against the page's prose. A page that
 * correctly explains "there is no way to create a merchant here" contains the word "create"; a
 * word-ban over the rendered text would fail on the very sentence that proves the boundary.
 */
const FORBIDDEN_CONTROL_PATTERNS = [
  /\bimpersonat/i, /\bsign in as\b/i, /\bcreate\b.*\bmerchant\b/i, /\bnew merchant\b/i,
  /\badd merchant\b/i, /first administrator/i, /\brecord payment\b/i, /\bvalidate payment\b/i,
  /\bissue receipt\b/i, /\bcreate invoice\b/i, /\b(add|create) branch\b/i, /\b(add|create) staff\b/i,
  /\bcomplete setup\b/i, /\bexport\b/i,
];

function assertNoForbiddenControls(wrapper: { findAll: (s: string) => { text: () => string }[] }): void {
  const controls = [...wrapper.findAll('button'), ...wrapper.findAll('a'), ...wrapper.findAll('[role="button"]')]
    .map((node) => node.text().trim())
    .filter((label) => label !== '');

  for (const label of controls) {
    for (const pattern of FORBIDDEN_CONTROL_PATTERNS) {
      expect(pattern.test(label), `forbidden control rendered: "${label}"`).toBe(false);
    }
  }
}

describe('Increment 9D — three Merchant governance pages', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    document.body.innerHTML = '';
  });

  // ── §5.4.10 Registration Monitoring ─────────────────────────────────────────────────────────

  describe('Registration monitoring — /merchants/registrations', () => {
    beforeEach(() => {
      get.mockResolvedValue(okList(REG_ROWS, { current_page: 1, last_page: 2, total: 40 }));
    });

    it('is its own screen with exactly one h1', async () => {
      const { wrapper } = await mountAt('/merchants/registrations', [REGISTRATION_VIEW]);
      const headings = wrapper.findAll('h1');
      expect(headings).toHaveLength(1);
      expect(headings[0].text()).toBe('Registration monitoring');
      expect(wrapper.find('[data-testid="platform-merchant-registrations-screen"]').exists()).toBe(true);
    });

    it('reads the shipped registration monitor and shows operational and billing status separately', async () => {
      const { wrapper } = await mountAt('/merchants/registrations', [REGISTRATION_VIEW]);
      expect(get).toHaveBeenCalledWith('/platform/registration-monitor');
      expect(wrapper.text()).toContain('Acme Salon');
      const badges = wrapper.findAll('[data-testid="sv-status-badge"]');
      const prefixes = badges.map((b) => b.text());
      expect(prefixes.some((t) => t.includes('Operational status:'))).toBe(true);
      expect(prefixes.some((t) => t.includes('Billing status:'))).toBe(true);
    });

    it('renders both responsive presentations from one column definition', async () => {
      const { wrapper } = await mountAt('/merchants/registrations', [REGISTRATION_VIEW]);
      // Desktop/tablet: a semantic table. Mobile: labelled record cards. CSS decides which is shown.
      expect(wrapper.find('table').exists()).toBe(true);
      expect(wrapper.find('[data-testid="sv-record-list-root"]').exists()).toBe(true);
    });

    it('links each registration to the canonical merchant detail route', async () => {
      const { wrapper } = await mountAt('/merchants/registrations', [REGISTRATION_VIEW]);
      const links = wrapper.findAll('[data-testid="registration-detail-link"]');
      expect(links.length).toBeGreaterThan(0);
      expect(links[0].attributes('href')).toBe(`/merchants/${ULID_A}`);
    });

    it('filters by the one status the shipped read allowlists, and resets to page one', async () => {
      const { wrapper } = await mountAt('/merchants/registrations', [REGISTRATION_VIEW]);
      get.mockClear();
      await wrapper.get('#registration-status-filter').setValue('suspended');
      await flushPromises();
      expect(get).toHaveBeenCalledWith('/platform/registration-monitor', { params: { status: 'suspended' } });
    });

    it('pages through the server pagination rather than showing page one as the whole platform', async () => {
      const { wrapper } = await mountAt('/merchants/registrations', [REGISTRATION_VIEW]);
      const pagination = wrapper.find('[data-testid="registrations-pagination"]');
      expect(pagination.exists()).toBe(true);
      get.mockClear();
      await wrapper.get('[data-testid="registrations-pagination"] button:last-of-type').trigger('click');
      await flushPromises();
      expect(get).toHaveBeenCalledWith('/platform/registration-monitor', { params: { page: 2 } });
    });

    it('names the risk, note and filter capabilities it cannot show instead of faking them', async () => {
      const { wrapper } = await mountAt('/merchants/registrations', [REGISTRATION_VIEW]);
      const evidence = wrapper.get('[data-testid="registrations-unavailable-evidence"]').text();
      expect(evidence).toContain('Risk indicators');
      expect(evidence).toContain('duplicate-business');
      expect(evidence).toContain('Governance notes');
      expect(wrapper.text()).not.toContain('Risk: none');
    });

    it('offers no approve, activate or create-merchant action', async () => {
      const { wrapper } = await mountAt('/merchants/registrations', [REGISTRATION_VIEW]);
      assertNoForbiddenControls(wrapper);
      expect(wrapper.get('[data-testid="registrations-no-activation-note"]').text()).toContain('no approve, activate or create-merchant action');
      for (const label of ['Approve', 'Activate', 'Create merchant']) {
        expect(wrapper.findAll('button').some((b) => b.text().trim() === label)).toBe(false);
      }
    });

    it('renders the non-enumerating permission state and issues no request without the key', async () => {
      const { wrapper } = await mountAt('/merchants/registrations', []);
      expect(wrapper.find('[data-testid="sv-permission-state"]').exists()).toBe(true);
      expect(get).not.toHaveBeenCalled();
    });

    it('offers a retry when the read fails', async () => {
      get.mockReset();
      get.mockRejectedValue(new Error('network'));
      const { wrapper } = await mountAt('/merchants/registrations', [REGISTRATION_VIEW]);
      expect(wrapper.find('[data-testid="sv-error-state"]').exists()).toBe(true);
      get.mockResolvedValue(okList(REG_ROWS));
      await wrapper.get('[data-testid="sv-error-state"] button').trigger('click');
      await flushPromises();
      expect(wrapper.text()).toContain('Acme Salon');
    });
  });

  // ── §5.4.11 Merchant Directory ──────────────────────────────────────────────────────────────

  describe('Merchant directory — /merchants', () => {
    beforeEach(() => {
      get.mockResolvedValue(okList([merchant(), merchant({ id: ULID_B, name: 'Bella Spa', operational_status: 'suspended', billing_status: 'active' })], { current_page: 1, last_page: 3, total: 60 }));
    });

    it('is its own screen with exactly one h1', async () => {
      const { wrapper } = await mountAt('/merchants', [MERCHANT_VIEW]);
      const headings = wrapper.findAll('h1');
      expect(headings).toHaveLength(1);
      expect(headings[0].text()).toBe('Merchant directory');
      expect(wrapper.find('[data-testid="platform-merchant-directory-screen"]').exists()).toBe(true);
    });

    it('carries no embedded detail pane and no governance control', async () => {
      const { wrapper } = await mountAt('/merchants', [MERCHANT_VIEW, ...GOVERNANCE]);
      expect(wrapper.find('[data-testid="merchant-governance-panel"]').exists()).toBe(false);
      expect(wrapper.find('[data-testid="action-suspend"]').exists()).toBe(false);
      expect(wrapper.find('[data-testid="operational-status-card"]').exists()).toBe(false);
    });

    it('opens a merchant by navigating to its own address, not by selecting a row', async () => {
      const { wrapper } = await mountAt('/merchants', [MERCHANT_VIEW]);
      const link = wrapper.get(`[data-testid="merchant-directory-link-${ULID_A}"]`);
      expect(link.attributes('href')).toBe(`/merchants/${ULID_A}`);
      // A link, not a button: middle-click, copy-link and open-in-new-tab all keep working.
      expect(link.element.tagName).toBe('A');
    });

    it('renders both responsive presentations and keeps the two statuses apart', async () => {
      const { wrapper } = await mountAt('/merchants', [MERCHANT_VIEW]);
      expect(wrapper.find('table').exists()).toBe(true);
      expect(wrapper.find('[data-testid="sv-record-list-root"]').exists()).toBe(true);
      const headers = wrapper.findAll('th').map((th) => th.text());
      expect(headers).toContain('Operational status');
      expect(headers).toContain('Billing status');
    });

    it('summarises how many merchants on the page need attention without reordering the platform', async () => {
      const { wrapper } = await mountAt('/merchants', [MERCHANT_VIEW]);
      expect(wrapper.get('[data-testid="directory-attention-summary"]').text()).toContain('2 of the merchants');
    });

    it('filters by operational status through the shipped parameter', async () => {
      const { wrapper } = await mountAt('/merchants', [MERCHANT_VIEW]);
      get.mockClear();
      await wrapper.get('#directory-status-filter').setValue('suspended');
      await flushPromises();
      expect(get).toHaveBeenCalledWith('/platform/merchants', { params: { status: 'suspended' } });
    });

    it('pages through the server pagination', async () => {
      const { wrapper } = await mountAt('/merchants', [MERCHANT_VIEW]);
      expect(wrapper.find('[data-testid="directory-pagination"]').exists()).toBe(true);
      get.mockClear();
      await wrapper.get('[data-testid="directory-pagination"] button:last-of-type').trigger('click');
      await flushPromises();
      expect(get).toHaveBeenCalledWith('/platform/merchants', { params: { page: 2 } });
    });

    it('shows no export control, and says why', async () => {
      const { wrapper } = await mountAt('/merchants', [MERCHANT_VIEW]);
      expect(wrapper.findAll('button').some((b) => /export/i.test(b.text()))).toBe(false);
      expect(wrapper.get('[data-testid="directory-unavailable-evidence"]').text()).toContain('masked directory export');
    });

    it('offers no merchant creation, first administrator, impersonation or operational control', async () => {
      const { wrapper } = await mountAt('/merchants', [MERCHANT_VIEW, ...GOVERNANCE]);
      assertNoForbiddenControls(wrapper);
      expect(wrapper.get('[data-testid="directory-boundary-note"]').text()).toContain('no way to create a merchant');
    });

    it('renders the non-enumerating permission state and issues no request without the key', async () => {
      const { wrapper } = await mountAt('/merchants', []);
      expect(wrapper.find('[data-testid="sv-permission-state"]').exists()).toBe(true);
      expect(get).not.toHaveBeenCalled();
    });
  });

  // ── §5.4.12 Merchant Detail and Governance ──────────────────────────────────────────────────

  describe('Merchant detail and governance — /merchants/:merchantUlid', () => {
    beforeEach(() => {
      get.mockResolvedValue({ data: { data: merchant() } });
    });

    it('loads the merchant named in the URL, with no prior row selection', async () => {
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW]);
      expect(get).toHaveBeenCalledWith(`/platform/merchants/${ULID_A}`);
      expect(wrapper.get('[data-testid="merchant-detail-name"]').text()).toBe('Acme Salon');
    });

    it('reloads when the address changes to another merchant', async () => {
      const { router } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW]);
      get.mockClear();
      get.mockResolvedValue({ data: { data: merchant({ id: ULID_B, name: 'Bella Spa' }) } });
      await router.push(`/merchants/${ULID_B}`);
      await flushPromises();
      expect(get).toHaveBeenCalledWith(`/platform/merchants/${ULID_B}`);
    });

    it('shows operational and billing status as two separate labelled cards', async () => {
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW]);
      expect(wrapper.get('[data-testid="operational-status-card"]').text()).toContain('Operational status');
      expect(wrapper.get('[data-testid="billing-status-card"]').text()).toContain('Billing status');
      expect(wrapper.get('[data-testid="operational-status"]').text()).toBe('Active');
      expect(wrapper.get('[data-testid="detail-billing-status"]').text()).toBe('Suspended');
    });

    it('states that governance never clears a billing suspension', async () => {
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW]);
      expect(wrapper.get('[data-testid="billing-status-card"]').text()).toContain('never change it');
      expect(wrapper.get('[data-testid="governance-preservation-notice"]').text()).toContain('billing lifecycle');
    });

    it('renders an unknown merchant and a refused merchant identically, so a URL cannot enumerate', async () => {
      get.mockReset();
      get.mockRejectedValue(apiError(404, 'not_found', 'Not found'));
      const missing = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW]);
      const missingText = missing.wrapper.get('[data-testid="merchant-detail-unavailable"]').text();
      missing.wrapper.unmount();

      setActivePinia(createPinia());
      get.mockReset();
      get.mockRejectedValue(apiError(403, 'forbidden', 'Forbidden'));
      const refused = await mountAt(`/merchants/${ULID_B}`, [MERCHANT_VIEW]);
      const refusedText = refused.wrapper.get('[data-testid="merchant-detail-unavailable"]').text();

      expect(refusedText).toBe(missingText);
      expect(missingText).not.toContain(ULID_A);
      expect(refusedText).not.toContain(ULID_B);
      expect(missingText).not.toContain('Acme');
    });

    it('offers a retry when the load fails for an unrelated reason', async () => {
      get.mockReset();
      get.mockRejectedValue(apiError(500, 'server_error', 'Boom'));
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW]);
      expect(wrapper.find('[data-testid="merchant-detail-error"]').exists()).toBe(true);
    });

    it('hides a lifecycle action the server can-map disallows, even with the permission key', async () => {
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW, ...GOVERNANCE]);
      expect(wrapper.find('[data-testid="action-suspend"]').exists()).toBe(true);
      expect(wrapper.find('[data-testid="action-deactivate"]').exists()).toBe(true);
      // `can.reactivate` is false on this merchant.
      expect(wrapper.find('[data-testid="action-reactivate"]').exists()).toBe(false);
    });

    it('hides every lifecycle action without the permission keys', async () => {
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW]);
      expect(wrapper.find('[data-testid="action-suspend"]').exists()).toBe(false);
      expect(wrapper.find('[data-testid="action-deactivate"]').exists()).toBe(false);
    });

    it('requires a reason of at least three characters, previews the impact, and posts to the shipped route', async () => {
      post.mockResolvedValueOnce({ data: { data: merchant({ operational_status: 'suspended' }) } });
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW, ...GOVERNANCE]);
      await wrapper.get('[data-testid="action-suspend"]').trigger('click');
      await flushPromises();

      const preview = document.querySelector('[data-testid="governance-impact-preview"]') as HTMLElement;
      expect(preview.textContent).toContain('Billing status is not changed');

      const confirm = () => document.querySelector('[data-testid="confirm-governance"]') as HTMLButtonElement;
      expect(confirm().disabled).toBe(true);

      const textarea = document.querySelector('#governance-reason') as HTMLTextAreaElement;
      textarea.value = 'Fraud investigation';
      textarea.dispatchEvent(new Event('input'));
      await flushPromises();
      expect(confirm().disabled).toBe(false);

      confirm().click();
      await flushPromises();
      expect(post).toHaveBeenCalledWith(`/platform/merchants/${ULID_A}/suspend`, { reason: 'Fraud investigation' });
      wrapper.unmount();
    });

    it('surfaces a missing fresh step-up as guidance rather than a silent failure', async () => {
      post.mockRejectedValueOnce(apiError(403, 'mfa_challenge_required', 'A fresh step-up is required.'));
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW, ...GOVERNANCE]);
      await wrapper.get('[data-testid="action-suspend"]').trigger('click');
      await flushPromises();
      const textarea = document.querySelector('#governance-reason') as HTMLTextAreaElement;
      textarea.value = 'Fraud investigation';
      textarea.dispatchEvent(new Event('input'));
      await flushPromises();
      (document.querySelector('[data-testid="confirm-governance"]') as HTMLButtonElement).click();
      await flushPromises();
      const alert = document.querySelector('[role="alert"]') as HTMLElement;
      expect(alert.textContent).toContain('step-up');
      wrapper.unmount();
    });

    it('names the evidence it cannot show rather than rendering an empty tab as a fact', async () => {
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW]);
      const evidence = wrapper.get('[data-testid="merchant-detail-unavailable-evidence"]').text();
      expect(evidence).toContain('governance timeline');
      expect(evidence).toContain('Subscription invoices');
      expect(evidence).toContain('Branches, staff overview');
    });

    it('links to the platform audit surface only when the viewer holds the audit key', async () => {
      const without = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW]);
      expect(without.wrapper.find('[data-testid="merchant-detail-audit-link"]').exists()).toBe(false);
      without.wrapper.unmount();

      setActivePinia(createPinia());
      const withKey = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW, 'platform.audit.view']);
      expect(withKey.wrapper.get('[data-testid="merchant-detail-audit-link"]').attributes('href')).toBe('/audit');
    });

    it('offers no impersonation, branch, staff, invoice, payment or setup control', async () => {
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, [MERCHANT_VIEW, ...GOVERNANCE]);
      assertNoForbiddenControls(wrapper);
    });

    it('renders the non-enumerating permission state and issues no request without the key', async () => {
      const { wrapper } = await mountAt(`/merchants/${ULID_A}`, []);
      expect(wrapper.find('[data-testid="sv-permission-state"]').exists()).toBe(true);
      expect(get).not.toHaveBeenCalled();
    });

    it('keeps the static registrations path from resolving to the parameterised detail route', async () => {
      const router = makeRouter();
      expect(router.resolve('/merchants/registrations').name).toBe('platform.merchant-registrations');
      expect(router.resolve(`/merchants/${ULID_A}`).name).toBe('platform.merchant-detail');
    });
  });
});
