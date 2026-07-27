import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createRouter, createWebHistory } from 'vue-router';
import BranchCalendar from '@/pages/branch/BranchCalendar.vue';
import { apiClient } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';

/**
 * REM-SCR-002B — the Plan §27.3 Branch Manager branch-calendar screen.
 * Frontend gating is UX only; these specs prove the screen talks to the right endpoints, keeps the
 * closure/modified-hours rule visible, surfaces the conflict and billing codes, and exposes no
 * internal identifier.
 */

const HOLIDAY = {
  date: '2026-12-12',
  type: 'public_holiday' as const,
  closes_branch: true,
  opens_at: null,
  closes_at: null,
  reason: 'Jamhuri Day',
  created_at: '2026-07-27T00:00:00+00:00',
};

const MODIFIED = {
  date: '2026-12-24',
  type: 'modified_hours' as const,
  closes_branch: false,
  opens_at: '09:00:00',
  closes_at: '13:00:00',
  reason: null,
  created_at: '2026-07-27T00:00:00+00:00',
};

function router() {
  return createRouter({
    history: createWebHistory(),
    routes: [{ path: '/branch/:id/calendar', name: 'branch.calendar', component: BranchCalendar }],
  });
}

function signIn(permissions: string[]): void {
  const auth = useAuthStore();
  auth.applyBootstrap({
    user: { id: 'u1', email: 'a@b.co', name: 'A', status: 'active', email_verified_at: null, is_platform_staff: false },
    merchant: { id: 'm1', name: 'Glow', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
    membership: { id: 'mm1', role: 'branch_manager', status: 'active' },
    memberships: [],
    permissions,
    branch_ids: ['b1'],
  } as never);
}

async function mountScreen(permissions: string[]) {
  const r = router();
  r.push({ name: 'branch.calendar', params: { id: 'b1' } });
  await r.isReady();
  signIn(permissions);
  const wrapper = mount(BranchCalendar, { global: { plugins: [r] } });
  await flushPromises();
  return wrapper;
}

describe('BranchCalendar', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('loads the branch calendar and renders each exception with its hours', async () => {
    const get = vi.spyOn(apiClient, 'get').mockResolvedValue({
      data: { data: [HOLIDAY, MODIFIED], meta: { from: '2026-12-01', to: '2027-03-03' } },
    } as never);

    const wrapper = await mountScreen(['branch.calendar.manage']);

    expect(get).toHaveBeenCalledWith('/branches/b1/calendar-exceptions');
    expect(wrapper.text()).toContain('Public holiday');
    expect(wrapper.text()).toContain('Closed all day');
    expect(wrapper.text()).toContain('Modified hours');
    expect(wrapper.text()).toContain('09:00:00 – 13:00:00');
    // The row identity is the date; no internal id is present anywhere.
    expect(wrapper.html()).not.toContain('branch_id');
    expect(wrapper.html()).not.toContain('merchant_id');
  });

  it('only asks for opening hours when the type needs a window', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [] } } as never);
    const wrapper = await mountScreen(['branch.calendar.manage']);

    // A closure type is the default → no window inputs.
    expect(wrapper.find('#bc-opens-at').exists()).toBe(false);

    await wrapper.find('#bc-type').setValue('modified_hours');
    expect(wrapper.find('#bc-opens-at').exists()).toBe(true);
    expect(wrapper.find('#bc-closes-at').exists()).toBe(true);
  });

  it('POSTs a closure with null hours and refetches', async () => {
    const get = vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [] } } as never);
    const post = vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: HOLIDAY } } as never);

    const wrapper = await mountScreen(['branch.calendar.manage']);
    await wrapper.find('#bc-date').setValue('2026-12-12');
    await wrapper.find('#bc-reason').setValue('Jamhuri Day');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/branches/b1/calendar-exceptions', {
      date: '2026-12-12',
      type: 'public_holiday',
      opens_at: null,
      closes_at: null,
      reason: 'Jamhuri Day',
    });
    // The list is re-read from the server rather than optimistically patched.
    expect(get).toHaveBeenCalledTimes(2);
  });

  it('shows the duplicate-date conflict from the API envelope', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [] } } as never);
    vi.spyOn(apiClient, 'post').mockRejectedValue({
      response: { data: { error: { code: 'calendar_exception_exists', fields: {} } } },
    } as never);

    const wrapper = await mountScreen(['branch.calendar.manage']);
    await wrapper.find('#bc-date').setValue('2026-12-12');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    const alert = wrapper.find('[role="alert"]');
    expect(alert.exists()).toBe(true);
    expect(alert.text()).toContain('already has an exception');
  });

  it('explains the billing read-only block instead of failing silently', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [] } } as never);
    vi.spyOn(apiClient, 'post').mockRejectedValue({
      response: { data: { error: { code: 'billing_read_only', fields: {} } } },
    } as never);

    const wrapper = await mountScreen(['branch.calendar.manage']);
    await wrapper.find('#bc-date').setValue('2026-12-12');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(wrapper.find('[role="alert"]').text()).toContain('read-only');
  });

  it('DELETEs by date, never by an internal id', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [HOLIDAY] } } as never);
    const del = vi.spyOn(apiClient, 'delete').mockResolvedValue({ data: null } as never);

    const wrapper = await mountScreen(['branch.calendar.manage']);
    const remove = wrapper.findAll('button').find((b) => b.text() === 'Remove');
    await remove?.trigger('click');
    await flushPromises();

    expect(del).toHaveBeenCalledWith('/branches/b1/calendar-exceptions/2026-12-12');
  });

  it('edits a modified-hours window without offering to change the date or type', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [MODIFIED] } } as never);
    const patch = vi.spyOn(apiClient, 'patch').mockResolvedValue({ data: { data: MODIFIED } } as never);

    const wrapper = await mountScreen(['branch.calendar.manage']);
    await wrapper.findAll('button').find((b) => b.text() === 'Edit')?.trigger('click');
    await flushPromises();

    // The editor exposes the window and the reason only.
    expect(wrapper.find('#bc-edit-opens-2026-12-24').exists()).toBe(true);
    expect(wrapper.find('#bc-edit-reason-2026-12-24').exists()).toBe(true);
    expect(wrapper.text()).toContain('Editing 2026-12-24');

    await wrapper.find('#bc-edit-opens-2026-12-24').setValue('10:00');
    await wrapper.findAll('form')[1]?.trigger('submit');
    await flushPromises();

    const [url, payload] = patch.mock.calls[0] as [string, Record<string, unknown>];
    expect(url).toBe('/branches/b1/calendar-exceptions/2026-12-24');
    expect(payload).not.toHaveProperty('date');
    expect(payload).not.toHaveProperty('type');
    expect(payload.opens_at).toBe('10:00');
  });

  it('sends no window when editing a full-day closure', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [HOLIDAY] } } as never);
    const patch = vi.spyOn(apiClient, 'patch').mockResolvedValue({ data: { data: HOLIDAY } } as never);

    const wrapper = await mountScreen(['branch.calendar.manage']);
    await wrapper.findAll('button').find((b) => b.text() === 'Edit')?.trigger('click');
    await flushPromises();

    // A closure has no window inputs at all — the API rejects times on one.
    expect(wrapper.find('#bc-edit-opens-2026-12-12').exists()).toBe(false);

    await wrapper.findAll('form')[1]?.trigger('submit');
    await flushPromises();

    const [, payload] = patch.mock.calls[0] as [string, Record<string, unknown>];
    expect(payload).not.toHaveProperty('opens_at');
    expect(payload).not.toHaveProperty('closes_at');
  });

  it('hides every mutation control and offers no create form without the permission', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [HOLIDAY] } } as never);

    const wrapper = await mountScreen([]);

    expect(wrapper.find('#bc-date').exists()).toBe(false);
    expect(wrapper.findAll('button').some((b) => b.text() === 'Remove')).toBe(false);
    expect(wrapper.findAll('button').some((b) => b.text() === 'Edit')).toBe(false);
    expect(wrapper.text()).toContain('view-only access');
  });

  it('shows a useful empty state when the period has no exceptions', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [] } } as never);

    const wrapper = await mountScreen(['branch.calendar.manage']);

    expect(wrapper.text()).toContain('No calendar exceptions in this period');
  });

  it('never calls an availability or appointment mutation endpoint', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [] } } as never);
    const post = vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: HOLIDAY } } as never);

    const wrapper = await mountScreen(['branch.calendar.manage']);
    await wrapper.find('#bc-date').setValue('2026-12-12');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    for (const call of post.mock.calls) {
      const url = String(call[0]);
      expect(url).not.toContain('availability');
      expect(url).not.toContain('appointments');
      expect(url).not.toContain('/day/');
      expect(url).not.toContain('/staff');
    }
  });
});
