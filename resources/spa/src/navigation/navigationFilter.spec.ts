import { describe, expect, it } from 'vitest';
import { router } from '@/router';
import { ROLE_IDENTITIES } from '@/types/roles';
import { NAVIGATION_ICONS } from './navigationIcons';
import {
  NAVIGATION_CONTRACT,
  REQUIRED_PAGES_PER_ACCOUNT,
  REQUIRED_PAGES_TOTAL,
  contractForAccount,
  type NavigationContractEntry,
} from './navigationRegistry.generated';
import { flattenNavigation, navigationTree, orphanedChildren } from './navigationFilter';

/**
 * Runtime navigation filtering (Phase UI-07; UI/UX plan §7, §19.2).
 *
 * These cases prove the FILTER, not the contract: the contract's own shape is proven by
 * `Ui07NavigationRegistryContractTest` and the generator's `--check` mode. Here the question is
 * only ever "given this contract and this user, what may be rendered?".
 */

const everything = new Set(
  NAVIGATION_CONTRACT.flatMap((e) => [...e.permissionAll, ...e.permissionAny]),
);
const allPermissions = { permissions: everything };
const noPermissions = { permissions: new Set<string>() };

/** A disposable contract copy, so a negative control never mutates the real one. */
const fixture = (...entries: Partial<NavigationContractEntry>[]): NavigationContractEntry[] =>
  entries.map(
    (overrides, index) =>
      ({
        key: `merchant_finance.fixture-${index}`,
        accountType: 'merchant_finance',
        screenKey: `fixture-${index}`,
        label: `Fixture ${index}`,
        description: 'Disposable fixture entry.',
        navigationGroup: 'Home',
        parentKey: null,
        order: index,
        icon: 'dashboard',
        routeName: `finance.fixture-${index}`,
        routePath: `/fixture-${index}`,
        ownerPhase: 'UI-12',
        backendOwnerPhase: null,
        implementationStatus: 'implemented',
        runtimeRouteName: 'finance.invoices',
        routeDelivery: 'dedicated',
        permissionAny: [],
        permissionAll: [],
        scope: 'merchant',
        requiresMfa: false,
        requiresStepUp: false,
        featureFlag: null,
        billingStateBehavior: 'per_account_billing_state_allowlist',
        gate: null,
        navigationVisibility: 'primary',
        nonNavigationReason: null,
        forbiddenFor: [],
        ...overrides,
      }) as NavigationContractEntry,
  );

describe('navigation contract registry', () => {
  it('holds exactly 160 authenticated pages', () => {
    expect(NAVIGATION_CONTRACT.length).toBe(160);
    expect(REQUIRED_PAGES_TOTAL).toBe(160);
  });

  it('holds the exact per-account page counts', () => {
    expect(REQUIRED_PAGES_PER_ACCOUNT).toEqual({
      super_administrator: 22,
      merchant_administrator: 23,
      merchant_branch: 18,
      merchant_human_resource: 19,
      merchant_finance: 24,
      merchant_front_office: 19,
      merchant_personnel: 20,
      merchant_audit: 15,
    });
    for (const identity of ROLE_IDENTITIES) {
      expect(contractForAccount(identity).length, identity).toBe(REQUIRED_PAGES_PER_ACCOUNT[identity]);
    }
  });

  it('sums the account counts to the total rather than trusting a written constant', () => {
    const summed = Object.values(REQUIRED_PAGES_PER_ACCOUNT).reduce((a, b) => a + b, 0);
    expect(summed).toBe(REQUIRED_PAGES_TOTAL);
  });

  it('resolves every icon through the curated Heroicons registry', () => {
    for (const entry of NAVIGATION_CONTRACT) {
      expect(NAVIGATION_ICONS[entry.icon], `${entry.key} icon ${entry.icon}`).toBeDefined();
    }
  });

  it('uses no emoji in any label', () => {
    const emoji = /\p{Extended_Pictographic}/u;
    for (const entry of NAVIGATION_CONTRACT) {
      expect(emoji.test(entry.label), `${entry.key}`).toBe(false);
    }
  });

  it('gives every implemented entry a registered, lazily-loaded route', () => {
    const records = new Map(
      router.getRoutes().map((r) => [String(r.name), r] as const),
    );
    for (const entry of NAVIGATION_CONTRACT) {
      if (entry.implementationStatus !== 'implemented') continue;
      expect(entry.runtimeRouteName, `${entry.key}`).toBeTruthy();
      expect(records.has(entry.runtimeRouteName!), `${entry.key} → ${entry.runtimeRouteName}`).toBe(true);
    }
  });

  it('never registers a runtime route for a planned or removed page', () => {
    const records = new Set(router.getRoutes().map((r) => String(r.name)));
    for (const entry of NAVIGATION_CONTRACT) {
      if (entry.implementationStatus === 'implemented' || entry.implementationStatus === 'disabled_by_gate') {
        continue;
      }
      expect(entry.runtimeRouteName, `${entry.key}`).toBeNull();
      expect(records.has(entry.routeName), `${entry.key} reserved name must not be live`).toBe(false);
    }
  });

  it('never lets the catch-all satisfy a contract route', () => {
    // Resolving an unknown path matches `not-found`. A parity check that accepted that match
    // would report every unbuilt page as present.
    const resolved = router.resolve('/definitely-not-a-real-servana-page');
    expect(resolved.name).toBe('not-found');
    expect(NAVIGATION_CONTRACT.some((e) => e.runtimeRouteName === 'not-found')).toBe(false);
  });

  it('gives every non-primary entry a recorded reason for being outside navigation', () => {
    for (const entry of NAVIGATION_CONTRACT) {
      if (entry.navigationVisibility === 'primary') continue;
      expect(entry.nonNavigationReason, `${entry.key}`).toBeTruthy();
    }
  });
});

describe('navigationTree filtering', () => {
  it('returns only entries owned by the requested account', () => {
    for (const identity of ROLE_IDENTITIES) {
      const owner = new Map(NAVIGATION_CONTRACT.map((e) => [e.key, e.accountType]));
      for (const node of flattenNavigation(navigationTree(identity, allPermissions))) {
        expect(owner.get(node.key), node.key).toBe(identity);
      }
    }
  });

  it('never renders a planned entry', () => {
    const planned = new Set(
      NAVIGATION_CONTRACT.filter((e) => e.implementationStatus === 'planned').map((e) => e.key),
    );
    expect(planned.size).toBeGreaterThan(0);
    for (const identity of ROLE_IDENTITIES) {
      for (const node of flattenNavigation(navigationTree(identity, allPermissions))) {
        expect(planned.has(node.key), node.key).toBe(false);
      }
    }
  });

  it('never renders a removed_by_authority entry', () => {
    const removed = fixture({ implementationStatus: 'removed_by_authority', runtimeRouteName: null });
    expect(navigationTree('merchant_finance', allPermissions, removed)).toEqual([]);
  });

  it('renders a gate-blocked entry disabled, naming the exact gate, with no destination', () => {
    const gated = fixture({
      implementationStatus: 'disabled_by_gate',
      gate: 'external_gate_w',
      runtimeRouteName: null,
    });
    const [node] = navigationTree('merchant_finance', allPermissions, gated);
    expect(node.disabled).toBe(true);
    expect(node.routeName).toBeNull();
    expect(node.disabledReason).toContain('External Gate W');
  });

  it('requires every key of permission_all', () => {
    const entries = fixture({ permissionAll: ['invoice.view', 'receipt.view'] });
    expect(navigationTree('merchant_finance', { permissions: ['invoice.view'] }, entries)).toEqual([]);
    expect(
      navigationTree('merchant_finance', { permissions: ['invoice.view', 'receipt.view'] }, entries),
    ).toHaveLength(1);
  });

  it('requires at least one key of permission_any', () => {
    const entries = fixture({ permissionAny: ['invoice.view', 'receipt.view'] });
    expect(navigationTree('merchant_finance', { permissions: [] }, entries)).toEqual([]);
    expect(navigationTree('merchant_finance', { permissions: ['receipt.view'] }, entries)).toHaveLength(1);
  });

  it('requires BOTH groups to pass when both are present, never merging them', () => {
    const entries = fixture({ permissionAll: ['invoice.view'], permissionAny: ['refund.create'] });
    // Holding only the `any` key must not satisfy the `all` group.
    expect(navigationTree('merchant_finance', { permissions: ['refund.create'] }, entries)).toEqual([]);
    // Holding only the `all` key must not satisfy the `any` group.
    expect(navigationTree('merchant_finance', { permissions: ['invoice.view'] }, entries)).toEqual([]);
    expect(
      navigationTree('merchant_finance', { permissions: ['invoice.view', 'refund.create'] }, entries),
    ).toHaveLength(1);
  });

  it('fails closed when permission data is missing entirely', () => {
    const entries = fixture({ permissionAll: ['invoice.view'] });
    expect(navigationTree('merchant_finance', { permissions: [] }, entries)).toEqual([]);
  });

  it('hides an entry whose feature flag is not enabled, and shows it when it is', () => {
    const entries = fixture({ featureFlag: 'finance_beta' });
    expect(navigationTree('merchant_finance', noPermissions, entries)).toEqual([]);
    expect(
      navigationTree('merchant_finance', { permissions: [], featureFlags: ['finance_beta'] }, entries),
    ).toHaveLength(1);
  });

  it('hides an entry that forbids the requesting account', () => {
    const entries = fixture({ forbiddenFor: ['merchant_finance'] });
    expect(navigationTree('merchant_finance', allPermissions, entries)).toEqual([]);
  });

  it('keeps contextual children under their parent and never at the root', () => {
    const entries = fixture(
      { key: 'merchant_finance.parent', screenKey: 'parent', order: 1 },
      {
        key: 'merchant_finance.child',
        screenKey: 'child',
        order: 2,
        parentKey: 'merchant_finance.parent',
        navigationVisibility: 'contextual_child',
        nonNavigationReason: 'reached from its parent',
      },
    );
    const tree = navigationTree('merchant_finance', allPermissions, entries);
    expect(tree).toHaveLength(1);
    expect(tree[0].key).toBe('merchant_finance.parent');
    expect(tree[0].children.map((c) => c.key)).toEqual(['merchant_finance.child']);
  });

  it('drops an orphaned child rather than surfacing it at the root', () => {
    const entries = fixture(
      { key: 'merchant_finance.parent', screenKey: 'parent', permissionAll: ['invoice.view'] },
      {
        key: 'merchant_finance.child',
        screenKey: 'child',
        parentKey: 'merchant_finance.parent',
        navigationVisibility: 'contextual_child',
        nonNavigationReason: 'reached from its parent',
      },
    );
    // The parent is filtered out by permission; the child must not be promoted.
    const tree = navigationTree('merchant_finance', { permissions: [] }, entries);
    expect(tree).toEqual([]);
    expect(orphanedChildren('merchant_finance', { permissions: [] }, entries)).toEqual([
      'merchant_finance.child',
    ]);
  });

  it('orders siblings by the contract order, never by display text', () => {
    const entries = fixture(
      { key: 'merchant_finance.z', screenKey: 'z', label: 'Aardvark', order: 9 },
      { key: 'merchant_finance.a', screenKey: 'a', label: 'Zebra', order: 1 },
    );
    expect(navigationTree('merchant_finance', allPermissions, entries).map((n) => n.label)).toEqual([
      'Zebra',
      'Aardvark',
    ]);
  });

  it('produces the same filtered result whatever the viewport — one result, three placements', () => {
    // Desktop, tablet rail and mobile drawer all consume this single value; there is no
    // viewport parameter to disagree about.
    for (const identity of ROLE_IDENTITIES) {
      const first = navigationTree(identity, allPermissions);
      const second = navigationTree(identity, allPermissions);
      expect(flattenNavigation(second).map((n) => n.key)).toEqual(flattenNavigation(first).map((n) => n.key));
    }
  });

  it('leaves no orphaned child in any account at full permission', () => {
    for (const identity of ROLE_IDENTITIES) {
      expect(orphanedChildren(identity, allPermissions), identity).toEqual([]);
    }
  });

  it('renders every account something, and every rendered link resolves', () => {
    const records = new Set(router.getRoutes().map((r) => String(r.name)));
    for (const identity of ROLE_IDENTITIES) {
      const nodes = flattenNavigation(navigationTree(identity, allPermissions));
      expect(nodes.length, identity).toBeGreaterThan(0);
      for (const node of nodes) {
        if (node.routeName === null) {
          expect(node.disabled, `${node.key} has no route so it must be disabled`).toBe(true);
          continue;
        }
        expect(records.has(node.routeName), `${node.key} → ${node.routeName}`).toBe(true);
        expect(node.routeName, `${node.key} must not resolve to the catch-all`).not.toBe('not-found');
      }
    }
  });
});
