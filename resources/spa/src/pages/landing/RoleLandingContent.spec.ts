import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { beforeEach, describe, expect, it } from 'vitest';
import RoleLandingScaffold from '@/components/layout/RoleLandingScaffold.vue';
import { loadFaq, loadLandingHero } from '@/content/roleDocuments';
import { loadLegalDoc } from '@/content/legalContent';
import { useAuthStore } from '@/stores/authStore';
import { ROLE_IDENTITIES, type RoleIdentity } from '@/types/roles';

const stub = { template: '<div />' };

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', name: 'front-office.dashboard', component: stub },
      { path: '/get-started', name: 'front-office.get-started', component: stub },
      { path: '/account', name: 'front-office.account', component: stub },
      { path: '/legal/:doc(data-policy|privacy-policy|terms-of-service)', name: 'public.legal', component: stub },
      { path: '/legal/:role/:doc', name: 'legal.document', component: stub },
      // Phase 22 global search is in every merchant-role navigation, so the test router must
      // resolve it or RouterLink cannot render the item.
      { path: '/search', name: 'search', component: stub },
    ],
  });
}

function login(identity: RoleIdentity): void {
  useAuthStore().applyBootstrap({
    user: { id: 'u1', email: 'a@b.co', name: 'Ada', status: 'active', email_verified_at: null, is_platform_staff: identity === 'super_administrator', theme_preference: null, resolved_theme: 'light' as const },
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

  it('parses verbatim hero + FAQ content for every role', async () => {
    for (const identity of ROLE_IDENTITIES) {
      const hero = await loadLandingHero(identity);
      const faq = await loadFaq(identity);
      expect(hero.title.length, `${identity} hero`).toBeGreaterThan(0);
      expect(faq.length, `${identity} faq`).toBeGreaterThan(0);
    }
  });

  it('gives each role its own hero copy, never another role\'s (Phase 24 lazy split)', async () => {
    const fo = await loadLandingHero('merchant_front_office');
    const personnel = await loadLandingHero('merchant_personnel');
    const audit = await loadLandingHero('merchant_audit');

    expect(fo.title).not.toEqual(personnel.title);
    expect(fo.title).not.toEqual(audit.title);

    // The loader is keyed strictly by identity, so a role can never resolve a sibling's document.
    for (const identity of ROLE_IDENTITIES) {
      await expect(loadLandingHero(identity)).resolves.toBeDefined();
      await expect(loadFaq(identity)).resolves.toBeDefined();
    }
  });

  it('fails safely for an unknown role identity', async () => {
    await expect(
      loadLandingHero('not_a_role' as unknown as RoleIdentity),
    ).rejects.toThrow(/not found/i);
  });

  it('uses each role its own legal documents (never another role\'s)', async () => {
    const fo = await loadLegalDoc('merchant_front_office', 'terms-of-service');
    const personnel = await loadLegalDoc('merchant_personnel', 'terms-of-service');
    expect(fo).not.toEqual(personnel);
    expect(fo).toContain('Front Office');
    expect(personnel).toContain('Personnel');
  });

  it('renders the role\'s own hero, FAQ, and legal links', async () => {
    login('merchant_front_office');
    const router = makeRouter();
    const wrapper = mount(RoleLandingScaffold, {
      props: { identity: 'merchant_front_office' },
      global: { plugins: [router] },
    });

    // Phase 24: hero + FAQ now arrive from lazily-imported per-role chunks, so the dynamic imports
    // must settle before the rendered copy is asserted.
    await flushPromises();
    await wrapper.vm.$nextTick();

    // Verbatim hero copy from the front-office landing doc.
    expect(wrapper.text()).toContain('Serve clients faster');
    expect(wrapper.text()).toContain('Frequently asked questions');

    // Phase UI-06: the legal routes became host-derived, so the role is no longer a path segment.
    // The destination is `/legal/<doc>` on the account's own host and the SERVER decides which
    // document that is — which removes the possibility of a path selecting an account at all,
    // rather than merely testing that this one does not.
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href') ?? '');
    expect(hrefs).toContain('/legal/data-policy');
    expect(hrefs).toContain('/legal/privacy-policy');
    expect(hrefs).toContain('/legal/terms-of-service');
    expect(hrefs.some((h) => h.includes('merchant_front_office'))).toBe(false);
    expect(hrefs.some((h) => h.includes('merchant_personnel'))).toBe(false);
  });
});
