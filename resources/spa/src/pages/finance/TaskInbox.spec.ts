import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }));

import TaskInbox from '@/pages/finance/TaskInbox.vue';
import { useAuthStore } from '@/stores/authStore';

describe('finance/TaskInbox.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    get.mockResolvedValue({ data: { data: [] } });
  });

  it('shows only the tiles the role is capable of (capability-gated)', async () => {
    useAuthStore().permissions = ['customer_payment.validate', 'cash_up.approve'];

    const wrapper = mount(TaskInbox, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="inbox-open-validations"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="inbox-open-cash-ups"]').exists()).toBe(true);
    // No refund/dispute/export/reopen capability → those tiles are absent.
    expect(wrapper.find('[data-testid="inbox-open-refund-approval"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="inbox-open-disputes"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="inbox-open-exports"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="inbox-open-reopens"]').exists()).toBe(false);
  });

  it('shows an empty state when the role has no Finance tasks', async () => {
    useAuthStore().permissions = [];

    const wrapper = mount(TaskInbox, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="inbox-tile"]').exists()).toBe(false);
  });
});
