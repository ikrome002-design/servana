import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import PeriodReopenApprovals from '@/pages/merchant/PeriodReopenApprovals.vue';
import { useAuthStore } from '@/stores/authStore';

function lock(overrides: Record<string, unknown> = {}) {
  return {
    id: 'l1', scope: 'merchant', branch: null, period_start: '2026-06-01', period_end: '2026-06-30',
    status: 'locked', exception_required: true, reopen_reason: 'Correcting a posting error.',
    reopen_requested_at: '2026-07-02T09:00:00Z', reopen_approved_at: null, reopened_at: null,
    created_at: '2026-07-01T09:00:00Z', ...overrides,
  };
}

describe('merchant/PeriodReopenApprovals.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('lets a Merchant Administrator approve a pending exceptional reopen — with NO lock/execute controls', async () => {
    get.mockResolvedValue({ data: { data: [lock()] } });
    useAuthStore().permissions = ['merchant.period_reopen.approve_exception'];

    const wrapper = mount(PeriodReopenApprovals, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="reopen-approve"]').exists()).toBe(true);
    // The Merchant Administrator has NO routine locking or reopen-execution controls.
    expect(wrapper.html()).not.toContain('period-lock-create');
    expect(wrapper.html()).not.toContain('period-reopen-execute');

    post.mockResolvedValueOnce({ data: { data: lock({ reopen_approved_at: '2026-07-03T09:00:00Z' }) } });
    get.mockResolvedValue({ data: { data: [] } });
    await wrapper.find('[data-testid="reopen-approve"]').trigger('click');
    await flushPromises();
    const posted = post.mock.calls.find((c) => String(c[0]).endsWith('/reopen/approve'));
    expect(posted?.[2]?.headers?.['Idempotency-Key']).toBeTruthy();
  });

  it('shows an empty state and no controls without the approval capability', async () => {
    get.mockResolvedValue({ data: { data: [lock()] } });
    useAuthStore().permissions = [];

    const wrapper = mount(PeriodReopenApprovals, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="reopen-approve"]').exists()).toBe(false);
  });
});
