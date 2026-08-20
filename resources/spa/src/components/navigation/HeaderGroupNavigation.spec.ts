import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import HeaderGroupNavigation from '@/components/navigation/HeaderGroupNavigation.vue';
import type { NavigationNode } from '@/navigation/navigationFilter';

/**
 * Increment 9A. The Super Administrator header carries 22 contract entries across 8 groups, so
 * these cases prove the grouping, the CSS-declared overflow, the gated treatment and the keyboard
 * contract — independently of how many entries the live registry happens to expose today.
 */

const Blank = { template: '<div />' };

function node(partial: Partial<NavigationNode> & { key: string; group: string }): NavigationNode {
  return {
    label: partial.label ?? partial.key,
    description: '',
    order: partial.order ?? 1,
    icon: 'dashboard' as NavigationNode['icon'],
    routeName: partial.routeName ?? null,
    disabled: partial.disabled ?? false,
    disabledReason: partial.disabledReason ?? null,
    children: [],
    ...partial,
  } as NavigationNode;
}

/** One entry per contract group, plus a gated entry, mirroring the UI-08 disposition. */
const NODES: NavigationNode[] = [
  node({ key: 'sa.dashboard', label: 'Dashboard', group: 'Home', routeName: 'platform.dashboard', order: 1 }),
  node({ key: 'sa.get-started', label: 'Get Started', group: 'Home', routeName: 'platform.get-started', order: 2 }),
  node({ key: 'sa.billing-settings', label: 'Platform Billing Settings', group: 'Billing & Commercial', routeName: 'platform.billing-settings', order: 3 }),
  node({ key: 'sa.billing-sms', label: 'SMS Billing Settings', group: 'Billing & Commercial', routeName: 'platform.billing-sms', order: 9 }),
  node({ key: 'sa.merchants', label: 'Merchant Directory', group: 'Merchants', routeName: 'platform.merchants', order: 11 }),
  node({ key: 'sa.billing-subscriptions', label: 'Subscription Operations', group: 'Billing Operations', routeName: 'platform.billing-subscriptions', order: 13 }),
  node({
    key: 'sa.billing-reconciliation-exceptions',
    label: 'Billing Reconciliation Exceptions',
    group: 'Billing Operations',
    order: 14,
    disabled: true,
    disabledReason: 'External Gate W — Wallet by Citrus collections readiness',
  }),
  node({
    key: 'sa.integrations',
    label: 'Integrations Health',
    group: 'Integrations',
    order: 15,
    disabled: true,
    disabledReason: 'External Gate W — Wallet by Citrus collections readiness',
  }),
  node({ key: 'sa.audit', label: 'Platform Audit', group: 'Reporting & Audit', routeName: 'platform.audit', order: 18 }),
  node({ key: 'sa.platform-access', label: 'Internal Platform Access', group: 'Platform Administration', routeName: 'platform.platform-access', order: 19 }),
  node({ key: 'sa.feature-flags', label: 'Feature Flags', group: 'Platform Administration', routeName: 'platform.feature-flags', order: 20 }),
  node({ key: 'sa.account', label: 'Account and Security', group: 'Utility', routeName: 'platform.account', order: 22 }),
];

let router: Router;

async function mountNav(variant: 'header' | 'stacked' = 'header', nodes: NavigationNode[] = NODES) {
  const wrapper = mount(HeaderGroupNavigation, {
    props: { nodes, variant },
    global: { plugins: [router], stubs: { Teleport: true } },
    attachTo: document.body,
  });
  await flushPromises();
  return wrapper;
}

describe('HeaderGroupNavigation.vue — Increment 9A', () => {
  beforeEach(async () => {
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/dashboard', name: 'platform.dashboard', component: Blank },
        { path: '/get-started', name: 'platform.get-started', component: Blank },
        { path: '/billing/settings', name: 'platform.billing-settings', component: Blank },
        { path: '/billing/sms', name: 'platform.billing-sms', component: Blank },
        { path: '/merchants', name: 'platform.merchants', component: Blank },
        { path: '/billing/subscriptions', name: 'platform.billing-subscriptions', component: Blank },
        { path: '/audit', name: 'platform.audit', component: Blank },
        { path: '/platform-access', name: 'platform.platform-access', component: Blank },
        { path: '/platform/feature-flags', name: 'platform.feature-flags', component: Blank },
        { path: '/account', name: 'platform.account', component: Blank },
        { path: '/merchants/:merchantUlid', name: 'platform.merchant-detail', component: Blank },
      ],
    });
    router.push('/dashboard');
    await router.isReady();
  });

  it('renders the eight contract groups in their contract order', async () => {
    const wrapper = await mountNav();
    const triggers = wrapper.findAll('[data-testid^="nav-group-trigger-"]');
    expect(triggers.map((t) => t.text().replace(/\s+/g, ' ').trim().replace(' (contains the current page)', ''))).toEqual([
      'Home',
      'Billing & Commercial',
      'Merchants',
      'Billing Operations',
      'Integrations',
      'Reporting & Audit',
      'Platform Administration',
      'Utility',
    ]);
  });

  it('renders Front Office groups in the UI-13 operational order with Utility last', async () => {
    const groups = [
      'Home',
      'Quick Access',
      'Clients',
      'Appointments & Walk-Ins',
      'Queue & Service',
      'Billing Client',
      'Billing Banner',
      'Utility',
    ];
    const wrapper = await mountNav(
      'stacked',
      groups.map((group, index) => node({
        key: `front-office.${index}`,
        group,
        disabled: true,
        disabledReason: 'Test-only inert item',
        order: index + 1,
      })),
    );

    expect(wrapper.findAll('section > p').map((label) => label.text())).toEqual(groups);
  });

  it('places each entry in its own group', async () => {
    const wrapper = await mountNav();
    await wrapper.find('[data-testid="nav-group-trigger-nav-group-billing-commercial"]').trigger('click');
    await flushPromises();

    const panel = wrapper.find('#nav-group-billing-commercial');
    expect(panel.text()).toContain('Platform Billing Settings');
    expect(panel.text()).toContain('SMS Billing Settings');
    expect(panel.text()).not.toContain('Merchant Directory');
  });

  it('marks the group that contains the active route, and the active link itself', async () => {
    const wrapper = await mountNav();
    const home = wrapper.find('[data-testid="nav-group-trigger-nav-group-home"]');
    expect(home.text()).toContain('contains the current page');

    await home.trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="nav-link-sa.dashboard"]').attributes('aria-current')).toBe('page');
  });

  it('keeps a visible parent current while its contextual detail child is active', async () => {
    const parent = node({
      key: 'sa.merchants',
      label: 'Merchant Directory',
      group: 'Merchants',
      routeName: 'platform.merchants',
      children: [node({
        key: 'sa.merchant-detail',
        label: 'Merchant detail',
        group: 'Merchants',
        routeName: 'platform.merchant-detail',
      })],
    });
    await router.push('/merchants/01JQMERCHANT');
    const wrapper = await mountNav('stacked', [parent]);

    expect(wrapper.find('[data-testid="nav-stacked-link-sa.merchants"]').attributes('aria-current')).toBe('page');
  });

  it('renders a gated entry as inert, named, and without a destination', async () => {
    const wrapper = await mountNav();
    await wrapper.find('[data-testid="nav-group-trigger-nav-group-billing-operations"]').trigger('click');
    await flushPromises();

    const gated = wrapper.find('[data-testid="nav-gated-sa.billing-reconciliation-exceptions"]');
    expect(gated.exists()).toBe(true);
    expect(gated.attributes('aria-disabled')).toBe('true');
    expect(gated.element.tagName).not.toBe('A');
    expect(gated.attributes('href')).toBeUndefined();
    expect(wrapper.find('[data-testid="nav-gate-reason-sa.billing-reconciliation-exceptions"]').text())
      .toContain('External Gate W');
  });

  it('keeps a gated entry keyboard-discoverable so its reason can be read', async () => {
    const wrapper = await mountNav();
    await wrapper.find('[data-testid="nav-group-trigger-nav-group-integrations"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="nav-gated-sa.integrations"]').attributes('tabindex')).toBe('0');
  });

  it('never labels a gated entry with a vague placeholder', async () => {
    const wrapper = await mountNav();
    await wrapper.find('[data-testid="nav-group-trigger-nav-group-integrations"]').trigger('click');
    await flushPromises();
    const text = wrapper.text().toLowerCase();
    expect(text).not.toContain('coming soon');
    expect(text).not.toContain('soon');
  });

  it('exposes the disclosure state to assistive technology', async () => {
    const wrapper = await mountNav();
    const trigger = wrapper.find('[data-testid="nav-group-trigger-nav-group-merchants"]');
    expect(trigger.attributes('aria-expanded')).toBe('false');
    expect(trigger.attributes('aria-controls')).toBe('nav-group-merchants');

    await trigger.trigger('click');
    expect(trigger.attributes('aria-expanded')).toBe('true');
  });

  it('opens at most one group at a time', async () => {
    const wrapper = await mountNav();
    await wrapper.find('[data-testid="nav-group-trigger-nav-group-home"]').trigger('click');
    await flushPromises();
    await wrapper.find('[data-testid="nav-group-trigger-nav-group-merchants"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('#nav-group-home').exists()).toBe(false);
    expect(wrapper.find('#nav-group-merchants').exists()).toBe(true);
  });

  it('opens a group with ArrowDown from its trigger', async () => {
    const wrapper = await mountNav();
    const trigger = wrapper.find('[data-testid="nav-group-trigger-nav-group-home"]');
    await trigger.trigger('keydown', { key: 'ArrowDown' });
    await flushPromises();
    expect(wrapper.find('#nav-group-home').exists()).toBe(true);
  });

  it('closes on Escape and returns focus to the trigger', async () => {
    const wrapper = await mountNav();
    const trigger = wrapper.find('[data-testid="nav-group-trigger-nav-group-home"]');
    await trigger.trigger('click');
    await flushPromises();

    await wrapper.find('#nav-group-home').trigger('keydown', { key: 'Escape' });
    await flushPromises();

    expect(wrapper.find('#nav-group-home').exists()).toBe(false);
    expect(document.activeElement).toBe(trigger.element);
    wrapper.unmount();
  });

  it('closes when the pointer goes outside the navigation', async () => {
    const wrapper = await mountNav();
    await wrapper.find('[data-testid="nav-group-trigger-nav-group-home"]').trigger('click');
    await flushPromises();

    // jsdom provides no `PointerEvent` constructor. A plain Event of the same type is sufficient:
    // the listener is registered for `pointerdown` and only reads `event.target`.
    document.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    await flushPromises();

    expect(wrapper.find('#nav-group-home').exists()).toBe(false);
    wrapper.unmount();
  });

  it('collapses the header after navigating', async () => {
    const wrapper = await mountNav();
    await wrapper.find('[data-testid="nav-group-trigger-nav-group-home"]').trigger('click');
    await flushPromises();

    await wrapper.find('[data-testid="nav-link-sa.get-started"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('#nav-group-home').exists()).toBe(false);
    expect(wrapper.emitted('navigate')).toHaveLength(1);
  });

  /**
   * The overflow is declarative: the tail groups render twice and CSS reveals exactly one. The
   * assertion is on the CLASSES that make that true, because a width probe would violate the
   * no-JS-device-detection guardrail and cannot be asserted in jsdom anyway.
   */
  it('declares the overflow with CSS breakpoints rather than measuring the container', async () => {
    const wrapper = await mountNav();

    // The first five groups are inline at every width.
    for (const id of ['home', 'billing-commercial', 'merchants', 'billing-operations', 'integrations']) {
      expect(wrapper.find(`[data-testid="nav-group-item-nav-group-${id}"]`).classes()).not.toContain('hidden');
    }
    /*
     * The tail groups are inline only from the DESKTOP floor upward.
     *
     * This asserted `xl:block` until Increment 10's browser proof found that `xl` DOES NOT EXIST in
     * this Tailwind config: `tailwind.config` overrides `screens` to exactly `md` (tablet floor) and
     * `lg` (desktop floor), so an unmandated breakpoint cannot be used. `hidden xl:block` therefore
     * compiled to a permanent `hidden`, and three of the eight contract groups were reachable only
     * through "More" even at 1440px — while this case passed, because it checked the class STRING
     * and not whether the breakpoint resolves. It now pins the breakpoint to one the config defines.
     */
    const BREAKPOINTS = ['md', 'lg'];
    for (const id of ['reporting-audit', 'platform-administration', 'utility']) {
      const classes = wrapper.find(`[data-testid="nav-group-item-nav-group-${id}"]`).classes();
      expect(classes).toContain('hidden');
      const responsive = classes.filter((name) => name.includes(':'));
      expect(responsive, `${id} must reveal itself at a configured breakpoint`).not.toEqual([]);
      for (const name of responsive) {
        expect(BREAKPOINTS, `${id} uses "${name}", but only ${BREAKPOINTS.join('/')} exist`).toContain(name.split(':')[0]);
      }
      expect(classes).toContain('lg:block');
    }
    // ...and the overflow that carries them below desktop is hidden from desktop upward.
    expect(wrapper.find('[data-testid="nav-overflow-trigger"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="nav-group-item-nav-group-utility"]').classes()).toContain('lg:block');
  });

  it('reaches every tail group through the overflow', async () => {
    const wrapper = await mountNav();
    await wrapper.find('[data-testid="nav-overflow-trigger"]').trigger('click');
    await flushPromises();

    const panel = wrapper.find('[data-testid="nav-overflow-panel"]');
    expect(panel.text()).toContain('Reporting & Audit');
    expect(panel.text()).toContain('Platform Administration');
    expect(panel.text()).toContain('Utility');
    expect(wrapper.find('[data-testid="nav-overflow-link-sa.account"]').exists()).toBe(true);
  });

  it('renders every group as an open labelled section in the stacked (drawer) variant', async () => {
    const wrapper = await mountNav('stacked');
    expect(wrapper.find('[data-testid="stacked-primary-nav"]').exists()).toBe(true);

    for (const label of ['Home', 'Billing & Commercial', 'Merchants', 'Utility']) {
      expect(wrapper.text()).toContain(label);
    }
    // Every live entry is reachable without opening a disclosure.
    expect(wrapper.find('[data-testid="nav-stacked-link-sa.dashboard"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="nav-stacked-link-sa.account"]').exists()).toBe(true);
  });

  it('keeps the drawer and the header showing the same entries', async () => {
    const header = await mountNav('header');
    const stacked = await mountNav('stacked');

    const liveKeys = NODES.filter((n) => !n.disabled && n.routeName !== null).map((n) => n.key);
    for (const key of liveKeys) {
      expect(stacked.find(`[data-testid="nav-stacked-link-${key}"]`).exists(), key).toBe(true);
    }
    // The header reaches the same set across its inline groups and its overflow.
    expect(header.findAll('[data-testid^="nav-group-trigger-"]')).toHaveLength(8);
  });

  it('renders a gated entry in the drawer too, still inert', async () => {
    const wrapper = await mountNav('stacked');
    const gated = wrapper.find('[data-testid="nav-stacked-gated-sa.integrations"]');
    expect(gated.attributes('aria-disabled')).toBe('true');
    expect(gated.text()).toContain('External Gate W');
  });

  it('omits a group entirely when the user may see none of its entries', async () => {
    const wrapper = await mountNav('header', NODES.filter((n) => n.group !== 'Merchants'));
    expect(wrapper.find('[data-testid="nav-group-trigger-nav-group-merchants"]').exists()).toBe(false);
    expect(wrapper.findAll('[data-testid^="nav-group-trigger-"]')).toHaveLength(7);
  });
});
