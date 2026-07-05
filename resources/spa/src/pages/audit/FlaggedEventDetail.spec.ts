import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'f1' } }),
  RouterLink: { template: '<a><slot /></a>' },
}));

import FlaggedEventDetail from '@/pages/audit/FlaggedEventDetail.vue';

function flagged(status: string, can: Record<string, boolean>) {
  return {
    id: 'f1',
    status,
    review_notes: null,
    assigned_to: null,
    resolved_by: null,
    created_at: null,
    updated_at: null,
    audit_event: { id: 'a1', action: 'invoice.created', severity: 'info', actor: 'j***@x.co', subject_type: 'Invoice', context: {}, occurred_at: null },
    can,
  };
}

const mountPage = () =>
  mount(FlaggedEventDetail, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('FlaggedEventDetail.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('shows start-review only when open + update_status capability', async () => {
    get.mockResolvedValueOnce({ data: { data: flagged('open', { update_status: true }) } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="flagged-start-review"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="flagged-resolve"]').exists()).toBe(false);
    // Source event is shown read-only — no mutation control on the source card.
    expect(wrapper.find('[data-testid="flagged-source"]').exists()).toBe(true);
  });

  it('shows resolve + dismiss only when under_review + resolve_metadata capability', async () => {
    get.mockResolvedValueOnce({ data: { data: flagged('under_review', { resolve_metadata: true, update_status: true }) } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="flagged-resolve"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="flagged-dismiss"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="flagged-start-review"]').exists()).toBe(false);
  });

  it('shows reopen only for a terminal event with update_status capability', async () => {
    get.mockResolvedValueOnce({ data: { data: flagged('resolved', { update_status: true }) } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="flagged-reopen"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="flagged-resolve"]').exists()).toBe(false);
  });

  it('hides every action when the server grants no capability (permission-denied absent)', async () => {
    get.mockResolvedValueOnce({ data: { data: flagged('under_review', {}) } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="flagged-start-review"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="flagged-resolve"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="flagged-dismiss"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="flagged-reopen"]').exists()).toBe(false);
  });
});
