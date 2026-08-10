import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: vi.fn().mockResolvedValue({ data: { data: [] } }),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
  },
}));

import FreePeriodOffers from '@/pages/platform/FreePeriodOffers.vue';
import PlanPrices from '@/pages/platform/PlanPrices.vue';
import PlansAndEntitlements from '@/pages/platform/PlansAndEntitlements.vue';
import PlatformBillingSettings from '@/pages/platform/PlatformBillingSettings.vue';
import PreferredPersonnelFeeRules from '@/pages/platform/PreferredPersonnelFeeRules.vue';
import PromotionalDiscounts from '@/pages/platform/PromotionalDiscounts.vue';
import { useAuthStore } from '@/stores/authStore';

/**
 * Increment 9C. Six contract pages replace two consolidated screens (§5.4.3-§5.4.8).
 *
 * These cases prove the SPLIT: each page owns exactly one `h1`, cites the permission the shipped
 * backend actually enforces, composes the already-tested section components rather than
 * reimplementing them, and states the immutability rule its contract requires. The sections' own
 * behaviour is covered by their existing specs and is deliberately not retested here.
 */
const sectionStubs = {
  BillingSettingsSection: { template: '<div data-test="billing-settings-section" />' },
  GeneralSettingsSection: { template: '<div data-test="general-settings-section" />' },
  PlatformFeeConfigSection: { template: '<div data-test="platform-fee-section" />' },
  SubscriptionPlansSection: { template: '<div data-test="plans-section" />' },
  PlanEntitlementsSection: { template: '<div data-test="entitlements-section" />', props: ['plan'] },
  PlanPricesSection: { template: '<div data-test="prices-section" />', props: ['plan'] },
  PreferredFeeRulesSection: { template: '<div data-test="preferred-fees-section" />' },
  PromotionsSection: { template: '<div data-test="promotions-section" :data-only="only" />', props: ['only'] },
};

function mountPage(component: unknown, permissions: string[]) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  return mount(component as never, { global: { stubs: sectionStubs } });
}

const PAGES = [
  { name: 'Platform billing settings', component: PlatformBillingSettings, permission: 'platform.billing_settings.view', testid: 'platform-billing-settings-screen' },
  { name: 'Plans and entitlements', component: PlansAndEntitlements, permission: 'platform.plan.view', testid: 'platform-plans-screen' },
  { name: 'Plan prices and billing periods', component: PlanPrices, permission: 'platform.plan.view', testid: 'platform-prices-screen' },
  { name: 'Promotional discounts', component: PromotionalDiscounts, permission: 'platform.promotion.manage', testid: 'platform-promotions-screen' },
  { name: 'Free-period offers', component: FreePeriodOffers, permission: 'platform.free_period_offer.manage', testid: 'platform-free-periods-screen' },
  { name: 'Preferred personnel fee rules', component: PreferredPersonnelFeeRules, permission: 'platform.preferred_personnel_fee.manage', testid: 'platform-preferred-fees-screen' },
];

describe('Increment 9C — six Billing & Commercial pages', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it.each(PAGES)('$name renders exactly one h1 carrying its own title', ({ name, component, permission }) => {
    const wrapper = mountPage(component, [permission]);
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe(name);
  });

  it.each(PAGES)('$name is a distinct screen with its own test id', ({ component, permission, testid }) => {
    const wrapper = mountPage(component, [permission]);
    expect(wrapper.find(`[data-testid="${testid}"]`).exists()).toBe(true);
  });

  it.each(PAGES)('$name renders the permission boundary without its permission', ({ component, testid }) => {
    const wrapper = mountPage(component, []);
    // The screen container still renders; its CONTENT is replaced by the permission state.
    expect(wrapper.find(`[data-testid="${testid}"]`).exists()).toBe(true);
    expect(wrapper.findAll('[data-test$="-section"]')).toHaveLength(0);
  });

  it('gives every commercial page a unique title, so none is a duplicate of another', () => {
    const titles = PAGES.map((page) => mountPage(page.component, [page.permission]).find('h1').text());
    expect(new Set(titles).size).toBe(PAGES.length);
  });

  // ── Composition, not reimplementation ───────────────────────────────────────────────────────

  it('billing settings composes the billing, general and platform-fee sections', () => {
    const wrapper = mountPage(PlatformBillingSettings, [
      'platform.billing_settings.view',
      'platform.settings.view',
      'platform.platform_fee.configure',
    ]);
    expect(wrapper.find('[data-test="billing-settings-section"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="general-settings-section"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="platform-fee-section"]').exists()).toBe(true);
  });

  it('billing settings omits a section the user cannot view rather than disabling it', () => {
    const wrapper = mountPage(PlatformBillingSettings, ['platform.billing_settings.view']);
    expect(wrapper.find('[data-test="billing-settings-section"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="general-settings-section"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="platform-fee-section"]').exists()).toBe(false);
  });

  it('billing settings no longer carries plans, prices or preferred fees', () => {
    const wrapper = mountPage(PlatformBillingSettings, [
      'platform.billing_settings.view',
      'platform.plan.view',
      'platform.preferred_personnel_fee.manage',
    ]);
    expect(wrapper.find('[data-test="plans-section"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="prices-section"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="preferred-fees-section"]').exists()).toBe(false);
    // And it is no longer a tabbed multi-page screen.
    expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
  });

  it('plans page prompts for a plan before showing entitlements', async () => {
    const wrapper = mountPage(PlansAndEntitlements, ['platform.plan.view']);
    expect(wrapper.find('[data-testid="plans-select-prompt"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="entitlements-section"]').exists()).toBe(false);

    await wrapper.findComponent(sectionStubs.SubscriptionPlansSection).vm.$emit('select', { id: 'p1', key: 'growth' });
    expect(wrapper.find('[data-test="entitlements-section"]').exists()).toBe(true);
  });

  it('prices page prompts for a plan before showing prices', async () => {
    const wrapper = mountPage(PlanPrices, ['platform.plan.view']);
    expect(wrapper.find('[data-testid="prices-select-prompt"]').exists()).toBe(true);

    await wrapper.findComponent(sectionStubs.SubscriptionPlansSection).vm.$emit('select', { id: 'p1', key: 'growth' });
    expect(wrapper.find('[data-test="prices-section"]').exists()).toBe(true);
  });

  /**
   * Promotions and free periods share one substantial form. The split is proven by each page
   * passing a DIFFERENT `only`, which is what makes them two pages rather than two labels.
   */
  it('promotions and free periods compose the same section with different scopes', () => {
    const promotions = mountPage(PromotionalDiscounts, ['platform.promotion.manage']);
    const freePeriods = mountPage(FreePeriodOffers, ['platform.free_period_offer.manage']);

    expect(promotions.find('[data-test="promotions-section"]').attributes('data-only')).toBe('promotions');
    expect(freePeriods.find('[data-test="promotions-section"]').attributes('data-only')).toBe('free-periods');
  });

  it('free periods are not reachable with only the promotion permission, and vice versa', () => {
    expect(mountPage(FreePeriodOffers, ['platform.promotion.manage']).find('[data-test="promotions-section"]').exists()).toBe(false);
    expect(mountPage(PromotionalDiscounts, ['platform.free_period_offer.manage']).find('[data-test="promotions-section"]').exists()).toBe(false);
  });

  // ── The immutability rules each contract requires the page to state ─────────────────────────

  it('prices page states that a price change is neither prorated nor back-applied', () => {
    const wrapper = mountPage(PlanPrices, ['platform.plan.view']);
    const note = wrapper.find('[data-testid="prices-immutability-note"]').text();
    expect(note).toContain('not applied mid-cycle');
    expect(note).toContain('not automatically grandfathered');
  });

  it('preferred-fee page states that existing invoices never recalculate', () => {
    const wrapper = mountPage(PreferredPersonnelFeeRules, ['platform.preferred_personnel_fee.manage']);
    expect(wrapper.find('[data-testid="preferred-fees-immutability-note"]').text())
      .toContain('never recalculate');
  });

  it('promotions page states the precedence order and the one-discount rule', () => {
    const wrapper = mountPage(PromotionalDiscounts, ['platform.promotion.manage']);
    const note = wrapper.find('[data-testid="promotions-precedence-note"]').text();
    expect(note).toContain('At most one discount');
    expect(note).toContain('merchant-targeted promotion');
  });

  it('free-period page states the one-offer rule and the absolute trial end', () => {
    const wrapper = mountPage(FreePeriodOffers, ['platform.free_period_offer.manage']);
    const note = wrapper.find('[data-testid="free-periods-immutability-note"]').text();
    expect(note).toContain('At most one free-period offer');
    expect(note).toContain('absolute trial-end date');
  });

  it('no commercial page offers a merchant-operation control', () => {
    for (const page of PAGES) {
      const text = mountPage(page.component, [page.permission]).text().toLowerCase();
      for (const forbidden of ['create merchant', 'impersonate', 'record payment']) {
        expect(text, `${page.name}: "${forbidden}"`).not.toContain(forbidden);
      }
    }
  });
});
