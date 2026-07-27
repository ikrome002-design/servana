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
    // Phase 23 §14.1 — the picker is fed by the NARROW branch personnel-options endpoint,
    // never by the HR roster `/staff` (now gated by the HR-only `staff.view`).
    if (url === '/branch/personnel-options') {
      return Promise.resolve({ data: { data: [{ id: 'p1', display_name: 'Jane Doe' }] } });
    }
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

  it('loads the picker from the narrow personnel-options endpoint and NEVER from the HR roster', async () => {
    mockLoaded();
    mountPage();
    await flushPromises();

    const urls = get.mock.calls.map((c) => c[0] as string);
    expect(urls).toContain('/branch/personnel-options');
    expect(urls).not.toContain('/staff');
    expect(urls.some((u) => u.startsWith('/staff'))).toBe(false);
  });

  it('clears stale options and the selection when the branch context changes', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);
    expect(wrapper.text()).toContain('Jane Doe');

    const auth = useAuthStore();
    get.mockImplementation((url: string) => {
      if (url === '/branch/personnel-options') {
        return Promise.resolve({ data: { data: [{ id: 'p2', display_name: 'Other Branch Person' }] } });
      }
      return Promise.resolve({ data: { data: structuredClone(schedule) } });
    });
    auth.branchIds = ['b2'];
    await flushPromises();

    // The previous branch's personnel must not remain selectable or selected.
    expect(wrapper.text()).not.toContain('Jane Doe');
    expect((wrapper.find('#bm-staff').element as HTMLSelectElement).value).toBe('');
  });

  it('never renders a personnel phone number (the options payload carries none)', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await selectStaff(wrapper);

    expect(wrapper.html()).not.toMatch(/\+?254\d{6,}/);
    expect(wrapper.html()).not.toContain('phone');
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
