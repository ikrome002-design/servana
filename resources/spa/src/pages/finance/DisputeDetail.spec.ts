import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'd1' }, path: '/finance/disputes/d1' }),
  useRouter: () => ({ push: vi.fn() }),
}));

import DisputeDetail from '@/pages/finance/DisputeDetail.vue';
import { useAuthStore } from '@/stores/authStore';

function dispute(overrides: Record<string, unknown> = {}) {
  return {
    id: 'd1', status: 'open', reason: 'Client disputes the charge.', resolution_note: null,
    has_evidence: false, created_at: '2026-07-03T09:00:00Z', updated_at: '2026-07-03T09:00:00Z',
    invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001' }, payment_record: null, ...overrides,
  };
}

describe('finance/DisputeDetail.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('starts review then resolves with a mandatory note; the source is stated read-only', async () => {
    get.mockResolvedValue({ data: { data: dispute({ status: 'under_review' }) } });
    useAuthStore().permissions = ['finance_dispute.manage'];

    const wrapper = mount(DisputeDetail, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.text()).toContain('read-only');
    expect(wrapper.find('[data-testid="dispute-resolve"]').exists()).toBe(true);
    await wrapper.find('[data-testid="dispute-resolve"]').trigger('click');

    const confirm = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.getAttribute('data-testid') === 'dispute-decision-confirm',
    ) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true); // note required
  });

  it('hides management controls without finance_dispute.manage', async () => {
    get.mockResolvedValue({ data: { data: dispute({ status: 'under_review' }) } });
    useAuthStore().permissions = [];

    const wrapper = mount(DisputeDetail, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="dispute-resolve"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="dispute-start-review"]').exists()).toBe(false);
  });
});
