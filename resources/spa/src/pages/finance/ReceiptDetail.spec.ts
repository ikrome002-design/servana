import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'r1' }, path: '/finance/receipts/r1' }),
  useRouter: () => ({ push: vi.fn() }),
}));

import ReceiptDetail from '@/pages/finance/ReceiptDetail.vue';
import { useAuthStore } from '@/stores/authStore';

function money(minor: number) {
  return { amount: minor, currency: 'KES', formatted: `KES ${(minor / 100).toFixed(2)}` };
}
function receipt(overrides: Record<string, unknown> = {}) {
  return {
    id: 'r1',
    receipt_number: 100,
    amount: money(200000),
    currency: 'KES',
    components: [{ method: 'cash', amount: money(200000) }],
    is_reissue: false,
    downloadable: true,
    file_generation_status: 'ready',
    created_at: '2026-07-03T09:00:00Z',
    invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001' },
    ...overrides,
  };
}

describe('finance/ReceiptDetail.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    vi.stubGlobal('open', vi.fn());
  });

  it('lets Finance reissue and download (reissue gated by receipt.reissue)', async () => {
    get.mockResolvedValue({ data: { data: receipt() } });
    useAuthStore().permissions = ['receipt.view', 'receipt.reissue'];

    const wrapper = mount(ReceiptDetail, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="receipt-download"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="receipt-reissue-open"]').exists()).toBe(true);

    post.mockResolvedValueOnce({ data: { data: { url: 'https://signed/download' } } });
    await wrapper.find('[data-testid="receipt-download"]').trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/receipts/r1/download-link', {});
  });

  it('hides reissue from Front Office (view + download only, no receipt.reissue)', async () => {
    get.mockResolvedValue({ data: { data: receipt() } });
    useAuthStore().permissions = ['receipt.view'];

    const wrapper = mount(ReceiptDetail, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="receipt-download"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="receipt-reissue-open"]').exists()).toBe(false);
  });
});
