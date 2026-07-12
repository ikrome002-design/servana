import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: vi.fn().mockResolvedValue({ data: { data: [] } }),
    post: vi.fn(),
    patch: vi.fn(),
  },
}));

import Promotions from '@/pages/platform/Promotions.vue';
import { useAuthStore } from '@/stores/authStore';

const BOTH = ['platform.promotion.manage', 'platform.free_period_offer.manage'];

function mountWith(permissions: string[]) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  return mount(Promotions);
}

describe('Promotions.vue (platform)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('renders both sections for a fully-permitted Super Administrator', () => {
    const wrapper = mountWith(BOTH);
    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs).toHaveLength(2);
    expect(tabs.map((t) => t.text())).toEqual(['Promotional discounts', 'Free-period offers']);
  });

  it('omits a section the user cannot manage (denied controls are absent, not disabled)', () => {
    const wrapper = mountWith(['platform.promotion.manage']);
    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs).toHaveLength(1);
    expect(tabs[0]?.text()).toBe('Promotional discounts');
  });

  it('shows a no-access empty state when the user has neither permission', () => {
    const wrapper = mountWith([]);
    expect(wrapper.findAll('[role="tab"]')).toHaveLength(0);
    expect(wrapper.text()).toContain('No access');
  });

  it('reveals the create form when New promotion is clicked', async () => {
    const wrapper = mountWith(BOTH);
    expect(wrapper.find('#promo-name').exists()).toBe(false);
    await wrapper.findAll('button').find((b) => b.text() === 'New promotion')?.trigger('click');
    expect(wrapper.find('#promo-name').exists()).toBe(true);
  });
});
