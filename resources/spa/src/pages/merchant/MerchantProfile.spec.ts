import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MerchantProfile from '@/pages/merchant/MerchantProfile.vue';
import { apiClient } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';

/**
 * REM-SCR-002A — the Plan §27.3 Merchant Administrator business-profile screen.
 * Frontend gating is UX only; these specs prove the screen calls the right endpoint, renders
 * labelled fields, surfaces field errors, and never exposes a storage path.
 */

const PROFILE = {
  id: '01JQ0000000000000000000001',
  business_category: 'Salon',
  contact_email: 'owner@glow.test',
  contact_phone: '+254700000111',
  receipt_display_name: 'Glow Salon',
  address: '1 Kenyatta Avenue',
  town: 'Nairobi',
  timezone: 'Africa/Nairobi',
  country: 'KE',
  merchant: { id: 'm1', name: 'Glow', slug: 'glow', status: 'active', service_fee_tier: null },
  logo: null,
};

function signIn(permissions: string[]): void {
  const auth = useAuthStore();
  auth.applyBootstrap({
    user: { id: 'u1', email: 'a@b.co', name: 'A', status: 'active', email_verified_at: null, is_platform_staff: false },
    merchant: { id: 'm1', name: 'Glow', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
    membership: { id: 'mm1', role: 'merchant_admin', status: 'active' },
    memberships: [],
    permissions,
    branch_ids: [],
  } as never);
}

describe('MerchantProfile', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('loads the profile from /merchant/profile and renders the labelled fields', async () => {
    const get = vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: PROFILE } } as never);
    signIn(['merchant.profile.view', 'merchant.profile.update']);

    const wrapper = mount(MerchantProfile);
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/merchant/profile');
    expect(wrapper.text()).toContain('Business profile');
    expect(wrapper.find('label[for="mp-business-category"]').exists()).toBe(true);
    expect(wrapper.find('label[for="mp-contact-phone"]').exists()).toBe(true);
    expect((wrapper.find('#mp-town').element as HTMLInputElement).value).toBe('Nairobi');
    // Read-only context is displayed but not editable.
    expect(wrapper.text()).toContain('Glow');
    expect(wrapper.text()).toContain('KE');
  });

  it('PATCHes only the editable fields and confirms success', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: PROFILE } } as never);
    const patch = vi
      .spyOn(apiClient, 'patch')
      .mockResolvedValue({ data: { data: { ...PROFILE, town: 'Mombasa' } } } as never);
    signIn(['merchant.profile.view', 'merchant.profile.update']);

    const wrapper = mount(MerchantProfile);
    await flushPromises();

    await wrapper.find('#mp-town').setValue('Mombasa');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(patch).toHaveBeenCalledTimes(1);
    const [url, payload] = patch.mock.calls[0] as [string, Record<string, unknown>];
    expect(url).toBe('/merchant/profile');
    expect(payload.town).toBe('Mombasa');
    // The payload carries exactly the seven writable fields — nothing read-only.
    expect(Object.keys(payload).sort()).toEqual([
      'address', 'business_category', 'contact_email', 'contact_phone',
      'receipt_display_name', 'timezone', 'town',
    ]);
    expect(payload).not.toHaveProperty('country');
    expect(payload).not.toHaveProperty('merchant');
    expect(payload).not.toHaveProperty('logo');
  });

  it('surfaces field errors from the API envelope against their inputs', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: PROFILE } } as never);
    vi.spyOn(apiClient, 'patch').mockRejectedValue({
      response: { data: { error: { code: 'validation_failed', fields: { contact_email: ['Enter a valid email.'] } } } },
    } as never);
    signIn(['merchant.profile.view', 'merchant.profile.update']);

    const wrapper = mount(MerchantProfile);
    await flushPromises();
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(wrapper.text()).toContain('Enter a valid email.');
    const input = wrapper.find('#mp-contact-email');
    expect(input.attributes('aria-invalid')).toBe('true');
    expect(input.attributes('aria-describedby')).toBe('mp-contact-email-error');
  });

  it('renders read-only with no submit control when the write key is absent', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: PROFILE } } as never);
    signIn(['merchant.profile.view']);

    const wrapper = mount(MerchantProfile);
    await flushPromises();

    expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('view-only access');
    expect(wrapper.find('#mp-town').attributes('disabled')).toBeDefined();
  });

  it('requests a short-lived link for the logo and never renders a storage path', async () => {
    const withLogo = { ...PROFILE, logo: { id: 'f1', filename: 'glow-logo.png' } };
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: withLogo } } as never);
    const post = vi.spyOn(apiClient, 'post').mockResolvedValue({
      data: { data: { url: 'https://example.test/files/f1/download?signature=abc', expires_at: 'x' } },
    } as never);
    signIn(['merchant.profile.view', 'merchant.profile.update']);

    const wrapper = mount(MerchantProfile);
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/files/f1/download-link');
    expect(wrapper.text()).toContain('glow-logo.png');
    expect(wrapper.html()).not.toContain('generated/');
    expect(wrapper.html()).not.toContain('final_path');
  });

  it('shows the error boundary when the profile cannot be loaded', async () => {
    vi.spyOn(apiClient, 'get').mockRejectedValue(new Error('boom'));
    signIn(['merchant.profile.view']);

    const wrapper = mount(MerchantProfile);
    await flushPromises();

    expect(wrapper.text()).toContain('could not load your business profile');
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
  });
});
