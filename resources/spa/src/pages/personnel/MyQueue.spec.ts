import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import MyQueue from '@/pages/personnel/MyQueue.vue';

const entry = {
  id: 'qe1',
  status: 'assigned',
  position: 1,
  queued_at: '2026-07-06T07:00:00+00:00',
  estimated_wait: { label: 'Estimate', effective_minutes: 10 },
  is_preferred_request: true,
  service: { id: 'sv1', name: 'Haircut', duration_minutes: 30 },
  client: { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678' },
};

const mountPage = () => mount(MyQueue);

describe('MyQueue.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('renders own-scope entries with status, service and a preferred indicator (no mutation controls)', async () => {
    get.mockResolvedValueOnce({ data: { data: [entry] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/personnel/me/queue', { params: { active: 'true', sort: 'position' } });
    expect(wrapper.text()).toContain('Amina Yusuf');
    expect(wrapper.text()).toContain('requested you');
    expect(wrapper.find('[data-testid="queue-status-badge"]').text()).toBe('Assigned');
    // Read-only: no action buttons.
    expect(wrapper.findAll('button').length).toBe(0);
  });

  it('shows the empty state when the queue is empty', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('You have no one in your queue right now.');
  });
});
