import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const put = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), put: (...a: unknown[]) => put(...a) },
}));

import QueueConfiguration from '@/pages/branch/QueueConfiguration.vue';

const config = {
  branch_day_id: 'bd1',
  business_date: '2026-07-06',
  day_status: 'open',
  queue_is_open: true,
  effective_queue_open: true,
  queue_capacity: 5,
  queue_default_assignment_mode: 'next_available',
  active_count: 2,
};

const mountPage = () => mount(QueueConfiguration, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('QueueConfiguration.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    put.mockReset();
  });

  it('loads the current configuration and active count', async () => {
    get.mockResolvedValueOnce({ data: { data: config } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('2 active in the queue today.');
    expect((wrapper.find('[data-testid="queue-is-open"]').element as HTMLInputElement).checked).toBe(true);
  });

  it('saves the updated configuration', async () => {
    get.mockResolvedValueOnce({ data: { data: config } });
    put.mockResolvedValueOnce({ data: { data: { ...config, queue_capacity: 8 } } });
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.find('[data-testid="save-queue-config"]').trigger('submit');
    await flushPromises();

    expect(put).toHaveBeenCalledWith('/queue/configuration', {
      queue_is_open: true,
      queue_capacity: 5,
      queue_default_assignment_mode: 'next_available',
    });
  });
});
