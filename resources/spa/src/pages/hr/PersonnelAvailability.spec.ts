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

vi.mock('vue-router', () => ({
  onBeforeRouteLeave: vi.fn(),
  RouterLink: { template: '<a><slot /></a>' },
}));

import PersonnelAvailability from '@/pages/hr/PersonnelAvailability.vue';
import { useAuthStore } from '@/stores/authStore';

const schedule = {
  staff: { id: 'p1', display_name: 'Jane Doe', employment_status: 'employed', is_active: true },
  timezone: 'Africa/Nairobi',
  current_state: 'available',
  recurring: [
    { weekday: 1, start_time: '09:00', end_time: '13:00', available: true },
    { weekday: 1, start_time: '13:00', end_time: '14:00', available: false },
  ],
  exceptions: [{ date: '2026-07-10', start_time: '09:00', end_time: '12:00', available: false }],
  eligible_services: [{ id: 's1', name: 'Haircut' }],
  can: { update: true },
};

function mockLoaded(): void {
  get.mockImplementation((url: string) => {
    if (url === '/staff') return Promise.resolve({ data: { data: [{ id: 'p1', display_name: 'Jane Doe' }] } });
    return Promise.resolve({ data: { data: structuredClone(schedule) } });
  });
}

const mountPage = () =>
  mount(PersonnelAvailability, {
    global: {
      stubs: {
        RouterLink: { template: '<a><slot /></a>' },
        SvDialog: { template: '<div v-if="open"><slot /></div>', props: ['open', 'title'] },
      },
    },
  });

async function selectStaff(wrapper: ReturnType<typeof mountPage>): Promise<void> {
  await wrapper.find('#staff').setValue('p1');
  await flushPromises();
}

describe('PersonnelAvailability.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    put.mockReset();
    post.mockReset();
    const auth = useAuthStore();
    auth.permissions = ['personnel.availability.manage'];
    auth.branchIds = ['b1'];
  });

  it('shows a no-permission state without the manage key', async () => {
    const auth = useAuthStore();
    auth.permissions = [];
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="no-permission"]').exists()).toBe(true);
  });

  it('shows a no-branch state when the user has no branch', async () => {
    const auth = useAuthStore();
    auth.branchIds = [];
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="no-branch"]').exists()).toBe(true);
  });

  it('prompts to select personnel before any schedule loads', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.text()).toContain('Select a personnel member');
  });

  it('loads a schedule, current state, and eligible services on selection', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    expect(wrapper.find('[data-testid="current-state"]').text()).toBe('Available');
    expect(wrapper.text()).toContain('Haircut');
    expect(wrapper.find('[data-testid="working-1"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="break-1"]').exists()).toBe(true);
  });

  it('links to the eligibility management screen', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);
    expect(wrapper.text()).toContain('Manage eligibility');
  });

  it('adds a working interval (split shift) and a break, then marks dirty', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    await wrapper.find('[data-testid="add-working-1"]').trigger('click');
    await wrapper.find('[data-testid="add-break-1"]').trigger('click');

    expect(wrapper.findAll('[data-testid="working-1"]').length).toBe(2);
    expect(wrapper.find('[data-testid="unsaved-indicator"]').exists()).toBe(true);
  });

  it('clears a weekday as a day off', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    await wrapper.find('[data-testid="day-off-1"]').trigger('click');
    expect(wrapper.find('[data-testid="working-1"]').exists()).toBe(false);
  });

  it('adds a date exception', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    await wrapper.find('[data-testid="add-exception"]').trigger('click');
    expect(wrapper.findAll('[data-testid^="exception-"]').length).toBe(2);
  });

  it('requires a change reason before saving', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    await wrapper.find('[data-testid="day-off-1"]').trigger('click'); // dirty, but no reason
    expect((wrapper.find('[data-testid="save-availability"]').element as HTMLButtonElement).disabled).toBe(true);
  });

  it('saves atomically with a reason and shows success', async () => {
    mockLoaded();
    put.mockResolvedValue({ data: { data: structuredClone(schedule) } });
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    await wrapper.find('[data-testid="day-off-1"]').trigger('click');
    await wrapper.find('#change-reason').setValue('Reduced hours');
    await wrapper.find('[data-testid="save-availability"]').trigger('click');
    await flushPromises();

    expect(put).toHaveBeenCalledWith('/staff/p1/availability', expect.objectContaining({ change_reason: 'Reduced hours' }));
  });

  it('surfaces server validation errors in the summary', async () => {
    mockLoaded();
    const error = Object.assign(new Error('invalid'), {
      isAxiosError: true,
      apiError: { code: 'validation_failed', message: 'The given data was invalid.', fields: { 'recurring.0': ['Overlap'] } },
    });
    put.mockRejectedValue(error);
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    await wrapper.find('[data-testid="day-off-1"]').trigger('click');
    await wrapper.find('#change-reason').setValue('Bad');
    await wrapper.find('[data-testid="save-availability"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-testid="validation-summary"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="validation-summary"]').text()).toContain('Overlap');
  });

  it('submits emergency unavailability', async () => {
    mockLoaded();
    post.mockResolvedValue({ data: { data: structuredClone(schedule) } });
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    await wrapper.find('[data-testid="open-emergency"]').trigger('click');
    await wrapper.find('#em-date').setValue('2026-07-13');
    await wrapper.find('#em-start').setValue('14:00');
    await wrapper.find('#em-end').setValue('17:00');
    await wrapper.find('#em-reason').setValue('Family emergency');
    await wrapper.find('[data-testid="submit-emergency"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
      '/staff/p1/availability/emergency-unavailable',
      expect.objectContaining({ date: '2026-07-13', change_reason: 'Family emergency' }),
    );
  });
});
