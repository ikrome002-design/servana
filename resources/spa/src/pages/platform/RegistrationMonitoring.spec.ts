import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import RegistrationMonitoring from '@/pages/platform/RegistrationMonitoring.vue';
import { useAuthStore } from '@/stores/authStore';

const regRow = { id: '01MER', name: 'Acme Salon', operational_status: 'pending_setup', billing_status: 'trialing', pending_setup: true, registered_at: '2026-07-01T00:00:00Z', setup_completed_at: null };
function merchant(overrides: Record<string, unknown> = {}) {
  return {
    id: '01MER', name: 'Acme Salon', operational_status: 'active', billing_status: 'suspended_billing',
    billing_status_reason: null, suspension_reason: null,
    can: { suspend: true, reactivate: false, deactivate: true }, ...overrides,
  };
}

function mockApi() {
  get.mockImplementation((url: string) => {
    if (url === '/platform/registration-monitor') return Promise.resolve({ data: { data: [regRow] } });
    if (url === '/platform/merchants') return Promise.resolve({ data: { data: [merchant()] } });
    if (String(url).startsWith('/platform/merchants/')) return Promise.resolve({ data: { data: merchant() } });
    return Promise.resolve({ data: { data: null } });
  });
}

const ALL = ['platform.registration_monitor.view', 'platform.merchant.view', 'platform.merchant.suspend', 'platform.merchant.reactivate', 'platform.merchant.deactivate'];

describe('platform/RegistrationMonitoring.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('renders registration monitoring rows with operational and billing status', async () => {
    mockApi();
    useAuthStore().permissions = ALL;
    const wrapper = mount(RegistrationMonitoring, { attachTo: document.body });
    await flushPromises();
    expect(wrapper.text()).toContain('Acme Salon');
    expect(wrapper.text()).toContain('Pending');
  });

  it('shows operational and billing status as separate detail fields', async () => {
    mockApi();
    useAuthStore().permissions = ALL;
    const wrapper = mount(RegistrationMonitoring, { attachTo: document.body });
    await flushPromises();
    await wrapper.get('#tab-directory').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="merchant-row-01MER"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="operational-status"]').text()).toBe('Active');
    expect(wrapper.get('[data-testid="detail-billing-status"]').text()).toBe('Suspended');
  });

  it('requires a reason (≥3 chars) before a governance action can be confirmed', async () => {
    mockApi();
    post.mockResolvedValueOnce({ data: { data: merchant({ operational_status: 'suspended' }) } });
    useAuthStore().permissions = ALL;
    const wrapper = mount(RegistrationMonitoring, { attachTo: document.body });
    await flushPromises();
    await wrapper.get('#tab-directory').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="merchant-row-01MER"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="action-suspend"]').trigger('click');
    await flushPromises();

    const confirm = document.querySelector('[data-testid="confirm-governance"]') as HTMLButtonElement;
    expect(confirm.disabled).toBe(true); // no reason yet

    const textarea = document.querySelector('#governance-reason') as HTMLTextAreaElement;
    textarea.value = 'Fraud investigation';
    textarea.dispatchEvent(new Event('input'));
    await flushPromises();
    expect((document.querySelector('[data-testid="confirm-governance"]') as HTMLButtonElement).disabled).toBe(false);

    (document.querySelector('[data-testid="confirm-governance"]') as HTMLButtonElement).click();
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/platform/merchants/01MER/suspend', { reason: 'Fraud investigation' });
    wrapper.unmount();
  });

  it('hides reactivate when the server can-map disallows it', async () => {
    mockApi();
    useAuthStore().permissions = ALL;
    const wrapper = mount(RegistrationMonitoring, { attachTo: document.body });
    await flushPromises();
    await wrapper.get('#tab-directory').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="merchant-row-01MER"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="action-reactivate"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="action-suspend"]').exists()).toBe(true);
  });

  it('denies the surface without any governance permission', async () => {
    useAuthStore().permissions = [];
    const wrapper = mount(RegistrationMonitoring, { attachTo: document.body });
    await flushPromises();
    expect(wrapper.text()).toContain('do not have access');
    expect(get).not.toHaveBeenCalled();
  });
});
