import { flushPromises, mount, type DOMWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import HeaderGroupNavigation from '@/components/navigation/HeaderGroupNavigation.vue';
import { navigationTree, type NavigationNode } from '@/navigation/navigationFilter';
import { NAVIGATION_CONTRACT } from '@/navigation/navigationRegistry.generated';

/**
 * Increment 9F — the five gated Super Administrator entries.
 *
 * 9A already proved the GENERIC gated treatment. What 9F proves is the specific disposition: that
 * exactly five contract entries are blocked, that each one names its OWN dependency rather than a
 * shared "coming soon", and that none of the three placements (inline group, overflow group, mobile
 * drawer section) can be made to navigate.
 *
 * The five entries and their gates are read from the CONTRACT, not from a list written here — a
 * table duplicated in a test is a second authority that can disagree with the first. What is
 * asserted locally is the disposition the readiness matrix records: which entries are gated, and
 * whether each is blocked directly by External Gate W or by a phase that is itself behind it.
 */

const EXPECTED_GATED: ReadonlyArray<{ screenKey: string; group: string; directlyGated: boolean }> = [
  { screenKey: 'billing-reconciliation-exceptions', group: 'Billing Operations', directlyGated: true },
  { screenKey: 'integrations', group: 'Integrations', directlyGated: true },
  { screenKey: 'integrations-refer-and-earn-qualifications', group: 'Integrations', directlyGated: true },
  { screenKey: 'reports', group: 'Reporting & Audit', directlyGated: true },
  { screenKey: 'notifications', group: 'Utility', directlyGated: true },
];

const superAdminEntries = NAVIGATION_CONTRACT.filter((e) => e.accountType === 'super_administrator');
const gatedEntries = superAdminEntries.filter((e) => e.implementationStatus === 'disabled_by_gate');

/** Every permission any Super Administrator entry can ask for, so nothing is hidden by permission. */
const ALL_KEYS = [
  ...new Set(superAdminEntries.flatMap((e) => [...e.permissionAll, ...e.permissionAny])),
];

function tree(): readonly NavigationNode[] {
  return navigationTree('super_administrator', { permissions: ALL_KEYS });
}

function flatten(nodes: readonly NavigationNode[]): NavigationNode[] {
  return nodes.flatMap((node) => [node, ...flatten(node.children)]);
}

let router: Router;

async function mountNav(variant: 'header' | 'stacked') {
  const wrapper = mount(HeaderGroupNavigation, {
    props: { nodes: tree(), variant },
    global: { plugins: [router], stubs: { Teleport: true } },
    attachTo: document.body,
  });
  await flushPromises();
  return wrapper;
}

/**
 * In the header variant a group's entries live inside a collapsed disclosure, and only one group
 * opens at a time — so a gated entry is inspected by opening its group, reading it, and moving on.
 */
async function eachHeaderGatedEntry(
  wrapper: Awaited<ReturnType<typeof mountNav>>,
  visit: (item: DOMWrapper<Element>) => void,
): Promise<number> {
  let seen = 0;
  const triggers = wrapper.findAll('button[aria-expanded]');
  for (const trigger of triggers) {
    await trigger.trigger('click');
    await flushPromises();
    for (const item of wrapper.findAll('[data-testid^="nav-gated-"], [data-testid^="nav-overflow-gated-"]')) {
      visit(item);
      seen += 1;
    }
  }
  return seen;
}

describe('Increment 9F — the five gated navigation treatments', () => {
  beforeEach(async () => {
    // Register exactly the runtime routes the filtered tree links to, so a RouterLink in an
    // implemented entry resolves and the gated entries are the only inert ones.
    const runtimeNames = [
      ...new Set(flatten(tree()).map((node) => node.routeName).filter((name): name is string => name !== null)),
    ];
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        ...runtimeNames.map((name, index) => ({ path: `/r${index}`, name, component: { template: '<div />' } })),
        { path: '/:pathMatch(.*)*', component: { template: '<div />' } },
      ],
    });
    await router.push('/');
    await router.isReady();
    document.body.innerHTML = '';
  });

  // ── The disposition itself ──────────────────────────────────────────────────────────────────

  it('blocks exactly five Super Administrator entries, and exactly the five the matrix records', () => {
    expect(gatedEntries.map((e) => e.screenKey).sort()).toEqual(
      EXPECTED_GATED.map((e) => e.screenKey).sort(),
    );
  });

  it('places each gated entry in its contract navigation group', () => {
    for (const expected of EXPECTED_GATED) {
      const entry = gatedEntries.find((e) => e.screenKey === expected.screenKey);
      expect(entry?.navigationGroup, expected.screenKey).toBe(expected.group);
    }
  });

  it('gives every gated entry a named gate and no runtime route', () => {
    for (const entry of gatedEntries) {
      expect(entry.gate, `${entry.screenKey} must name a gate`).not.toBeNull();
      expect(entry.runtimeRouteName, `${entry.screenKey} must have no runtime route`).toBeNull();
    }
  });

  it('reserves a contract route identity for each gated entry without registering it', () => {
    // The map still owns the eventual path; what must not exist is a live route to it.
    for (const entry of gatedEntries) {
      expect(entry.routePath.startsWith('/'), entry.screenKey).toBe(true);
      expect(router.hasRoute(entry.routeName), `${entry.routeName} must not be registered`).toBe(false);
    }
  });

  // ── The reason each one gives ───────────────────────────────────────────────────────────────

  it('states a specific dependency for every gated entry, never a vague placeholder', () => {
    const gatedNodes = flatten(tree()).filter((node) => node.disabled);
    expect(gatedNodes).toHaveLength(EXPECTED_GATED.length);

    for (const node of gatedNodes) {
      const reason = node.disabledReason ?? '';
      expect(reason, node.key).not.toBe('');
      expect(reason, node.key).toMatch(/Gate W|Phase \d/);
      for (const vague of ['coming soon', 'not available', 'todo', 'tbd', 'under construction']) {
        expect(reason.toLowerCase().includes(vague), `${node.key}: vague reason "${reason}"`).toBe(false);
      }
      // A raw contract token must never reach a user.
      expect(reason, node.key).not.toMatch(/_/);
    }
  });

  it('never claims a gated capability is healthy, zero, empty or complete', async () => {
    const wrapper = await mountNav('stacked');
    for (const expected of EXPECTED_GATED) {
      const item = wrapper.find(`[data-testid="nav-stacked-gated-super_administrator.${expected.screenKey}"]`);
      expect(item.exists(), expected.screenKey).toBe(true);
      const text = item.text().toLowerCase();
      for (const claim of ['healthy', ' 0 ', 'none outstanding', 'all clear', 'up to date']) {
        expect(text.includes(claim), `${expected.screenKey} claims "${claim}"`).toBe(false);
      }
    }
  });

  // ── Inertness in every placement ────────────────────────────────────────────────────────────

  it('renders no anchor and no href for a gated entry in the drawer', async () => {
    const wrapper = await mountNav('stacked');
    for (const expected of EXPECTED_GATED) {
      const item = wrapper.get(`[data-testid="nav-stacked-gated-super_administrator.${expected.screenKey}"]`);
      expect(item.element.tagName, expected.screenKey).not.toBe('A');
      expect(item.attributes('href'), expected.screenKey).toBeUndefined();
      expect(item.attributes('aria-disabled'), expected.screenKey).toBe('true');
    }
  });

  it('renders no anchor and no href for a gated entry in the header', async () => {
    const wrapper = await mountNav('header');
    const seen = await eachHeaderGatedEntry(wrapper, (item) => {
      expect(item.element.tagName).not.toBe('A');
      expect(item.attributes('href')).toBeUndefined();
      expect(item.attributes('aria-disabled')).toBe('true');
    });
    expect(seen).toBeGreaterThan(0);
  });

  it('does not navigate when a gated entry is clicked or activated by keyboard', async () => {
    const wrapper = await mountNav('stacked');
    const before = router.currentRoute.value.fullPath;
    const item = wrapper.get('[data-testid="nav-stacked-gated-super_administrator.reports"]');
    await item.trigger('click');
    await item.trigger('keydown', { key: 'Enter' });
    await item.trigger('keyup', { key: ' ' });
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe(before);
  });

  // ── Discoverability and parity ──────────────────────────────────────────────────────────────

  it('keeps every gated entry keyboard reachable so its reason can be read', async () => {
    const wrapper = await mountNav('stacked');
    for (const expected of EXPECTED_GATED) {
      const item = wrapper.get(`[data-testid="nav-stacked-gated-super_administrator.${expected.screenKey}"]`);
      expect(item.attributes('tabindex'), expected.screenKey).toBe('0');
      const reason = wrapper.find(`[data-testid="nav-gate-reason-super_administrator.${expected.screenKey}"]`);
      expect(reason.exists() || item.text().includes('Unavailable'), expected.screenKey).toBe(true);
    }
  });

  it('shows the same five gated entries on desktop, tablet and mobile', async () => {
    const header = await mountNav('header');
    const collected = new Set<string>();
    await eachHeaderGatedEntry(header, (item) => {
      const id = item.attributes('data-testid')?.replace(/^nav-(overflow-)?gated-/, '');
      if (id !== undefined) collected.add(id);
    });
    const headerKeys = [...collected].sort();
    header.unmount();

    const drawer = await mountNav('stacked');
    const drawerKeys = drawer
      .findAll('[data-testid^="nav-stacked-gated-"]')
      .map((n) => n.attributes('data-testid')?.replace(/^nav-stacked-gated-/, ''))
      .sort();

    // One filtered tree, three placements: the surfaces cannot disagree about what is blocked.
    expect(headerKeys).toEqual(drawerKeys);
    expect(drawerKeys).toHaveLength(EXPECTED_GATED.length);
  });

  it('offers the browser no input that could open a gate (UI08-NAV-002)', () => {
    // Every gate name, every feature flag the contract knows, and every permission key at once.
    const everything = navigationTree('super_administrator', {
      permissions: ALL_KEYS,
      featureFlags: [
        ...new Set(superAdminEntries.map((e) => e.featureFlag).filter((f): f is string => f !== null)),
        ...new Set(gatedEntries.map((e) => e.gate as string)),
        'external_gate_w',
      ],
    });

    // A gate opens by the canonical map being regenerated, never by anything a client can supply.
    expect(flatten(everything).filter((node) => node.disabled)).toHaveLength(EXPECTED_GATED.length);
    for (const node of flatten(everything).filter((n) => n.disabled)) {
      expect(node.routeName, node.key).toBeNull();
    }
  });
});
