import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import FinanceExportList from '@/pages/finance/FinanceExportList.vue';
import { useAuthStore } from '@/stores/authStore';

function exp(overrides: Record<string, unknown> = {}) {
  return {
    id: 'e1', export_type: 'payments', scope: 'merchant', branch: null, status: 'ready',
    reason: 'x', row_count: 3, download_count: 0, expires_at: null,
    first_downloaded_at: null, last_downloaded_at: null, failure_code: null, failure_message: null,
    created_at: '2026-07-03T09:00:00Z', ...overrides,
  };
}

describe('finance/FinanceExportList.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    vi.stubGlobal('open', vi.fn());
  });

  it('offers only the supported export types (never compensation/payouts/billing)', async () => {
    get.mockResolvedValue({ data: { data: [exp()] } });
    useAuthStore().permissions = ['finance_export.create', 'finance_export.download'];

    const wrapper = mount(FinanceExportList, { attachTo: document.body });
    await flushPromises();
    await wrapper.find('[data-testid="export-request-open"]').trigger('click');

    const html = document.body.innerHTML;
    for (const t of ['invoices', 'payments', 'receipts', 'cash_up', 'refunds', 'disputes']) {
      expect(html).toContain(t);
    }
    expect(html).not.toContain('compensation');
    expect(html).not.toContain('payouts');
    expect(html).not.toContain('billing');
  });

  it('downloads a ready export via a signed link and never stores the URL', async () => {
    get.mockResolvedValue({ data: { data: [exp()] } });
    post.mockResolvedValueOnce({ data: { data: { url: 'https://signed/x' } } });
    useAuthStore().permissions = ['finance_export.create', 'finance_export.download'];

    const wrapper = mount(FinanceExportList, { attachTo: document.body });
    await flushPromises();
    await wrapper.find('[data-testid="export-download"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/finance-exports/e1/download-link', {});
    expect(window.localStorage.getItem('export-url')).toBeNull();
  });

  it('hides request + revoke from a user without finance_export.create', async () => {
    get.mockResolvedValue({ data: { data: [exp()] } });
    useAuthStore().permissions = ['finance_export.download'];

    const wrapper = mount(FinanceExportList, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="export-request-open"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="export-revoke"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="export-download"]').exists()).toBe(true);
  });
});
