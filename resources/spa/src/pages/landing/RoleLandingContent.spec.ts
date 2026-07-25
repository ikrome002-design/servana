import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { beforeEach, describe, expect, it } from 'vitest';
import RoleLandingScaffold from '@/components/layout/RoleLandingScaffold.vue';
import { getFaq, getLandingHero } from '@/content/roleContent';
import { loadLegalDoc } from '@/content/legalContent';
import { useAuthStore } from '@/stores/authStore';
import { ROLE_IDENTITIES, type RoleIdentity } from '@/types/roles';

const stub = { template: '<div />' };

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/front-office', name: 'front-office.landing', component: stub },
      { path: '/front-office/get-started', name: 'front-office.get-started', component: stub },
      { path: '/legal/:role/:doc', name: 'legal.document', component: stub },
      // Phase 22 global search is in every merchant-role navigation, so the test router must
      // resolve it or RouterLink cannot render the item.
      { path: '/search', name: 'search', component: stub },
    ],
  });
}

function login(identity: RoleIdentity): void {
  useAuthStore().applyBootstrap({
    user: { id: 'u1', email: 'a@b.co', name: 'Ada', status: 'active', email_verified_at: null, is_platform_staff: identity === 'super_administrator' },
    merchant: { id: 'm1', name: 'Glow', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
    membership: null,
    memberships: [],
    permissions: [],
    setup: { required: false, current_step: null, completed_at: null },
    branch_ids: ['b1'],
    mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
  });
}

describe('role landing content sources', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('parses verbatim hero + FAQ content for every role', () => {
    for (const identity of ROLE_IDENTITIES) {
      expect(getLandingHero(identity).title.length, `${identity} hero`).toBeGreaterThan(0);
      expect(getFaq(identity).length, `${identity} faq`).toBeGreaterThan(0);
    }
  });

  it('uses each role its own legal documents (never another role\'s)', async () => {
    const fo = await loadLegalDoc('merchant_front_office', 'terms-of-service');
    const personnel = await loadLegalDoc('merchant_personnel', 'terms-of-service');
    expect(fo).not.toEqual(personnel);
    expect(fo).toContain('Front Office');
    expect(personnel).toContain('Personnel');
  });

  it('renders the role\'s own hero, FAQ, and legal links', () => {
    login('merchant_front_office');
    const router = makeRouter();
    const wrapper = mount(RoleLandingScaffold, {
      props: { identity: 'merchant_front_office' },
      global: { plugins: [router] },
    });

    // Verbatim hero copy from the front-office landing doc.
    expect(wrapper.text()).toContain('Serve clients faster');
    expect(wrapper.text()).toContain('Frequently asked questions');

    // Legal footer links point at THIS role's documents only.
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href') ?? '');
    expect(hrefs.some((h) => h.includes('/legal/merchant_front_office/'))).toBe(true);
    expect(hrefs.some((h) => h.includes('/legal/merchant_personnel/'))).toBe(false);
  });
});
