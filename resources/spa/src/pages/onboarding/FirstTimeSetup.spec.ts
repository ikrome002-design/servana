import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const get = vi.fn((...args: unknown[]) => {
  void args;
  return Promise.resolve({ data: { data: { user: null } } });
});
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    post: (...a: unknown[]) => post(...a),
    get: (...a: unknown[]) => get(...a),
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
  // Step 1 — tier.
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
}

describe('FirstTimeSetup.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    get.mockClear();
    routerPush.mockReset();
  });

  it('renders the 4-step stepper starting at the tier step', () => {
    const wrapper = mountWizard();
    expect(wrapper.find('#service_fee_tier').exists()).toBe(true);
    expect(wrapper.findAll('ol[aria-label="Setup progress"] li')).toHaveLength(4);
  });

  it('blocks advancing past the tier step until a tier is chosen', async () => {
    const wrapper = mountWizard();

    expect(primaryButton(wrapper).attributes('disabled')).toBeDefined();

    await wrapper.find('#service_fee_tier').setValue('customer_centric');
    expect(primaryButton(wrapper).attributes('disabled')).toBeUndefined();
  });

  it('persists the selected service fee tier into the store', async () => {
    const wrapper = mountWizard();
    await wrapper.find('#service_fee_tier').setValue('business_centric');

    expect(useOnboardingStore().form.service_fee_tier).toBe('business_centric');
  });

  it('submits the full payload and routes to the dashboard on completion', async () => {
    post.mockResolvedValueOnce({ data: { data: { merchant: { status: 'active' } } } });
    get.mockResolvedValueOnce({ data: { data: { user: null } } }); // re-bootstrap

    const wrapper = mountWizard();
    await fillAndAdvanceToLastStep(wrapper);

    await primaryButton(wrapper).trigger('click'); // Finish setup
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
      '/merchant-registration/first-time-setup',
      expect.objectContaining({
        service_fee_tier: 'split_tier',
        business_category: 'Salon',
        branch: expect.objectContaining({ name: 'Main Branch', code: 'MAIN' }),
        branch_manager_email: 'bm@demo.co.ke',
        hr_email: 'hr@demo.co.ke',
      }),
    );
    expect(routerPush).toHaveBeenCalledWith({ name: 'merchant.dashboard' });
  });
});
