import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import GetStartedChecklist from '@/components/onboarding/GetStartedChecklist.vue';
import { useGetStartedStore } from '@/stores/getStartedStore';
import type { RoleIdentity } from '@/types/roles';

const stub = { template: '<div />' };
const USER = '01JUSER0000000000000000000';
const ROLE: RoleIdentity = 'merchant_administrator';

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'test.home', component: stub },
      { path: '/branch/create', name: 'branch.create', component: stub },
      { path: '/onboarding', name: 'onboarding.first-time-setup', component: stub },
      { path: '/legal/:doc(data-policy|privacy-policy|terms-of-service)', name: 'public.legal', component: stub },
      { path: '/legal/:role/:doc', name: 'legal.document', component: stub },
    ],
  });
}

describe('GetStartedChecklist', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('toggling an item persists completion', async () => {
    const router = makeRouter();
    router.push('/');
    await router.isReady();
    const wrapper = mount(GetStartedChecklist, {
      props: { identity: ROLE, userId: USER },
      global: { plugins: [router] },
    });

    await wrapper.find('[data-testid="checklist-verify-email"]').setValue(true);
    await flushPromises();

    expect(useGetStartedStore().isCompleted(USER, ROLE, 'verify-email')).toBe(true);
    expect(wrapper.text()).toContain('1 of');
  });

  it('emits dismiss when the user dismisses', async () => {
    const router = makeRouter();
    router.push('/');
    await router.isReady();
    const wrapper = mount(GetStartedChecklist, {
      props: { identity: ROLE, userId: USER },
      global: { plugins: [router] },
    });

    await wrapper.find('[data-testid="dismiss-get-started"]').trigger('click');
    expect(wrapper.emitted('dismiss')).toBeTruthy();
  });

  it('requires explicit acknowledgement and cannot be bypassed', async () => {
    const router = makeRouter();
    router.push('/');
    await router.isReady();
    const wrapper = mount(GetStartedChecklist, {
      props: { identity: ROLE, userId: USER },
      global: { plugins: [router] },
    });
    const store = useGetStartedStore();

    await wrapper.find('[data-testid="open-acknowledgement"]').trigger('click');
    await flushPromises();

    const confirm = document.querySelector<HTMLButtonElement>(
      '[data-testid="confirm-acknowledgement"]',
    );
    expect(confirm).toBeTruthy();
    // Disabled until all mandatory boxes are checked (cannot bypass).
    expect(confirm!.disabled).toBe(true);

    for (const type of ['terms-of-service', 'privacy-policy', 'data-policy']) {
      const box = document.querySelector<HTMLInputElement>(`[data-testid="accept-${type}"]`);
      box!.checked = true;
      box!.dispatchEvent(new Event('change'));
    }
    await flushPromises();
    expect(confirm!.disabled).toBe(false);

    confirm!.click();
    await flushPromises();
    expect(store.isLegalAcknowledged(USER, ROLE)).toBe(true);
  });

  it('renders deep links only for live items', async () => {
    const router = makeRouter();
    router.push('/');
    await router.isReady();
    const wrapper = mount(GetStartedChecklist, {
      props: { identity: ROLE, userId: USER },
      global: { plugins: [router] },
    });
    // create-first-branch is live → an Open link exists.
    expect(wrapper.findAll('a').some((a) => a.text() === 'Open')).toBe(true);
  });
});
