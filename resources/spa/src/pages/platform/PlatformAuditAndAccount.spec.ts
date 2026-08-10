import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();
const del = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    patch: (...a: unknown[]) => patch(...a),
    delete: (...a: unknown[]) => del(...a),
  },
  primeCsrfCookie: vi.fn(),
}));

import AccountAndSecurity from '@/pages/platform/AccountAndSecurity.vue';
import PlatformAudit from '@/pages/platform/PlatformAudit.vue';
import { useAuthStore } from '@/stores/authStore';

/**
 * Increment 9E. Two contract pages: Platform Audit (§5.4.18) and Account and Security (§5.4.22).
 *
 * The properties under test are the ones that make each page truthful rather than merely present:
 *
 *  - the audit page is read-only because the CONTRACT is read-only, and it renders no integrity
 *    indicator and no export control, because neither has an endpoint behind it;
 *  - the account page is own-scope because every endpoint it calls is own-scope, and it says what
 *    it cannot do rather than leaving a missing control to be inferred;
 *  - no request either page makes carries another user's identifier.
 */

const AUDIT_VIEW = 'platform.audit.view';

const EVENT = {
  id: '01JAUDIT0000000000000001',
  action: 'merchant.suspended',
  severity: 'high',
  actor: 'a***@citrus.co.ke',
  subject_type: 'Merchant',
  context: { merchant_id: '01JQ0000000000000000000001', from_status: 'active', to_status: 'suspended', reason: 'Fraud investigation' },
  correlation_id: 'corr-abc',
  created_at: '2026-08-01T09:00:00+00:00',
};

function auditListResponse(meta?: Record<string, number>) {
  return { data: meta === undefined ? { data: [EVENT] } : { data: [EVENT], meta } };
}

async function mountAudit(permissions: string[]) {
  useAuthStore().permissions = permissions;
  const wrapper = mount(PlatformAudit, { attachTo: document.body });
  await flushPromises();
  return wrapper;
}

describe('Increment 9E — Platform Audit (§5.4.18)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
    del.mockReset();
    document.body.innerHTML = '';
    get.mockResolvedValue(auditListResponse({ current_page: 1, last_page: 2, total: 40 }));
  });

  it('is its own screen with exactly one h1', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe('Platform audit');
    expect(wrapper.find('[data-testid="platform-audit-screen"]').exists()).toBe(true);
  });

  it('reads the platform chain through the shipped endpoint', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    expect(get).toHaveBeenCalledWith('/platform/audit-logs', { params: { sort: '-created_at' } });
    expect(wrapper.text()).toContain('merchant.suspended');
  });

  it('renders both responsive presentations from one column definition', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    expect(wrapper.find('table').exists()).toBe(true);
    expect(wrapper.find('[data-testid="sv-record-list-root"]').exists()).toBe(true);
  });

  it('sends only the non-empty allowlisted filters, and resets to the first page', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    get.mockClear();
    await wrapper.get('#audit-severity').setValue('critical');
    await flushPromises();
    expect(get).toHaveBeenCalledWith('/platform/audit-logs', { params: { severity: 'critical', sort: '-created_at' } });
  });

  it('pages through the server pagination', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    expect(wrapper.find('[data-testid="audit-pagination"]').exists()).toBe(true);
    get.mockClear();
    await wrapper.get('[data-testid="audit-pagination"] button:last-of-type').trigger('click');
    await flushPromises();
    expect(get).toHaveBeenCalledWith('/platform/audit-logs', { params: { sort: '-created_at', page: 2 } });
  });

  it('opens an event and presents the recorded change as before and after', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    get.mockResolvedValueOnce({ data: { data: EVENT } });
    await wrapper.get(`[data-testid="audit-event-open-${EVENT.id}"]`).trigger('click');
    await flushPromises();
    expect(get).toHaveBeenCalledWith(`/platform/audit-logs/${EVENT.id}`);
    const changes = wrapper.get('[data-testid="audit-event-changes"]');
    expect(changes.get('[data-testid="audit-change-before"]').text()).toBe('active');
    expect(changes.get('[data-testid="audit-change-after"]').text()).toBe('suspended');
  });

  it('shows the remaining masked context without repeating the change pair', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    get.mockResolvedValueOnce({ data: { data: EVENT } });
    await wrapper.get(`[data-testid="audit-event-open-${EVENT.id}"]`).trigger('click');
    await flushPromises();
    const detail = wrapper.get('[data-testid="audit-event-detail"]').text();
    expect(detail).toContain('merchant_id');
    expect(detail).toContain('reason');
    expect(detail).not.toContain('from_status');
    expect(wrapper.get('[data-testid="audit-event-correlation"]').text()).toBe('corr-abc');
  });

  it('renders one message for an event that is unknown or not addressable here', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    get.mockRejectedValueOnce(new Error('404'));
    await wrapper.get(`[data-testid="audit-event-open-${EVENT.id}"]`).trigger('click');
    await flushPromises();
    const message = wrapper.get('[data-testid="audit-event-detail-unavailable"]').text();
    expect(message).toBe('That audit event isn’t available.');
    expect(message).not.toContain(EVENT.id);
  });

  it('offers no mutation of an append-only record', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    const labels = wrapper.findAll('button').map((b) => b.text().trim().toLowerCase());
    for (const forbidden of ['edit', 'delete', 'remove', 'resolve', 'dismiss', 'annotate']) {
      expect(labels.some((label) => label.includes(forbidden)), `mutation control: ${forbidden}`).toBe(false);
    }
    expect(post).not.toHaveBeenCalled();
    expect(patch).not.toHaveBeenCalled();
    expect(del).not.toHaveBeenCalled();
  });

  it('renders no export control and states the export disposition', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    expect(wrapper.findAll('button').some((b) => /export/i.test(b.text()))).toBe(false);
    const disposition = wrapper.get('[data-testid="audit-export-disposition"]').text();
    expect(disposition).toContain('platform.audit.export');
    expect(disposition).toContain('Phase 23');
  });

  it('claims no chain-integrity status it cannot verify', async () => {
    const wrapper = await mountAudit([AUDIT_VIEW]);
    const statement = wrapper.get('[data-testid="audit-integrity-statement"]').text();
    expect(statement).toContain('append-only');
    expect(statement).toContain('not exposed by any endpoint');
    expect(wrapper.text()).not.toMatch(/chain[^.]*\bverified\b/i);
    expect(wrapper.text()).not.toMatch(/\bchain:\s*healthy\b/i);
  });

  it('renders the non-enumerating permission state and issues no request without the key', async () => {
    const wrapper = await mountAudit([]);
    expect(wrapper.find('[data-testid="sv-permission-state"]').exists()).toBe(true);
    expect(get).not.toHaveBeenCalled();
  });

  it('offers a retry when the read fails', async () => {
    get.mockReset();
    get.mockRejectedValue(new Error('network'));
    const wrapper = await mountAudit([AUDIT_VIEW]);
    expect(wrapper.find('[data-testid="sv-error-state"]').exists()).toBe(true);
  });
});

// ── Account and Security ──────────────────────────────────────────────────────────────────────

/**
 * The LOCAL account host, not the production one: `AccountHostRegistryParityTest` forbids a
 * production hostname anywhere outside the account-host authority, so that a silent rename cannot
 * hide in a fixture.
 */
const SESSIONS = [
  { id: '01JSESSION000000000000001', account_key: 'super_administrator', host: 'citrus.servana.test', merchant_name: null, branch_name: null, created_at: '2026-08-01T08:00:00+00:00', last_activity_at: '2026-08-08T08:00:00+00:00', revoked: false, is_current: true },
  { id: '01JSESSION000000000000002', account_key: 'super_administrator', host: 'citrus.servana.test', merchant_name: null, branch_name: null, created_at: '2026-07-30T08:00:00+00:00', last_activity_at: '2026-08-05T08:00:00+00:00', revoked: false, is_current: false },
];

/**
 * The page refreshes MFA state on mount, so the stub for `/auth/mfa` must agree with the seeded
 * state — otherwise the refresh silently overwrites the case under test with a confirmed one.
 */
let stubbedMfa = { enrolled: true, confirmed: true };

function seedUser(mfa: { enrolled: boolean; confirmed: boolean }) {
  stubbedMfa = mfa;
  const auth = useAuthStore();
  auth.user = {
    id: '01JUSER00000000000000001',
    email: 'owner@citrus.co.ke',
    name: 'Platform Owner',
    status: 'active',
    email_verified_at: '2026-01-01T00:00:00+00:00',
    is_platform_staff: true,
    theme_preference: null,
    resolved_theme: 'light',
  } as never;
  auth.mfa = { enrolled: mfa.enrolled, confirmed: mfa.confirmed, enrollment_required: false, challenge_required: false } as never;
}

async function mountAccount(mfa = { enrolled: true, confirmed: true }) {
  seedUser(mfa);
  const wrapper = mount(AccountAndSecurity, { attachTo: document.body });
  await flushPromises();
  return wrapper;
}

describe('Increment 9E — Account and Security (§5.4.22)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
    del.mockReset();
    document.body.innerHTML = '';
    stubbedMfa = { enrolled: true, confirmed: true };
    get.mockImplementation((url: string) => {
      if (url === '/auth/sessions') return Promise.resolve({ data: { data: SESSIONS } });
      if (url === '/auth/mfa') return Promise.resolve({ data: { data: { mfa: { ...stubbedMfa } } } });
      return Promise.resolve({ data: { data: null } });
    });
  });

  it('is its own screen with exactly one h1', async () => {
    const wrapper = await mountAccount();
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe('Account and security');
    expect(wrapper.find('[data-testid="platform-account-screen"]').exists()).toBe(true);
  });

  it('shows the signed-in identity only', async () => {
    const wrapper = await mountAccount();
    expect(wrapper.get('[data-testid="account-name"]').text()).toBe('Platform Owner');
    expect(wrapper.get('[data-testid="account-email"]').text()).toBe('owner@citrus.co.ke');
  });

  it('sends no user identifier on any request it makes', async () => {
    await mountAccount();
    for (const call of get.mock.calls) {
      expect(String(call[0])).not.toContain('01JUSER');
    }
    expect(get).toHaveBeenCalledWith('/auth/sessions');
  });

  it('offers no password, one-time-code login or passkey control', async () => {
    const wrapper = await mountAccount();
    expect(wrapper.find('input[type="password"]').exists()).toBe(false);
    const labels = wrapper.findAll('button').map((b) => b.text().toLowerCase());
    for (const forbidden of ['password', 'passkey', 'webauthn', 'security key']) {
      expect(labels.some((label) => label.includes(forbidden)), `forbidden control: ${forbidden}`).toBe(false);
    }
  });

  it('enrolls an authenticator, then shows the recovery codes exactly once', async () => {
    post.mockResolvedValueOnce({ data: { data: { secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/x', mfa: { enrolled: true, confirmed: false } } } });
    const wrapper = await mountAccount({ enrolled: false, confirmed: false });
    await wrapper.get('[data-testid="account-mfa-enroll"]').trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/auth/mfa/enroll');
    expect(wrapper.get('[data-testid="account-mfa-secret"]').text()).toBe('JBSWY3DPEHPK3PXP');

    post.mockResolvedValueOnce({ data: { data: { recovery_codes: ['aaa-111', 'bbb-222'], mfa: { enrolled: true, confirmed: true } } } });
    await wrapper.get('#account-mfa-code').setValue('123456');
    await wrapper.get('[data-testid="account-mfa-confirm"]').trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/auth/mfa/confirm', { code: '123456' });
    const codes = wrapper.get('[data-testid="account-recovery-codes"]').text();
    expect(codes).toContain('aaa-111');
    expect(codes).toContain('shown once');
  });

  it('confirms before replacing recovery codes, then posts to the own-scope route', async () => {
    const wrapper = await mountAccount();
    await wrapper.get('[data-testid="account-mfa-regenerate"]').trigger('click');
    await flushPromises();
    const confirmButton = [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Replace codes');
    expect(confirmButton, 'the confirmation dialog is shown before codes are replaced').toBeTruthy();

    post.mockResolvedValueOnce({ data: { data: { recovery_codes: ['ccc-333'], mfa: { enrolled: true, confirmed: true } } } });
    confirmButton?.click();
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/auth/mfa/recovery-codes');
    expect(wrapper.get('[data-testid="account-recovery-codes"]').text()).toContain('ccc-333');
    wrapper.unmount();
  });

  it('surfaces a missing fresh step-up on recovery-code replacement', async () => {
    const wrapper = await mountAccount();
    await wrapper.get('[data-testid="account-mfa-regenerate"]').trigger('click');
    await flushPromises();
    post.mockRejectedValueOnce(new Error('403'));
    [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Replace codes')?.click();
    await flushPromises();
    expect(wrapper.get('[data-testid="account-mfa-error"]').text()).toContain('step-up');
    wrapper.unmount();
  });

  it('states that the platform MFA requirement cannot be lowered here', async () => {
    const wrapper = await mountAccount();
    expect(wrapper.get('[data-testid="account-mfa-policy-note"]').text()).toContain('cannot be turned off');
    const labels = wrapper.findAll('button').map((b) => b.text().toLowerCase());
    for (const forbidden of ['disable two-factor', 'turn off', 'skip']) {
      expect(labels.some((label) => label.includes(forbidden))).toBe(false);
    }
  });

  it('lists the active sessions and marks the current one', async () => {
    const wrapper = await mountAccount();
    expect(get).toHaveBeenCalledWith('/auth/sessions');
    expect(wrapper.find('[data-testid="account-session-current"]').exists()).toBe(true);
  });

  it('confirms, then revokes exactly the session named on the control', async () => {
    const wrapper = await mountAccount();
    await wrapper.get(`[data-testid="account-session-revoke-${SESSIONS[1].id}"]`).trigger('click');
    await flushPromises();
    del.mockResolvedValueOnce({});
    [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'End session')?.click();
    await flushPromises();
    expect(del).toHaveBeenCalledWith(`/auth/sessions/${SESSIONS[1].id}`);
    // The list is re-read rather than patched locally: the server decides what is still active.
    expect(get.mock.calls.filter((call) => call[0] === '/auth/sessions').length).toBeGreaterThan(1);
    wrapper.unmount();
  });

  it('offers no control that names or reaches another user', async () => {
    const wrapper = await mountAccount();
    const labels = wrapper.findAll('button').map((b) => b.text().toLowerCase());
    for (const forbidden of ['invite', 'suspend user', 'edit user', 'assign role', 'remove access']) {
      expect(labels.some((label) => label.includes(forbidden)), `forbidden control: ${forbidden}`).toBe(false);
    }
    expect(wrapper.get('[data-testid="account-scope-note"]').text()).toContain('own account only');
  });

  it('offers the theme preference and names the preferences it does not have', async () => {
    const wrapper = await mountAccount();
    expect(wrapper.find('[data-testid="account-preferences"]').exists()).toBe(true);
    const unavailable = wrapper.get('[data-testid="account-preferences-unavailable"]').text();
    expect(unavailable).toContain('density');
    expect(unavailable).toContain('timezone override');
    expect(unavailable).toContain('Notifications runtime');
  });
});
