import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/services/apiClient', () => ({
  apiClient: { get: vi.fn().mockResolvedValue({ data: { data: null } }), put: vi.fn(), post: vi.fn() },
}));

import BillingSettings from '@/pages/platform/BillingSettings.vue';
import { useAuthStore } from '@/stores/authStore';

const ALL_PERMS = [
  'platform.settings.view',
  'platform.billing_settings.view',
  'platform.plan.view',
  'platform.preferred_personnel_fee.manage',
];

const sectionStubs = {
  GeneralSettingsSection: { template: '<div data-test="general" />' },
  BillingSettingsSection: { template: '<div data-test="billing" />' },
  SubscriptionPlansSection: { template: '<div data-test="plans" />' },
  PlanPricesSection: { template: '<div data-test="prices" />', props: ['plan'] },
  PlanEntitlementsSection: { template: '<div data-test="entitlements" />', props: ['plan'] },
  PreferredFeeRulesSection: { template: '<div data-test="fees" />' },
};

function mountWith(permissions: string[]) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  return mount(BillingSettings, { global: { stubs: sectionStubs } });
}

describe('BillingSettings.vue (platform)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('renders all six tabs for a fully-permitted Super Administrator', () => {
    const wrapper = mountWith(ALL_PERMS);
    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs).toHaveLength(6);
    expect(tabs.map((t) => t.text())).toEqual([
      'General settings',
      'Billing settings',
      'Plans',
      'Prices',
      'Entitlements',
      'Preferred-personnel fee',
    ]);
  });

  it('omits tabs the user cannot view (denied controls are absent, not disabled)', () => {
    // Only billing_settings.view → a single tab; no plans/fees/general tabs.
    const wrapper = mountWith(['platform.billing_settings.view']);
    const labels = wrapper.findAll('[role="tab"]').map((t) => t.text());
    expect(labels).toEqual(['Billing settings']);
    expect(wrapper.text()).not.toContain('Preferred-personnel fee');
  });

  it('shows an access note when the user can view no billing configuration', () => {
    const wrapper = mountWith([]);
    expect(wrapper.findAll('[role="tab"]')).toHaveLength(0);
    expect(wrapper.text()).toContain('do not have access');
  });

  it('marks the first visible tab selected and exposes a tablist', () => {
    const wrapper = mountWith(ALL_PERMS);
    expect(wrapper.find('[role="tablist"]').exists()).toBe(true);
    const first = wrapper.findAll('[role="tab"]')[0];
    expect(first.attributes('aria-selected')).toBe('true');
    expect(first.attributes('tabindex')).toBe('0');
  });
});
