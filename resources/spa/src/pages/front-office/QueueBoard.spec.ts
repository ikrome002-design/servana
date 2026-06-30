import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const put = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    put: (...a: unknown[]) => put(...a),
  },
}));

import QueueBoard from '@/pages/front-office/QueueBoard.vue';
import { useAuthStore } from '@/stores/authStore';

function entry(overrides: Record<string, unknown> = {}) {
  return {
    id: 'qe1',
    status: 'assigned',
    position: 1,
    assignment_mode: 'next_available',
    source: { type: 'walk_in', id: 'wi1' },
    queued_at: '2026-07-06T07:00:00+00:00',
    estimated_wait: { label: 'Estimate', minutes: 10, override_minutes: null, override_reason: null, effective_minutes: 10 },
    service: { id: 'sv1', name: 'Haircut', duration_minutes: 30 },
    client: { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678' },
    assigned_personnel: { id: 'st1', display_name: 'Bob Stylist' },
    preferred_personnel: null,
    can: { view: true, assign: false, call: true, start: false, complete: false, transfer: true, cancel: true, no_show: true },
    ...overrides,
  };
}

const mountPage = () => mount(QueueBoard, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('QueueBoard.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    put.mockReset();
  });

  it('renders an entry with position, status badge and masked client', async () => {
    get.mockResolvedValueOnce({ data: { data: [entry()] } });
    const auth = useAuthStore();
    auth.permissions = ['queue.view', 'queue.create'];
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Amina Yusuf');
    expect(wrapper.text()).toContain('Haircut');
    expect(wrapper.text()).toContain('Estimate');
    expect(wrapper.find('[data-testid="queue-status-badge"]').text()).toBe('Assigned');
  });

  it('gates the start-walk-in action on queue.create', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const auth = useAuthStore();
    auth.permissions = ['queue.view'];
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="start-walk-in"]').exists()).toBe(false);
  });

  it('calls the call action and refreshes the board', async () => {
    get.mockResolvedValue({ data: { data: [entry()] } });
    post.mockResolvedValue({ data: { data: entry({ status: 'called' }) } });
    const auth = useAuthStore();
    auth.permissions = ['queue.view', 'queue.create'];
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.findAll('button').find((b) => b.text() === 'Call')?.trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/queue-entries/qe1/call');
  });

  it('exposes keyboard-accessible move-up / move-down controls for waiting entries', async () => {
    const waiting = entry({
      status: 'waiting',
      can: { view: true, assign: true, call: false, start: false, complete: false, transfer: true, cancel: true, no_show: true },
    });
    get.mockResolvedValueOnce({ data: { data: [waiting, entry({ id: 'qe2', position: 2, status: 'waiting', can: { ...waiting.can } })] } });
    const auth = useAuthStore();
    auth.permissions = ['queue.view', 'queue.create'];
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="move-down-qe1"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="move-up-qe2"]').exists()).toBe(true);
  });
});
