import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const put = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    put: (...a: unknown[]) => put(...a),
    post: (...a: unknown[]) => post(...a),
  },
}));

import CashUp from '@/pages/branch/CashUp.vue';
import { useAuthStore } from '@/stores/authStore';

// The component derives the business date from the real clock
// (new Date().toISOString().slice(0, 10)); mirror that so the test is
// date-independent rather than coupled to a single authoring day.
const today = new Date().toISOString().slice(0, 10);
function money(minor: number) {
  return { amount: minor, currency: 'KES', formatted: `KES ${(minor / 100).toFixed(2)}` };
}
function preview(overrides: Record<string, unknown> = {}) {
  return {
    id: null,
    business_date: today,
    status: 'draft',
    expected: money(300000),
    counted: money(0),
    variance: money(-300000),
    expected_minor: 300000,
    counted_minor: 0,
    variance_minor: -300000,
    lines: [{ method: 'cash', expected_minor: 300000, counted_minor: 0, variance_minor: -300000 }],
    ...overrides,
  };
}

describe('branch/CashUp.vue (Branch Manager maker)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    put.mockReset();
    post.mockReset();
  });

  it('lets a Branch Manager enter counts and submit — with NO approval control', async () => {
    get.mockResolvedValue({ data: { data: preview() } });
    const auth = useAuthStore();
    auth.permissions = ['branch.cash_up.submit'];
    auth.branchIds = ['b1'];

    const wrapper = mount(CashUp, { attachTo: document.body });
    await flushPromises();

    // Server-derived expected is shown and there is a counted input.
    expect(wrapper.find('[data-testid="counted-cash"]').exists()).toBe(true);
    // The maker can submit but there is NO approve/reject/lock control anywhere.
    expect(wrapper.find('[data-testid="cash-up-submit"]').exists()).toBe(true);
    expect(wrapper.html()).not.toContain('cash-up-approve');
    expect(wrapper.html()).not.toContain('cash-up-lock');

    // Enter a counted amount → save draft PUTs the counts.
    put.mockResolvedValueOnce({ data: { data: preview({ id: 'cu1', counted_minor: 300000, counted: money(300000), variance_minor: 0, variance: money(0), lines: [{ method: 'cash', expected_minor: 300000, counted_minor: 300000, variance_minor: 0 }] }) } });
    await wrapper.find('[data-testid="counted-cash"]').setValue('300000');
    await wrapper.find('[data-testid="cash-up-save"]').trigger('click');
    await flushPromises();
    expect(put).toHaveBeenCalledWith(`/branches/b1/cash-ups/${today}`, { counts: [{ method: 'cash', counted_minor: 300000 }] });
  });

  it('shows a read-only state for a submitted cash-up (no edit controls)', async () => {
    get.mockResolvedValue({ data: { data: preview({ id: 'cu1', status: 'submitted' }) } });
    const auth = useAuthStore();
    auth.permissions = ['branch.cash_up.submit'];
    auth.branchIds = ['b1'];

    const wrapper = mount(CashUp, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="cash-up-submit"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="counted-cash"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('can no longer be edited');
  });

  it('shows a no-branch boundary when the manager is not assigned to a branch', async () => {
    const auth = useAuthStore();
    auth.permissions = ['branch.cash_up.submit'];
    auth.branchIds = [];

    const wrapper = mount(CashUp, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.text()).toContain('not assigned to a branch');
  });
});
