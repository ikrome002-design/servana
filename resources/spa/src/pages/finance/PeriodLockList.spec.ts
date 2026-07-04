import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import PeriodLockList from '@/pages/finance/PeriodLockList.vue';
import { useAuthStore } from '@/stores/authStore';

function lock(overrides: Record<string, unknown> = {}) {
  return {
    id: 'l1', scope: 'merchant', branch: null, period_start: '2026-06-01', period_end: '2026-06-30',
    status: 'locked', exception_required: false, reopen_reason: null, reopen_requested_at: null,
    reopen_approved_at: null, reopened_at: null, created_at: '2026-07-01T09:00:00Z', ...overrides,
  };
}

describe('finance/PeriodLockList.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('lets Finance create a lock and request a reopen', async () => {
    get.mockResolvedValue({ data: { data: [lock()] } });
    useAuthStore().permissions = ['period_lock.create', 'period_lock.reopen'];

    const wrapper = mount(PeriodLockList, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="period-lock-create-open"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="period-reopen-request"]').exists()).toBe(true);
  });

  it('shows execute (not request) once a reopen has been requested', async () => {
    get.mockResolvedValue({ data: { data: [lock({ reopen_requested_at: '2026-07-02T09:00:00Z' })] } });
    useAuthStore().permissions = ['period_lock.create', 'period_lock.reopen'];

    const wrapper = mount(PeriodLockList, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="period-reopen-request"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="period-reopen-execute"]').exists()).toBe(true);
  });

  it('hides create/reopen controls from a user without the Finance keys', async () => {
    get.mockResolvedValue({ data: { data: [lock()] } });
    useAuthStore().permissions = [];

    const wrapper = mount(PeriodLockList, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="period-lock-create-open"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="period-reopen-request"]').exists()).toBe(false);
  });
});
