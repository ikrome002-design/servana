import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const setupOptions = {
  data: {
    options: {
      service_fee_tiers: [
        { value: 'split_tier', label: 'Split tier' },
        { value: 'business_centric', label: 'Business-centric' },
        { value: 'customer_centric', label: 'Customer-centric' },
      ],
      subscription_plans: [{
        id: '01JPLAN0000000000000000000',
        name: 'Starter',
        description: 'Starter plan',
        tier: 'starter',
        prices: [{
          id: '01JPRICE000000000000000000',
          amount_minor: 250000,
          currency: 'KES',
          billing_interval: 'monthly',
        }],
      }],
    },
  },
};
const get = vi.fn((url: string) => Promise.resolve(
  url === '/merchant-registration/first-time-setup'
    ? { data: setupOptions }
    : { data: { data: { user: null } } },
));
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    post: (...a: unknown[]) => post(...a),
    get: (url: string) => get(url),
  },
  primeCsrfCookie: () => Promise.resolve(),
}));

vi.mock('axios', () => ({
  default: {
    isAxiosError: (e: unknown) => Boolean((e as { isAxiosError?: boolean })?.isAxiosError),
  },
}));

const routerPush = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push: routerPush }) }));

import FirstTimeSetup from '@/pages/onboarding/FirstTimeSetup.vue';
import { useOnboardingStore } from '@/stores/onboardingStore';

const mountWizard = () => mount(FirstTimeSetup);

// The primary action (Continue / Finish) is always the last button rendered.
function primaryButton(wrapper: ReturnType<typeof mountWizard>) {
  const buttons = wrapper.findAll('button');
  return buttons[buttons.length - 1];
}

async function fillAndAdvanceToLastStep(wrapper: ReturnType<typeof mountWizard>) {
  await flushPromises();
  // Step 1 — plan and tier.
  await wrapper.find('#subscription_plan_price').setValue(
    '01JPLAN0000000000000000000:01JPRICE000000000000000000',
  );
  await wrapper.find('#service_fee_tier').setValue('split_tier');
  await primaryButton(wrapper).trigger('click'); // Continue
  // Step 2 — profile.
  await wrapper.find('#business_category').setValue('Salon');
  await wrapper.find('#contact_phone').setValue('+254700000000');
  await primaryButton(wrapper).trigger('click');
  // Step 3 — branch.
  await wrapper.find('#branch_name').setValue('Main Branch');
  await wrapper.find('#branch_code').setValue('MAIN');
  await primaryButton(wrapper).trigger('click');
  // Step 4 — staff.
  await wrapper.find('#branch_manager_email').setValue('bm@demo.co.ke');
  await wrapper.find('#hr_email').setValue('hr@demo.co.ke');
  await primaryButton(wrapper).trigger('click'); // Continue to review.
}

describe('FirstTimeSetup.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    get.mockClear();
    get.mockImplementation((url: string) => Promise.resolve(
      url === '/merchant-registration/first-time-setup'
        ? { data: setupOptions }
        : { data: { data: { user: null } } },
    ));
    routerPush.mockReset();
  });

  it('renders the 5-step stepper starting at plan and fee selection', async () => {
    const wrapper = mountWizard();
    await flushPromises();
    expect(wrapper.find('#service_fee_tier').exists()).toBe(true);
    expect(wrapper.find('#subscription_plan_price').exists()).toBe(true);
    expect(wrapper.findAll('ol[aria-label="Setup progress"] li')).toHaveLength(5);
  });

  it('blocks advancing past the tier step until a tier is chosen', async () => {
    const wrapper = mountWizard();
    await flushPromises();

    expect(primaryButton(wrapper).attributes('disabled')).toBeDefined();

    await wrapper.find('#subscription_plan_price').setValue(
      '01JPLAN0000000000000000000:01JPRICE000000000000000000',
    );
    await wrapper.find('#service_fee_tier').setValue('customer_centric');
    expect(primaryButton(wrapper).attributes('disabled')).toBeUndefined();
  });

  it('persists the selected service fee tier into the store', async () => {
    const wrapper = mountWizard();
    await flushPromises();
    await wrapper.find('#service_fee_tier').setValue('business_centric');

    expect(useOnboardingStore().form.service_fee_tier).toBe('business_centric');
  });

  it('submits the full payload and routes to the dashboard on completion', async () => {
    post.mockResolvedValueOnce({ data: { data: { merchant: { status: 'active' } } } });
    const wrapper = mountWizard();
    await fillAndAdvanceToLastStep(wrapper);

    await primaryButton(wrapper).trigger('click'); // Finish setup
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
      '/merchant-registration/first-time-setup',
      expect.objectContaining({
        service_fee_tier: 'split_tier',
        subscription_plan_ulid: '01JPLAN0000000000000000000',
        subscription_plan_price_ulid: '01JPRICE000000000000000000',
        business_category: 'Salon',
        branch: expect.objectContaining({ name: 'Main Branch', code: 'MAIN' }),
        branch_manager_email: 'bm@demo.co.ke',
        hr_email: 'hr@demo.co.ke',
      }),
    );
    expect(routerPush).toHaveBeenCalledWith({ name: 'merchant.dashboard' });
  });
});
