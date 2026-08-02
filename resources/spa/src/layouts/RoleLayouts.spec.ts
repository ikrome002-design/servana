import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import AppShell from '@/components/layout/AppShell.vue';
import { useAuthStore } from '@/stores/authStore';
import { ROLE_ENTRY, ROLE_IDENTITIES, type RoleIdentity } from '@/types/roles';

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
      { path: '/hr', name: 'hr.landing', component: stub },
      { path: '/hr/get-started', name: 'hr.get-started', component: stub },
      { path: '/hr/staff', name: 'hr.staff', component: stub },
      { path: '/hr/invitations', name: 'hr.invitations', component: stub },
      { path: '/hr/permission-preview', name: 'hr.permission-preview', component: stub },
      { path: '/hr/eligibility', name: 'hr.eligibility', component: stub },
      { path: '/hr/availability', name: 'hr.availability', component: stub },
      { path: '/hr/compensation', name: 'hr.compensation', component: stub },
      { path: '/hr/payout-runs', name: 'hr.payout-runs', component: stub },
      { path: '/branch', name: 'branch.landing', component: stub },
      { path: '/branch/get-started', name: 'branch.get-started', component: stub },
      // Phase UI-04: SvFixedFooter links the three role-scoped legal documents, so the shell
      // cannot mount without this route resolving.
      { path: '/legal/:role/:doc', name: 'legal.document', component: stub },
    ],
  });
}

function login(isPlatformStaff: boolean): void {
  useAuthStore().applyBootstrap({
    user: { id: 'u1', email: 'a@b.co', name: 'Ada', status: 'active', email_verified_at: null, is_platform_staff: isPlatformStaff, theme_preference: null, resolved_theme: 'light' as const },
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

/**
 * Phase UI-04 — Human Resource shell identity (closes UI01-NAV-002).
 *
 * The audited defect was that ROLE_ENTRY mapped BOTH merchant_branch and merchant_human_resource
 * to BranchLayout, so HR was the one account presenting under another account's identity.
 */
describe('Human Resource shell identity (UI01-NAV-002)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    login(false);
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('names Human Resource in the account contract, not the abbreviation', () => {
    expect(ROLE_ENTRY.merchant_human_resource.label).toBe('Human Resource');
  });

  it('gives Human Resource a shell of its own', () => {
    // The defect in one assertion: these two were the same value.
    expect(ROLE_ENTRY.merchant_human_resource.layout).toBe('HumanResourceLayout');
    expect(ROLE_ENTRY.merchant_branch.layout).toBe('BranchLayout');
    expect(ROLE_ENTRY.merchant_human_resource.layout)
      .not.toBe(ROLE_ENTRY.merchant_branch.layout);
  });

  it('gives every one of the eight accounts a distinct shell', () => {
    const layouts = ROLE_IDENTITIES.map((identity) => ROLE_ENTRY[identity].layout);

    expect(new Set(layouts).size).toBe(ROLE_IDENTITIES.length);
  });

  it('presents the Human Resource identity in the shell, never Branch', async () => {
    const wrapper = await mountShell('merchant_human_resource');

    expect(wrapper.text()).toContain('Human Resource');
    expect(wrapper.text()).not.toContain('Branch Manager');
  });

  it('keeps HR on left navigation with a responsive drawer, like every merchant account', async () => {
    const wrapper = await mountShell('merchant_human_resource');

    expect(wrapper.find('[data-testid="sidebar-primary-nav"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="header-primary-nav"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="nav-drawer-trigger"]').exists()).toBe(true);
  });

  it('renders exactly one primary navigation region', async () => {
    const wrapper = await mountShell('merchant_human_resource');

    expect(wrapper.findAll('[data-testid="sidebar-primary-nav"]')).toHaveLength(1);
  });

  it('surfaces no Branch-owned command through the shell', async () => {
    // The shell renders HR's OWN navigation, so branch-management entries cannot appear.
    const wrapper = await mountShell('merchant_human_resource');
    const nav = wrapper.get('[data-testid="sidebar-primary-nav"]').text();

    expect(nav).not.toContain('Cash-up');
    expect(nav).not.toContain('Operating hours');
    expect(nav).not.toContain('Service catalogue');
  });
});

/**
 * Phase UI-04 — the shell integrates the shared chrome (ADR-024; UI/UX plan §14.3).
 */
describe('authenticated shell chrome', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    login(false);
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('renders the fixed footer on an authenticated page', async () => {
    const wrapper = await mountShell('merchant_finance');

    expect(wrapper.find('[data-testid="sv-fixed-footer"]').exists()).toBe(true);
  });

  it('reserves the footer\'s block size so it cannot cover page content', async () => {
    // ADR-024: ONE token drives both the footer height and the reserved space.
    const wrapper = await mountShell('merchant_finance');

    expect(wrapper.classes()).toContain('sv-footer-reserve');
  });

  it('carries the identity in one profile control rather than a scattered cluster', async () => {
    const wrapper = await mountShell('merchant_finance');

    expect(wrapper.find('[data-testid="sv-profile-control"]').exists()).toBe(true);
    expect(wrapper.get('[data-testid="sv-profile-trigger"]').text()).toContain('Ada');
    expect(wrapper.get('[data-testid="sv-profile-trigger"]').text()).toContain('Finance');
  });

  it('keeps the theme control reachable from the footer on every page', async () => {
    const wrapper = await mountShell('merchant_finance');

    expect(wrapper.find('[data-testid="theme-toggle"]').exists()).toBe(true);
  });

  it('links the three legal documents for the ACTIVE account only', async () => {
    // One account never receives another's documents.
    const wrapper = await mountShell('merchant_human_resource');

    for (const doc of ['data-policy', 'privacy-policy', 'terms-of-service']) {
      const link = wrapper.get(`[data-testid="sv-footer-${doc}"]`);
      expect(link.attributes('href')).toContain('/legal/merchant_human_resource/');
    }
  });

  it('ships no dead FAQ link while the role-aware FAQ route does not exist', async () => {
    // UI-05/UI-06 activate it. A link that promises content the product cannot serve is worse
    // than no link.
    const wrapper = await mountShell('merchant_finance');

    expect(wrapper.find('[data-testid="sv-footer-faq"]').exists()).toBe(false);
  });

  it('opens every external footer link safely', async () => {
    const wrapper = await mountShell('merchant_finance');

    for (const key of ['instagram', 'x', 'facebook', 'youtube', 'linkedin', 'corporate']) {
      const link = wrapper.get(`[data-testid="sv-footer-${key}"]`);
      expect(link.attributes('target')).toBe('_blank');
      // noopener alone still leaks the referrer; the pair is the control (ADR-024).
      expect(link.attributes('rel')).toBe('noopener noreferrer');
    }
  });

  it('states the copyright verbatim', async () => {
    const wrapper = await mountShell('merchant_finance');

    expect(wrapper.get('[data-testid="sv-footer-copyright"]').text())
      .toBe('© 2026 Citrus Labs. All Rights Reserved.');
  });
});
