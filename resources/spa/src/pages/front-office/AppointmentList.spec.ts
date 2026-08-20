import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import AppointmentList from '@/pages/front-office/AppointmentList.vue';
import { useAuthStore } from '@/stores/authStore';

const appointment = {
  id: 'ap1',
  status: 'confirmed',
  starts_at: '2026-07-06T07:00:00+00:00',
  ends_at: '2026-07-06T08:00:00+00:00',
  checked_in_at: null,
  cancelled_at: null,
  no_show_at: null,
  cancellation_reason: null,
  service: { id: 'sv1', name: 'Haircut', duration_minutes: 60 },
  client: { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678' },
  assigned_personnel: { id: 'st1', display_name: 'Bob Stylist' },
};

const mountPage = () =>
  mount(AppointmentList, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('AppointmentList.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('renders the appointment with a status badge and masked client', async () => {
    get.mockResolvedValueOnce({ data: { data: [appointment] } });
    const auth = useAuthStore();
    auth.permissions = ['appointment.view', 'appointment.create'];
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Amina Yusuf');
    expect(wrapper.text()).toContain('Haircut');
    expect(wrapper.find('[data-testid="status-badge"]').text()).toContain('Confirmed');
  });

  it('applies the date filter', async () => {
    get.mockResolvedValue({ data: { data: [] } });
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.find('#appointment-date').setValue('2026-07-06');
    await wrapper.find('[data-testid="filter-appointments"]').trigger('submit');
    await flushPromises();

    expect(get).toHaveBeenLastCalledWith('/appointments', { params: { date: '2026-07-06' } });
  });

  it('gates the book-appointment action on appointment.create', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const auth = useAuthStore();
    auth.permissions = ['appointment.view'];
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="add-appointment"]').exists()).toBe(false);
  });
});
