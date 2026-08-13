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
      // Phase UI-10: Branch navigation is derived from the activated canonical page contract, so the
      // test router must resolve every runtime route the Branch contract entries point at.
      { path: '/dashboard', name: 'branch.dashboard', component: stub },
      { path: '/branch/profile', name: 'branch.branch-profile', component: stub },
      { path: '/branch/calendar', name: 'branch.branch-calendar', component: stub },
      { path: '/branch/day', name: 'branch.branch-day', component: stub },
      { path: '/services', name: 'branch.services', component: stub },
      { path: '/staff', name: 'branch.staff', component: stub },
      { path: '/operations/queue', name: 'branch.operations-queue', component: stub },
      { path: '/operations/appointments', name: 'branch.operations-appointments', component: stub },
      { path: '/finance/invoices', name: 'branch.finance-invoices', component: stub },
      { path: '/finance/payments', name: 'branch.finance-payments', component: stub },
      { path: '/finance/receipts', name: 'branch.finance-receipts', component: stub },
      { path: '/cash-up', name: 'branch.cash-up', component: stub },
      { path: '/audit', name: 'branch.audit', component: stub },
      { path: '/account', name: 'branch.account', component: stub },
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

  it('renders live items as links and gate-blocked items as disabled (no dead links)', async () => {
    setPermissions(['branch.profile.view', 'service.view']);
    const router = makeRouter();
    router.push({ name: 'branch.services' });
    await router.isReady();

    const wrapper = mount(RoleNavigation, {
      props: { items: navigationFor('merchant_branch') },
      global: { plugins: [router] },
    });
    await flushPromises();

    // An implemented, permitted entry is a real link.
    expect(wrapper.findAll('a').some((a) => a.text() === 'Service Catalogue')).toBe(true);

    // A gate-blocked entry (Branch Reports — External Gate W) is a disabled span, never a link.
    const gated = wrapper.findAll('span').find((s) => s.text().startsWith('Branch Reports'));
    expect(gated?.attributes('aria-disabled')).toBe('true');
    expect(wrapper.findAll('a').some((a) => a.text() === 'Branch Reports')).toBe(false);

    // An implemented but unauthorized entry is absent; permission filtering is a UX layer and
    // the server remains the security boundary.
    expect(wrapper.text()).not.toContain('Branch Day Operations');
  });

  it('hides permissioned items when the user lacks the permission', async () => {
    setPermissions([]); // no service.view
    const router = makeRouter();
    router.push('/');
    await router.isReady();

    const wrapper = mount(RoleNavigation, {
      props: { items: navigationFor('merchant_branch') },
      global: { plugins: [router] },
    });
    await flushPromises();

    // "Service Catalogue" requires service.view → hidden.
    expect(wrapper.text()).not.toContain('Service Catalogue');
    // An entry with no permission requirement is still shown.
    expect(wrapper.text()).toContain('Get Started');
  });

  it('marks the active route with aria-current', async () => {
    setPermissions(['branch.profile.view', 'service.view']);
    const router = makeRouter();
    router.push({ name: 'branch.services' });
    await router.isReady();

    const wrapper = mount(RoleNavigation, {
      props: { items: navigationFor('merchant_branch') },
      global: { plugins: [router] },
    });
    await flushPromises();

    const active = wrapper.findAll('a').find((a) => a.attributes('aria-current') === 'page');
    expect(active?.text()).toBe('Service Catalogue');
  });
});
