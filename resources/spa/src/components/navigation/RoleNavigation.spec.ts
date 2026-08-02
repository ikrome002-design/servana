import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { beforeEach, describe, expect, it } from 'vitest';
import RoleNavigation from '@/components/navigation/RoleNavigation.vue';
import { navigationFor } from '@/navigation/roleNavigation';
import { useAuthStore } from '@/stores/authStore';

const stub = { template: '<div />' };

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'test.home', component: stub },
      // Phase 22 global search is in every merchant-role navigation, so the test router must
      // resolve it or RouterLink cannot render the item.
      { path: '/search', name: 'search', component: stub },
      { path: '/branch', name: 'branch.landing', component: stub },
      { path: '/branch/get-started', name: 'branch.get-started', component: stub },
      { path: '/branch/list', name: 'branch.list', component: stub },
    ],
  });
}

function setPermissions(perms: string[]): void {
  const auth = useAuthStore();
  auth.applyBootstrap({
    user: { id: 'u1', email: 'a@b.co', name: 'A', status: 'active', email_verified_at: null, is_platform_staff: false, theme_preference: null, resolved_theme: 'light' as const },
    merchant: { id: 'm1', name: 'Glow', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
    membership: { id: 'mm1', role: 'branch_manager', status: 'active' },
    memberships: [],
    permissions: perms,
    setup: { required: false, current_step: null, completed_at: null },
    branch_ids: ['b1'],
    mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
  });
}

describe('RoleNavigation', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('renders live items as links and planned items as disabled (no dead links)', async () => {
    setPermissions(['branch.profile.view']);
    const router = makeRouter();
    router.push({ name: 'branch.landing' });
    await router.isReady();

    const wrapper = mount(RoleNavigation, {
      props: { items: navigationFor('merchant_branch') },
      global: { plugins: [router] },
    });
    await flushPromises();

    // Live overview link present.
    const overview = wrapper.findAll('a').find((a) => a.text() === 'Branch overview');
    expect(overview).toBeTruthy();

    // Planned item (Service sessions, Phase 16C) is a disabled span, not a link.
    const planned = wrapper.findAll('span').find((s) => s.text().startsWith('Service sessions'));
    expect(planned?.attributes('aria-disabled')).toBe('true');
    expect(wrapper.findAll('a').some((a) => a.text() === 'Service sessions')).toBe(false);
  });

  it('hides permissioned items when the user lacks the permission', async () => {
    setPermissions([]); // no branch.profile.view
    const router = makeRouter();
    router.push('/');
    await router.isReady();

    const wrapper = mount(RoleNavigation, {
      props: { items: navigationFor('merchant_branch') },
      global: { plugins: [router] },
    });
    await flushPromises();

    // "Branches" requires branch.profile.view → hidden.
    expect(wrapper.text()).not.toContain('Branches');
    // Non-permissioned overview still shown.
    expect(wrapper.text()).toContain('Branch overview');
  });

  it('marks the active route with aria-current', async () => {
    setPermissions(['branch.profile.view']);
    const router = makeRouter();
    router.push({ name: 'branch.landing' });
    await router.isReady();

    const wrapper = mount(RoleNavigation, {
      props: { items: navigationFor('merchant_branch') },
      global: { plugins: [router] },
    });
    await flushPromises();

    const active = wrapper.findAll('a').find((a) => a.attributes('aria-current') === 'page');
    expect(active?.text()).toBe('Branch overview');
  });
});
