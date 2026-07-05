import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import FinanceAuditView from '@/pages/finance/FinanceAuditView.vue';

const financeEvent = {
  id: 'a1',
  action: 'invoice.voided',
  severity: 'high',
  actor: 'f***@salon.co.ke',
  branch: 'b1',
  subject_type: 'Invoice',
  context: { amount: '***' },
  correlation_id: 'c1',
  created_at: '2026-07-05T00:00:00Z',
  can: { view: true },
};

const mountPage = () =>
  mount(FinanceAuditView, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('FinanceAuditView.vue (Finance-role finance.audit.view)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('reads the finance segment endpoint, reflects MFA-required access, and masks values', async () => {
    get.mockResolvedValueOnce({ data: { data: [financeEvent] } });
    const wrapper = mountPage();
    await flushPromises();

    // The Finance role hits the SAME finance segment endpoint (authorised by finance.audit.view).
    expect(get).toHaveBeenCalledWith('/audit-logs/finance', expect.anything());
    // MFA-required access is represented in the UI (the API is authoritative).
    expect(wrapper.find('[data-testid="audit-domain-mfa-note"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="audit-domain-row"]').text()).toContain('invoice.voided');
    expect(wrapper.text()).toContain('f***@salon.co.ke');
  });

  it('has no operational mutation or flagged-review controls (read-only)', async () => {
    get.mockResolvedValueOnce({ data: { data: [financeEvent] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('button[data-testid="flagged-start-review"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="flagged-resolve"]').exists()).toBe(false);
    // No validate/void/adjust operational controls on the audit surface.
    expect(wrapper.text().toLowerCase()).not.toContain('validate');
  });

  it('shows the error boundary when the backend denies the request (e.g. missing MFA)', async () => {
    get.mockRejectedValueOnce({ response: { status: 403, data: { error: { code: 'mfa_challenge_required' } } } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('couldn’t load');
  });

  it('shows an honest empty state when there are no finance audit events', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('No finance audit events');
  });
});
