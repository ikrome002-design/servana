import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const primeCsrfCookie = vi.fn(() => Promise.resolve());
vi.mock('@/services/apiClient', () => ({
  apiClient: { post: (...a: unknown[]) => post(...a) },
  primeCsrfCookie: () => primeCsrfCookie(),
}));

// Treat any thrown object carrying `isAxiosError` as an axios error.
vi.mock('axios', () => ({
  default: {
    isAxiosError: (e: unknown) => Boolean((e as { isAxiosError?: boolean })?.isAxiosError),
  },
}));

// Phase 21R-A: the page reads `?ref=` from the route to pre-fill the referral field.
// The query is per-test state so both the referred and unreferred journeys are covered.
let routeQuery: Record<string, unknown> = {};
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: routeQuery }),
}));

import RegisterMerchant from '@/pages/auth/RegisterMerchant.vue';

const mountPage = () =>
  mount(RegisterMerchant, { global: { stubs: { RouterLink: true } } });

const fillRequired = async (wrapper: ReturnType<typeof mountPage>) => {
  await wrapper.find('#owner_name').setValue('Paul Nderitu');
  await wrapper.find('#business_name').setValue('Glow Salon');
  await wrapper.find('#email').setValue('owner@example.com');
};

describe('RegisterMerchant.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    primeCsrfCookie.mockClear();
    routeQuery = {};
  });

  it('renders accessible owner, business and email fields', () => {
    const wrapper = mountPage();
    expect(wrapper.find('#owner_name').exists()).toBe(true);
    expect(wrapper.find('#business_name').exists()).toBe(true);
    expect(wrapper.find('#email').exists()).toBe(true);
    expect(wrapper.find('button[type="submit"]').exists()).toBe(true);
  });

  it('submits the registration and shows the uniform success state', async () => {
    post.mockResolvedValueOnce({ data: { message: 'ok' } });
    const wrapper = mountPage();

    await fillRequired(wrapper);
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    // An unreferred registration sends exactly what it always sent — no empty
    // referral keys — so the pre-21R-A contract is untouched.
    expect(post).toHaveBeenCalledWith('/merchant-registration/self-register', {
      owner_name: 'Paul Nderitu',
      business_name: 'Glow Salon',
      email: 'owner@example.com',
    });
    expect(wrapper.find('[data-testid="register-success"]').exists()).toBe(true);
  });

  it('maps server validation errors onto the fields and stays on the form', async () => {
    const apiError = {
      code: 'validation_failed',
      message: 'Invalid',
      fields: { email: ['The email field is required.'] },
      meta: {},
    };
    post.mockRejectedValueOnce(
      Object.assign(new Error('422'), { isAxiosError: true, apiError }),
    );

    const wrapper = mountPage();
    await wrapper.find('#email').setValue('bad');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(wrapper.find('[data-testid="register-success"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('The email field is required.');
  });

  // ── Phase 21R-A referral capture (Plan §58A.1, §12.1 item 5) ────────────────

  it('offers an optional referral field that is not required', () => {
    const input = mountPage().find('#referral_code');

    expect(input.exists()).toBe(true);
    expect(input.attributes('required')).toBeUndefined();
    expect(input.attributes('aria-required')).not.toBe('true');
  });

  it('pre-fills the referral code from ?ref= and shows a dismissible notice', async () => {
    routeQuery = { ref: 'SERVANA-X8T2K' };
    const wrapper = mountPage();

    const notice = wrapper.find('[data-testid="referral-applied-notice"]');

    expect((wrapper.find('#referral_code').element as HTMLInputElement).value).toBe('SERVANA-X8T2K');
    expect(notice.text()).toContain('SERVANA-X8T2K');

    await wrapper.find('[data-testid="referral-dismiss"]').trigger('click');

    expect(wrapper.find('[data-testid="referral-applied-notice"]').exists()).toBe(false);
    // Dismissing the notice must not discard the code itself.
    expect((wrapper.find('#referral_code').element as HTMLInputElement).value).toBe('SERVANA-X8T2K');
  });

  it('submits a URL referral as the query_param channel', async () => {
    routeQuery = { ref: 'SERVANA-X8T2K' };
    post.mockResolvedValueOnce({ data: { message: 'ok' } });

    const wrapper = mountPage();
    await fillRequired(wrapper);
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/merchant-registration/self-register', {
      owner_name: 'Paul Nderitu',
      business_name: 'Glow Salon',
      email: 'owner@example.com',
      referral_code: 'SERVANA-X8T2K',
      referral_channel: 'query_param',
    });
  });

  it('submits a typed referral as the manual_entry channel', async () => {
    post.mockResolvedValueOnce({ data: { message: 'ok' } });

    const wrapper = mountPage();
    await fillRequired(wrapper);
    await wrapper.find('#referral_code').setValue('servana-x8t2k');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
      '/merchant-registration/self-register',
      expect.objectContaining({ referral_code: 'servana-x8t2k', referral_channel: 'manual_entry' }),
    );
  });

  it('downgrades a pre-filled code to manual_entry once the user edits it', async () => {
    routeQuery = { ref: 'SERVANA-X8T2K' };
    post.mockResolvedValueOnce({ data: { message: 'ok' } });

    const wrapper = mountPage();
    await fillRequired(wrapper);
    await wrapper.find('#referral_code').setValue('SERVANA-OTHER1');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
      '/merchant-registration/self-register',
      expect.objectContaining({ referral_code: 'SERVANA-OTHER1', referral_channel: 'manual_entry' }),
    );
  });

  it('never blocks submission on a badly shaped referral code', async () => {
    post.mockResolvedValueOnce({ data: { message: 'ok' } });

    const wrapper = mountPage();
    await fillRequired(wrapper);
    await wrapper.find('#referral_code').setValue('not-a-code');

    // The hint is advisory, never an error, and the submit button stays enabled.
    expect(wrapper.find('[data-testid="referral-format-hint"]').exists()).toBe(true);
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined();

    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    // It is still submitted: the server keeps it as invalid_format evidence.
    expect(post).toHaveBeenCalledWith(
      '/merchant-registration/self-register',
      expect.objectContaining({ referral_code: 'not-a-code' }),
    );
    expect(wrapper.find('[data-testid="register-success"]').exists()).toBe(true);
  });

  it('preserves the referral code through a server validation error', async () => {
    routeQuery = { ref: 'SERVANA-X8T2K' };
    post.mockRejectedValueOnce(
      Object.assign(new Error('422'), {
        isAxiosError: true,
        apiError: {
          code: 'validation_failed',
          message: 'Invalid',
          fields: { email: ['The email field is required.'] },
          meta: {},
        },
      }),
    );

    const wrapper = mountPage();
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect((wrapper.find('#referral_code').element as HTMLInputElement).value).toBe('SERVANA-X8T2K');
  });

  it('never displays a referrer identity', () => {
    routeQuery = { ref: 'SERVANA-X8T2K' };
    const text = mountPage().text().toLowerCase();

    // Servana holds only the code and R&E's opaque attribution id — there is no
    // referrer name, contact or reward to render, and none may ever be implied.
    for (const forbidden of ['referred by', 'referrer name', 'your referrer', 'reward', 'earn ksh']) {
      expect(text).not.toContain(forbidden);
    }
  });

  it('omits referral keys entirely when the field is left blank', async () => {
    post.mockResolvedValueOnce({ data: { message: 'ok' } });

    const wrapper = mountPage();
    await fillRequired(wrapper);
    await wrapper.find('#referral_code').setValue('   ');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(post.mock.calls[0][1]).not.toHaveProperty('referral_code');
    expect(post.mock.calls[0][1]).not.toHaveProperty('referral_channel');
  });
});
