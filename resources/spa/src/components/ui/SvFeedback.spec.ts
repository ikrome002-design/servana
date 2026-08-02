import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvBanner from '@/components/ui/SvBanner.vue';
import SvEmptyState from '@/components/ui/SvEmptyState.vue';
import SvErrorState from '@/components/ui/SvErrorState.vue';
import SvLockedState from '@/components/ui/SvLockedState.vue';
import SvOfflineState from '@/components/ui/SvOfflineState.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import SvToast from '@/components/ui/SvToast.vue';
import { useNotificationStore } from '@/stores/notificationStore';

/**
 * Phase UI-04 — feedback and state contract (UI/UX plan §10).
 *
 * The property that matters most here is that the five state components stay SEMANTICALLY
 * DISTINCT. Collapsing them is how a user gets told "no records" when the truth is "the request
 * failed" or "you may not see these" — three different facts requiring three different actions,
 * and one of them is a disclosure boundary.
 */

describe('SvAlert', () => {
  it('interrupts for an error and waits politely for everything else', () => {
    expect(mount(SvAlert, { props: { severity: 'error' } }).attributes('role')).toBe('alert');

    for (const severity of ['info', 'success', 'warning'] as const) {
      expect(mount(SvAlert, { props: { severity } }).attributes('role')).toBe('status');
    }
  });

  it('states the severity in text, so meaning survives monochrome and speech', () => {
    const wrapper = mount(SvAlert, { props: { severity: 'warning' }, slots: { default: 'Careful' } });

    expect(wrapper.find('.sr-only').text()).toContain('Warning');
  });

  it('renders a distinct icon per severity', () => {
    const seen = new Set<string>();
    for (const severity of ['info', 'success', 'warning', 'error'] as const) {
      seen.add(mount(SvAlert, { props: { severity } }).get('svg').html());
    }

    expect(seen.size).toBe(4);
  });

  it('offers dismissal only when asked, with a named control', () => {
    expect(mount(SvAlert).find('button').exists()).toBe(false);

    const wrapper = mount(SvAlert, { props: { dismissible: true } });
    expect(wrapper.get('button').attributes('aria-label')).toBe('Dismiss message');
  });

  it('emits dismiss when the control is used', async () => {
    const wrapper = mount(SvAlert, { props: { dismissible: true } });

    await wrapper.get('button').trigger('click');
    expect(wrapper.emitted('dismiss')).toHaveLength(1);
  });
});

describe('SvBanner', () => {
  it('is a NAMED region landmark, so it can be navigated to deliberately', () => {
    const wrapper = mount(SvBanner, { props: { label: 'Billing notice' } });

    expect(wrapper.attributes('role')).toBe('region');
    expect(wrapper.attributes('aria-label')).toBe('Billing notice');
  });

  it('is not a live region — it is present on load, not a change', () => {
    const wrapper = mount(SvBanner, { props: { label: 'Billing notice' } });

    expect(wrapper.attributes('aria-live')).toBeUndefined();
    expect(wrapper.attributes('role')).not.toBe('alert');
  });

  it('names the banner in its dismiss control', () => {
    const wrapper = mount(SvBanner, { props: { label: 'Billing notice', dismissible: true } });

    expect(wrapper.get('button').attributes('aria-label')).toBe('Dismiss Billing notice');
  });
});

describe('SvSkeleton', () => {
  it('hides the placeholder blocks from assistive technology', () => {
    const wrapper = mount(SvSkeleton);

    expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
  });

  it('announces loading exactly once, politely', () => {
    const wrapper = mount(SvSkeleton, { props: { label: 'Loading payout runs' } });
    const live = wrapper.findAll('[aria-live="polite"]');

    expect(live).toHaveLength(1);
    expect(live[0].text()).toBe('Loading payout runs');
  });

  it('can stay silent when an ancestor already announces loading', () => {
    // Two announcements for one load is worse than none.
    expect(mount(SvSkeleton, { props: { label: '' } }).findAll('[aria-live]')).toHaveLength(0);
  });

  it('never renders text that could be mistaken for data', () => {
    const wrapper = mount(SvSkeleton, { props: { shape: 'text', lines: 3, label: '' } });

    expect(wrapper.text()).toBe('');
  });
});

describe('the five state components stay semantically distinct', () => {
  it('uses alert only for a genuine failure', () => {
    // A failure the user did not cause is announced; a refusal or a business state is not.
    expect(mount(SvErrorState).attributes('role')).toBe('alert');
    expect(mount(SvPermissionState).attributes('role')).toBe('status');
    expect(mount(SvLockedState).attributes('role')).toBe('status');
    expect(mount(SvOfflineState).attributes('role')).toBe('status');
  });

  it('gives each state its own test id, so a page cannot silently substitute one for another', () => {
    const ids = [
      mount(SvEmptyState, { props: { title: 'Nothing yet' } }).attributes('data-testid'),
      mount(SvErrorState).attributes('data-testid'),
      mount(SvPermissionState).attributes('data-testid'),
      mount(SvLockedState).attributes('data-testid'),
      mount(SvOfflineState).attributes('data-testid'),
    ];

    // Every id must be PRESENT as well as distinct. Without this, a missing id counts as a
    // unique `undefined` and the set stays size 5 — which is how this test first passed while
    // SvEmptyState carried no id at all.
    expect(ids.filter((id) => id !== undefined)).toHaveLength(5);
    expect(new Set(ids).size).toBe(5);
  });
});

describe('SvErrorState', () => {
  it('shows the failure and a retry when repeating is safe', () => {
    const wrapper = mount(SvErrorState, { props: { message: 'We couldn’t load this invoice.' } });

    expect(wrapper.text()).toContain('We couldn’t load this invoice.');
    expect(wrapper.find('[data-testid="sv-error-retry"]').exists()).toBe(true);
  });

  it('withholds retry when repeating the operation is not safe', () => {
    // Offering "Try again" after a failed financial mutation invites a duplicate submission.
    const wrapper = mount(SvErrorState, { props: { retryable: false } });

    expect(wrapper.find('[data-testid="sv-error-retry"]').exists()).toBe(false);
  });

  it('surfaces a correlation id when one is available', () => {
    const wrapper = mount(SvErrorState, { props: { correlationId: '01JABCDEFGHJKMNPQRSTVWXYZ0' } });

    expect(wrapper.text()).toContain('01JABCDEFGHJKMNPQRSTVWXYZ0');
  });

  it('emits retry', async () => {
    const wrapper = mount(SvErrorState);

    await wrapper.get('[data-testid="sv-error-retry"]').trigger('click');
    expect(wrapper.emitted('retry')).toHaveLength(1);
  });
});

describe('SvPermissionState', () => {
  it('never names a resource, an owner, or whether anything exists', () => {
    // The non-enumeration boundary. UI-03 proved the server refuses without disclosing; the UI
    // must not undo that by being helpful.
    const text = mount(SvPermissionState).text();

    expect(text).not.toMatch(/invoice|receipt|payout|merchant|branch|INV-|\bid\b/i);
  });

  it('offers no retry, because a correct refusal is not a failure to retry', () => {
    expect(mount(SvPermissionState).find('button').exists()).toBe(false);
  });

  it('reads as a refusal rather than an error', () => {
    expect(mount(SvPermissionState).text()).toContain('don’t have access');
  });
});

describe('SvLockedState', () => {
  it('may state the reason and moment, because the user is entitled to see this record', () => {
    const wrapper = mount(SvLockedState, {
      props: {
        message: 'This period was locked and can no longer be edited.',
        lockedAt: '2026-07-31T21:00:00Z',
        lockedBy: 'A. Wanjiru',
      },
    });

    expect(wrapper.text()).toContain('no longer be edited');
    expect(wrapper.text()).toContain('A. Wanjiru');
    // The timestamp goes through SvDateTime, so it is formatted in Africa/Nairobi.
    expect(wrapper.find('time').exists()).toBe(true);
  });

  it('omits the provenance line entirely when the record carries none', () => {
    const wrapper = mount(SvLockedState);

    expect(wrapper.find('time').exists()).toBe(false);
  });
});

describe('SvOfflineState', () => {
  it('names the connection as the cause, not a generic failure', () => {
    // Telling a user on a dropped connection that "something went wrong" sends them to support
    // for a problem support cannot fix.
    expect(mount(SvOfflineState).text()).toContain('offline');
  });

  it('offers retry, because reconnecting and retrying is the user’s next step', async () => {
    const wrapper = mount(SvOfflineState);

    await wrapper.get('[data-testid="sv-offline-retry"]').trigger('click');
    expect(wrapper.emitted('retry')).toHaveLength(1);
  });
});

describe('SvEmptyState', () => {
  it('renders the supplied title and optional action', async () => {
    const wrapper = mount(SvEmptyState, {
      props: { title: 'No clients yet', actionLabel: 'Add client' },
    });

    expect(wrapper.text()).toContain('No clients yet');
    await wrapper.get('button').trigger('click');
    expect(wrapper.emitted('action')).toHaveLength(1);
  });

  it('renders a Heroicon illustration, never an emoji', () => {
    // UI01-ASSET-001: this component previously rendered 📋.
    const wrapper = mount(SvEmptyState, { props: { title: 'Empty' } });

    expect(wrapper.find('svg').exists()).toBe(true);
    expect(wrapper.html()).not.toMatch(/[\u{1F300}-\u{1FAFF}]/u);
  });
});

describe('SvToast', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
  });

  function withToast(type: 'success' | 'error' | 'warning' | 'info' = 'success') {
    const store = useNotificationStore();
    const id = store.addToast({ type, message: 'Payout run approved.' });
    const wrapper = mount(SvToast, { attachTo: document.body });

    return { store, id, wrapper };
  }

  it('announces through exactly ONE live region', () => {
    // The Phase 4 component nested role="status" inside aria-live, announcing each message twice.
    //
    // The defect was the NESTING, not the role: `aria-live` alone confers no role, so a region
    // with no `role="status"` cannot be addressed by role at all. Both therefore belong on the
    // SAME element, and what must be asserted is that exactly one element carries them and that
    // no toast inside the region is itself a live region.
    const { wrapper } = withToast();

    const live = document.querySelectorAll('[aria-live], [role="status"]');
    expect(live).toHaveLength(1);
    expect(live[0]?.getAttribute('aria-live')).toBe('polite');
    expect(live[0]?.getAttribute('role')).toBe('status');
    expect(live[0]?.querySelectorAll('[aria-live], [role="status"], [role="alert"]')).toHaveLength(0);
    wrapper.unmount();
  });

  it('states the severity in text, never by colour alone', () => {
    const { wrapper } = withToast('error');

    expect(document.body.textContent).toContain('Error:');
    wrapper.unmount();
  });

  it('renders a Heroicon rather than a hand-rolled SVG path', () => {
    const { wrapper } = withToast();

    // Heroicons carry data-slot="icon"; the retired inline path did not.
    expect(document.querySelector('[data-testid="sv-toast-region"] svg[data-slot="icon"]')).not.toBeNull();
    wrapper.unmount();
  });

  it('gives the dismiss control a 44px target and a specific name', () => {
    const { id, wrapper } = withToast();
    const dismiss = document.querySelector(`[data-testid="sv-toast-dismiss-${id}"]`) as HTMLElement;

    expect(dismiss.getAttribute('aria-label')).toBe('Dismiss: Payout run approved.');
    expect(dismiss.className).toContain('min-h-sv-touch');
    wrapper.unmount();
  });

  it('clears the fixed footer, so its dismiss control is never covered', () => {
    // ADR-024 names toast dismissal controls explicitly. The offset uses the SAME token the page
    // reserves, so the two cannot drift apart.
    const { wrapper } = withToast();
    const region = document.querySelector('[data-testid="sv-toast-region"]') as HTMLElement;

    expect(region.getAttribute('style')).toContain('--sv-footer-height-mobile');
    expect(region.className).toContain('z-sv-toast');
    wrapper.unmount();
  });

  it('auto-dismisses after the timer', () => {
    const { store, wrapper } = withToast();
    expect(store.toasts).toHaveLength(1);

    vi.advanceTimersByTime(5000);

    expect(store.toasts).toHaveLength(0);
    wrapper.unmount();
  });

  it('pauses the timer on hover AND on focus', async () => {
    // Pausing only on hover strands a keyboard user, who cannot hover.
    const { store, id, wrapper } = withToast();
    const toast = document.querySelector(`[data-testid="sv-toast-${id}"]`) as HTMLElement;

    toast.dispatchEvent(new Event('focusin', { bubbles: true }));
    vi.advanceTimersByTime(10000);

    expect(store.toasts).toHaveLength(1);
    wrapper.unmount();
  });

  it('dismisses on demand', async () => {
    const { store, id, wrapper } = withToast();

    (document.querySelector(`[data-testid="sv-toast-dismiss-${id}"]`) as HTMLElement).click();
    await wrapper.vm.$nextTick();

    expect(store.toasts).toHaveLength(0);
    wrapper.unmount();
  });

  it('schedules a toast added AFTER mount', async () => {
    // The Phase 4 component only scheduled on mount, so later toasts never expired.
    const store = useNotificationStore();
    const wrapper = mount(SvToast, { attachTo: document.body });

    store.addToast({ type: 'info', message: 'Added later.' });
    // The watcher that schedules the new timer runs on the next tick; advancing the clock before
    // it has run would test nothing.
    await wrapper.vm.$nextTick();
    vi.advanceTimersByTime(5000);

    expect(store.toasts).toHaveLength(0);
    wrapper.unmount();
  });

  it('keeps the stack order deterministic', () => {
    const store = useNotificationStore();
    store.addToast({ type: 'info', message: 'First' });
    store.addToast({ type: 'info', message: 'Second' });
    const wrapper = mount(SvToast, { attachTo: document.body });

    const text = (document.querySelector('[data-testid="sv-toast-region"]') as HTMLElement).textContent ?? '';
    expect(text.indexOf('First')).toBeLessThan(text.indexOf('Second'));
    wrapper.unmount();
  });
});
