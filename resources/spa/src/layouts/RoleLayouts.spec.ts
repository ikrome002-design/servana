import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import AppShell from '@/components/layout/AppShell.vue';
import { useAuthStore } from '@/stores/authStore';
import type { RoleIdentity } from '@/types/roles';

const stub = { template: '<div />' };

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'test.home', component: stub },
      // Phase 22 global search is in every merchant-role navigation, so the test router must
      // resolve it or RouterLink cannot render the item.
      { path: '/search', name: 'search', component: stub },
      { path: '/platform', name: 'platform.landing', component: stub },
      { path: '/platform/get-started', name: 'platform.get-started', component: stub },
      { path: '/finance', name: 'finance.landing', component: stub },
      { path: '/finance/get-started', name: 'finance.get-started', component: stub },
      { path: '/personnel', name: 'personnel.landing', component: stub },
      { path: '/personnel/get-started', name: 'personnel.get-started', component: stub },
      { path: '/auth/login', name: 'auth.login', component: stub },
    ],
  });
}

function login(isPlatformStaff: boolean): void {
  useAuthStore().applyBootstrap({
    user: { id: 'u1', email: 'a@b.co', name: 'Ada', status: 'active', email_verified_at: null, is_platform_staff: isPlatformStaff },
    merchant: { id: 'm1', name: 'Glow', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
    membership: null,
    memberships: [],
    permissions: [],
    setup: { required: false, current_step: null, completed_at: null },
    branch_ids: ['b1'],
    mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
  });
}

async function mountShell(identity: RoleIdentity) {
  const router = makeRouter();
  router.push('/');
  await router.isReady();
  return mount(AppShell, {
    props: { identity },
    slots: { default: '<div>main</div>' },
    global: { plugins: [router] },
    attachTo: document.body,
  });
}

describe('role layout navigation placement', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    login(false);
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('Super Administrator places primary navigation in the header, not a sidebar', async () => {
    login(true);
    const wrapper = await mountShell('super_administrator');
    expect(wrapper.find('[data-testid="header-primary-nav"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="sidebar-primary-nav"]').exists()).toBe(false);
  });

  it('merchant roles keep primary navigation out of the header (sidebar + drawer)', async () => {
    const wrapper = await mountShell('merchant_finance');
    expect(wrapper.find('[data-testid="header-primary-nav"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="sidebar-primary-nav"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="nav-drawer-trigger"]').exists()).toBe(true);
  });

  it('every merchant-role layout exposes an accessible drawer trigger', async () => {
    for (const identity of ['merchant_personnel', 'merchant_finance'] as RoleIdentity[]) {
      const wrapper = await mountShell(identity);
      const trigger = wrapper.find('[data-testid="nav-drawer-trigger"]');
      expect(trigger.exists()).toBe(true);
      expect(trigger.attributes('aria-controls')).toBe('role-nav-drawer');
      wrapper.unmount();
    }
  });

  it('returns focus to the trigger when the mobile drawer closes', async () => {
    const wrapper = await mountShell('merchant_finance');
    const trigger = wrapper.find('[data-testid="nav-drawer-trigger"]')
      .element as HTMLButtonElement;

    await wrapper.find('[data-testid="nav-drawer-trigger"]').trigger('click');
    await flushPromises();

    const close = document.querySelector<HTMLButtonElement>('[data-testid="nav-drawer-close"]');
    expect(close).toBeTruthy();
    close!.click();
    await flushPromises();
    expect(document.activeElement).toBe(trigger);
  });

  it('renders skip link and focusable main landmark', async () => {
    const wrapper = await mountShell('merchant_finance');
    expect(wrapper.find('a[href="#main-content"]').exists()).toBe(true);
    const main = wrapper.find('#main-content');
    expect(main.attributes('tabindex')).toBe('-1');
  });
});
