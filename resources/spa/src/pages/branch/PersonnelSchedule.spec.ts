import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import PersonnelSchedule from '@/pages/branch/PersonnelSchedule.vue';
import { useAuthStore } from '@/stores/authStore';

const schedule = {
  staff: { id: 'p1', display_name: 'Jane Doe', employment_status: 'employed', is_active: true },
  timezone: 'Africa/Nairobi',
  current_state: 'on_break',
  recurring: [{ weekday: 1, start_time: '09:00', end_time: '17:00', available: true }],
  exceptions: [],
  eligible_services: [{ id: 's1', name: 'Haircut' }],
  can: { update: false },
};

function mockLoaded(): void {
  get.mockImplementation((url: string) => {
    if (url === '/staff') return Promise.resolve({ data: { data: [{ id: 'p1', display_name: 'Jane Doe' }] } });
    return Promise.resolve({ data: { data: structuredClone(schedule) } });
  });
}

const mountPage = () => mount(PersonnelSchedule);

async function selectStaff(wrapper: ReturnType<typeof mountPage>): Promise<void> {
  await wrapper.find('#bm-staff').setValue('p1');
  await flushPromises();
}

describe('PersonnelSchedule.vue (Branch Manager read-only)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    const auth = useAuthStore();
    auth.permissions = ['branch.dashboard.view'];
    auth.branchIds = ['b1'];
  });

  it('shows a no-permission state without branch.dashboard.view', async () => {
    const auth = useAuthStore();
    auth.permissions = [];
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="no-permission"]').exists()).toBe(true);
  });

  it('renders the read-only schedule, current state, and eligible services', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    expect(wrapper.find('[data-testid="bm-current-state"]').text()).toBe('On break');
    expect(wrapper.find('[data-testid="bm-today"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Haircut');
    expect(wrapper.text()).toContain('Jane Doe');
  });

  it('exposes NO edit, save, emergency, or eligibility controls', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    expect(wrapper.find('[data-testid="save-availability"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="open-emergency"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="add-working-1"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="day-off-1"]').exists()).toBe(false);
    expect(wrapper.findAll('button').filter((b) => /save|remove|add|emergency/i.test(b.text())).length).toBe(0);
  });
});
