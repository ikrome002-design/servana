import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'ap1' } }),
}));

import AppointmentDetail from '@/pages/front-office/AppointmentDetail.vue';

function appointmentWith(can: Record<string, boolean>) {
  return {
    id: 'ap1',
    status: 'scheduled',
    starts_at: '2026-07-06T07:00:00+00:00',
    ends_at: '2026-07-06T08:00:00+00:00',
    checked_in_at: null,
    cancelled_at: null,
    no_show_at: null,
    cancellation_reason: null,
    service: { id: 'sv1', name: 'Haircut', duration_minutes: 60 },
    client: { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678' },
    assigned_personnel: null,
    can,
  };
}

const mountPage = () =>
  mount(AppointmentDetail, {
    global: {
      stubs: { RouterLink: { template: '<a><slot /></a>' } },
      mocks: { $route: { params: { id: 'ap1' } } },
    },
  });

describe('AppointmentDetail.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('shows only the actions allowed by the capability map', async () => {
    get.mockResolvedValueOnce({ data: { data: appointmentWith({ view: true, assign: true, cancel: true, transfer: false, reschedule: false, check_in: false, mark_no_show: false }) } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="action-assign"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="action-cancel"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="action-transfer"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="action-check-in"]').exists()).toBe(false);
  });

  it('renders no mutation controls for a read-only (all-false) capability map', async () => {
    get.mockResolvedValueOnce({ data: { data: appointmentWith({ view: true, assign: false, cancel: false, transfer: false, reschedule: false, check_in: false, mark_no_show: false }) } });
    const wrapper = mountPage();
    await flushPromises();

    for (const action of ['assign', 'transfer', 'reschedule', 'cancel', 'check-in', 'no-show']) {
      expect(wrapper.find(`[data-testid="action-${action}"]`).exists()).toBe(false);
    }
    expect(wrapper.find('[data-testid="status-badge"]').text()).toBe('Scheduled');
  });
});
