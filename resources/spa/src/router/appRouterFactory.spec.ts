import { describe, expect, it } from 'vitest';
import { createAppRouter } from '@/router';

/**
 * Increment 7B — the router is built per account host.
 *
 * Why this exists: `/audit`, `/dashboard`, `/account` and `/reports` are contract routes for MORE
 * THAN ONE account. A single router carrying all eight trees cannot register both claimants, which
 * is exactly why the Super Administrator's canonical paths stayed unregistered through 7A. Each
 * account is served on its own host, so the collision was an artefact of the build, not of the
 * contract — and the factory removes it.
 *
 * What must NOT follow from that: the host deciding anything about authority. Scoping changes which
 * routes are REGISTERED. It never changes a guard, and the server re-checks everything regardless
 * (ADR-017).
 */

const ACCOUNTS = [
  'super_administrator',
  'merchant_administrator',
  'merchant_branch',
  'merchant_human_resource',
  'merchant_finance',
  'merchant_front_office',
  'merchant_personnel',
  'merchant_audit',
] as const;

/** Routes present on every host regardless of account: public, auth, search, denial, not-found. */
const SHARED_ROUTE_NAMES = ['auth.login', 'search', 'access-denied', 'not-found'];

function accountKeysIn(router: ReturnType<typeof createAppRouter>): Set<string> {
  const keys = new Set<string>();
  for (const record of router.getRoutes()) {
    const key = record.meta?.accountKey;
    if (typeof key === 'string') keys.add(key);
  }
  return keys;
}

describe('createAppRouter — one account tree per host (Increment 7B)', () => {
  /**
   * A host registers its own tree plus any tree it CO-OWNS — and never one it does not own.
   *
   * Plan §10.2 gives the Merchant Administrator branch creation and branch record screens, so the
   * `/branch` tree declares two owners. The historical Merchant Administrator HR-invitation URL is
   * registered as one narrow supporting route; the canonical HR account tree remains HR-only.
   */
  const CO_OWNED: Readonly<Record<string, string[]>> = {
    merchant_administrator: ['merchant_administrator', 'merchant_branch'],
  };

  it('registers the account tree plus any tree the account co-owns, and nothing else', () => {
    for (const account of ACCOUNTS) {
      const expected = new Set(CO_OWNED[account] ?? [account]);
      expect(accountKeysIn(createAppRouter(account)), account).toEqual(expected);
    }
  });

  it('never registers a tree the account does not own', () => {
    // The Merchant Audit host must not carry Finance, Front Office, Personnel or the platform.
    const auditHost = accountKeysIn(createAppRouter('merchant_audit'));
    for (const foreign of ['super_administrator', 'merchant_finance', 'merchant_front_office', 'merchant_personnel', 'merchant_administrator']) {
      expect(auditHost.has(foreign), `merchant_audit must not register ${foreign}`).toBe(false);
    }
  });

  it('registers every account tree when no account is given, for the static contracts', () => {
    expect(accountKeysIn(createAppRouter(null))).toEqual(new Set(ACCOUNTS));
  });

  it('serves the shared public, auth and utility routes on every host', () => {
    for (const account of [...ACCOUNTS, null]) {
      const names = new Set(createAppRouter(account).getRoutes().map((r) => String(r.name)));
      for (const shared of SHARED_ROUTE_NAMES) {
        expect(names.has(shared), `${account ?? 'all-accounts'} is missing ${shared}`).toBe(true);
      }
    }
  });

  it('gives an unrecognised host the public and auth surface only, never another account tree', () => {
    const router = createAppRouter('not-a-servana-account');
    expect(accountKeysIn(router).size).toBe(0);
    expect(new Set(router.getRoutes().map((r) => String(r.name))).has('auth.login')).toBe(true);
  });

  it('mints an independent router each call, so one host cannot mutate another', () => {
    const a = createAppRouter('super_administrator');
    const b = createAppRouter('super_administrator');
    expect(a).not.toBe(b);
    a.addRoute({ path: '/only-on-a', name: 'only-on-a', component: { template: '<div />' } });
    expect(a.hasRoute('only-on-a')).toBe(true);
    expect(b.hasRoute('only-on-a')).toBe(false);
  });

  it('keeps each account route guarded identically however the router was built', () => {
    // Scoping decides REGISTRATION. It must not quietly drop the account guard that a route
    // carries, which would turn "served on this host" into "permitted on this host".
    const scoped = createAppRouter('super_administrator').getRoutes().filter((r) => r.meta?.accountKey === 'super_administrator');
    const all = createAppRouter(null).getRoutes().filter((r) => r.meta?.accountKey === 'super_administrator');

    expect(scoped.length).toBe(all.length);
    for (const record of [...scoped, ...all]) {
      // The account tree's root carries the guards; children inherit them by nesting.
      expect(record.meta?.accountKey).toBe('super_administrator');
    }
  });

  it('never lets the catch-all answer for a route another account owns', () => {
    // On the Merchant Audit host, a Super Administrator path is NOT FOUND — it is not silently
    // rendered by some other account's page.
    const auditHost = createAppRouter('merchant_audit');
    expect(String(auditHost.resolve('/platform/billing-settings').name)).toBe('not-found');
  });
});
