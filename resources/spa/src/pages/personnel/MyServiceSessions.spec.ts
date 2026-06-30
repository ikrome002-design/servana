import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import MyServiceSessions from '@/pages/personnel/MyServiceSessions.vue';

const session = {
  id: 'ss1',
  status: 'in_progress',
  started_at: '2026-07-06T07:00:00+00:00',
  completed_at: null,
  cancelled_at: null,
  service: { id: 'sv1', name: 'Haircut', duration_minutes: 30 },
  client: { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678' },
};

const mountPage = () => mount(MyServiceSessions);

describe('MyServiceSessions.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('renders own-scope sessions with status and service (no mutation controls, no preview)', async () => {
    get.mockResolvedValueOnce({ data: { data: [session] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/personnel/me/sessions', { params: { sort: '-created_at' } });
    expect(wrapper.text()).toContain('Amina Yusuf');
    expect(wrapper.text()).toContain('Haircut');
    expect(wrapper.find('[data-testid="session-status-badge"]').text()).toBe('In progress');
    // Read-only own scope: no buttons, no commission preview wording.
    expect(wrapper.findAll('button').length).toBe(0);
    expect(wrapper.text()).not.toContain('Preview');
  });

  it('shows the empty state when there are no sessions', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('You have no service sessions yet.');
  });

  it('shows the error state with retry', async () => {
    get.mockRejectedValueOnce(new Error('network'));
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('We couldn’t load your sessions.');
  });
});
