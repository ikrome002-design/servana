import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();

vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    patch: (...a: unknown[]) => patch(...a),
  },
}));

import InternalPlatformAccess from '@/pages/platform/InternalPlatformAccess.vue';
import { useAuthStore } from '@/stores/authStore';

const VIEW = 'platform.internal_access.view';
const MANAGE = 'platform.internal_access.manage';

const membership = (overrides: Record<string, unknown> = {}) => ({
  id: '01JMEM00000000000000000001',
  user: {
    id: '01JUSER0000000000000000001',
    email: 'ops@citruslabs.co.ke',
    name: 'Ops Lead',
    status: 'active',
    mfa_enrolled: 'true',
    last_login_at: '2026-08-05T09:00:00Z',
  },
  role_key: 'super_admin',
  status: 'active',
  grants_access: true,
  active_session_count: '2',
  denied_permissions: [],
  invited_at: '2026-07-01T00:00:00Z',
  activated_at: '2026-07-02T00:00:00Z',
  suspended_at: null,
  deactivated_at: null,
  last_action: null,
  last_action_reason: null,
  last_action_at: null,
  ...overrides,
});

function respondWith(users: unknown[], invitations: unknown[] = []): void {
  get.mockImplementation((url: string) => {
    if (url === '/platform/internal-access/users') return Promise.resolve({ data: { data: users } });
    if (url === '/platform/internal-access/invitations') return Promise.resolve({ data: { data: invitations } });
    return Promise.resolve({ data: { data: [] } });
  });
}

async function mountWith(permissions: string[], userId = '01JOTHER000000000000000001') {
  const auth = useAuthStore();
  auth.permissions = permissions;
  auth.user = {
    id: userId,
    email: 'me@citruslabs.co.ke',
    name: 'Me',
    status: 'active',
    email_verified_at: null,
    is_platform_staff: true,
    theme_preference: null,
    resolved_theme: 'light',
  };
  const wrapper = mount(InternalPlatformAccess, { global: { stubs: { Teleport: true } } });
  await flushPromises();
  return wrapper;
}

describe('InternalPlatformAccess.vue — contract page §5.4.19', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
    respondWith([membership(), membership({ id: '01JMEM00000000000000000002', grants_access: true })]);
  });

  it('renders its own page title as the single h1', async () => {
    const wrapper = await mountWith([VIEW]);
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe('Internal platform access');
  });

  it('reads the shipped internal-access endpoints', async () => {
    await mountWith([VIEW]);
    const called = get.mock.calls.map((c) => c[0] as string);
    expect(called).toContain('/platform/internal-access/users');
    expect(called).toContain('/platform/internal-access/invitations');
  });

  it('renders the permission boundary and issues no request without the key', async () => {
    const wrapper = await mountWith([]);
    expect(wrapper.find('[data-testid="access-invite-open"]').exists()).toBe(false);
    expect(get).not.toHaveBeenCalled();
  });

  it('offers no lifecycle control to a view-only user', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="access-invite-open"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="access-actions"]').exists()).toBe(false);
  });

  /**
   * The authority boundary. A platform user can never be given a merchant role, merchant
   * membership, branch assignment or staff profile.
   *
   * Asserted on CONTROLS, not on words: the page's own guarantee sentence legitimately names
   * those things in order to rule them out, so a text ban would fail on the very copy that states
   * the boundary. What matters is that nothing selectable offers one.
   */
  it('offers no selectable merchant role — only the platform role, and it is read-only', async () => {
    const wrapper = await mountWith([VIEW, MANAGE]);
    await wrapper.find('[data-testid="access-invite-open"]').trigger('click');
    await flushPromises();

    // No role picker exists at all: the platform role is fixed and read-only.
    expect(wrapper.findAll('select')).toHaveLength(0);
    expect(wrapper.findAll('option')).toHaveLength(0);

    const roleField = wrapper.find('#access-invite-role');
    expect(roleField.attributes('readonly')).toBeDefined();
    expect((roleField.element as HTMLInputElement).value).toBe('super_admin');
  });

  it('presents permission overrides as deny-only, never as a grant', async () => {
    const wrapper = await mountWith([VIEW, MANAGE]);
    // The statement lives on the override dialog, so the dialog must be open to assert it.
    await wrapper.findAll('[data-testid="access-actions"] button')[0].trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Overrides are deny-only');
    expect(wrapper.text().toLowerCase()).not.toContain('grant permission');
    // The field is explicitly a DENY list; there is no grant counterpart anywhere in the dialog.
    expect(wrapper.find('#access-override-permissions').exists()).toBe(true);
    expect(wrapper.find('#access-override-grants').exists()).toBe(false);
  });

  it('previews the impact of a permission override before it is saved', async () => {
    const wrapper = await mountWith([VIEW, MANAGE]);
    await wrapper.findAll('[data-testid="access-actions"] button')[0].trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="access-override-preview"]').exists()).toBe(true);
  });

  it('warns before a change to the last account that grants access', async () => {
    respondWith([membership()]);
    const wrapper = await mountWith([VIEW, MANAGE]);
    expect(wrapper.find('[data-testid="access-sole-admin-warning"]').exists()).toBe(true);
  });

  it('does not raise the sole-admin warning when more than one account grants access', async () => {
    const wrapper = await mountWith([VIEW, MANAGE]);
    expect(wrapper.find('[data-testid="access-sole-admin-warning"]').exists()).toBe(false);
  });

  it('marks a user their own account so a self-change is visible before it is made', async () => {
    const wrapper = await mountWith([VIEW, MANAGE], '01JUSER0000000000000000001');
    expect(wrapper.find('[data-testid="access-self-warning-01JMEM00000000000000000001"]').exists()).toBe(true);
  });

  it('reports the count of revoked SESSIONS, using the corrected field name', async () => {
    post.mockResolvedValue({ data: { data: { sessions_revoked: 3 } } });
    const wrapper = await mountWith([VIEW, MANAGE]);

    const buttons = wrapper.findAll('[data-testid="access-actions"] button');
    const revoke = buttons.find((b) => b.text() === 'Revoke sessions');
    await revoke?.trigger('click');
    await wrapper.find('[data-testid="access-action-submit"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-testid="access-sessions-revoked"]').text()).toContain('3 session(s)');
  });

  it('keeps a server refusal visible rather than closing the dialog', async () => {
    post.mockRejectedValue(Object.assign(new Error('refused'), {
      apiError: { message: 'This change would leave no account with platform access.' },
    }));
    const wrapper = await mountWith([VIEW, MANAGE]);

    const buttons = wrapper.findAll('[data-testid="access-actions"] button');
    const suspend = buttons.find((b) => b.text() === 'Suspend');
    await suspend?.trigger('click');
    await wrapper.find('[data-testid="access-action-submit"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-testid="access-action-error"]').text()).toContain('no account with platform access');
  });

  it('surfaces a retryable error state', async () => {
    get.mockRejectedValue(new Error('network'));
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="access-retry"]').exists()).toBe(true);
  });

  it('shows an empty roster state rather than a blank page', async () => {
    respondWith([]);
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.text()).toContain('No platform user has been recorded.');
  });
});
