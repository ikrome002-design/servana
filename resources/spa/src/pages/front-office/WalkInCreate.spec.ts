import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }));

import WalkInCreate from '@/pages/front-office/WalkInCreate.vue';

const service = { id: 'sv1', name: 'Haircut', duration_minutes: 30, status: 'active' };

const mountPage = () => mount(WalkInCreate, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('WalkInCreate.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    push.mockReset();
    // services then clients on mount.
    get.mockResolvedValue({ data: { data: [service] } });
  });

  it('disables submit until a client and service are chosen, then creates the walk-in', async () => {
    const wrapper = mountPage();
    await flushPromises();

    const submit = () => wrapper.find('[data-testid="submit-walk-in"]');
    expect(submit().attributes('disabled')).toBeDefined();

    // New-client path.
    await wrapper.find('[data-testid="client-new"]').trigger('click');
    await wrapper.find('#new-client-name').setValue('Walkin Wanjiku');
    await wrapper.find('#new-client-phone').setValue('0712345678');
    await wrapper.find('#walk-in-service').setValue('sv1');
    await flushPromises();

    expect(submit().attributes('disabled')).toBeUndefined();

    post.mockResolvedValueOnce({ data: { data: { id: 'qe9' } } });
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/walk-ins', expect.objectContaining({
      assignment_mode: 'next_available',
      service: 'sv1',
      client: null,
      new_client: { full_name: 'Walkin Wanjiku', phone: '0712345678' },
    }));
    expect(push).toHaveBeenCalledWith({ name: 'front-office.queue.detail', params: { id: 'qe9' } });
  });
});
