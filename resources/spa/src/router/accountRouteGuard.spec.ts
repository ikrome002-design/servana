import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { requiresAccount } from '@/router/guards';
import { initAccountContext, resetAccountContext } from '@/host/accountHostContext';
import { useAuthStore } from '@/stores/authStore';
import type { BootstrapPayload } from '@/types/models';

/**
 * Phase UI-03 — account-entry guard, and the `UI01-ROLE-001` regression it closes.
 *
 * UI-01 proved that all seven merchant-side fixtures could request `/platform` and render the
 * Super Administrator landing, because the tree carried `requiresAuth` alone. These tests assert
 * the NEGATIVE for every one of those accounts, and assert that the denial is a denial — never a
 * redirect to another account.
 */

/** A `/me` payload for a user the SERVER says holds exactly these accounts. */
function bootstrapWith(accountKeys: string[], isPlatformStaff = false): BootstrapPayload {
  return {
    user: {
      id: 'u1',
      email: 'a@b.co',
      name: 'A',
      status: 'active',
      email_verified_at: null,
      is_platform_staff: isPlatformStaff,
    },
    merchant: null,
    membership: null,
    memberships: [],
    permissions: [],
    account_keys: accountKeys,
    setup: { required: false, current_step: null, completed_at: null },
    branch_ids: [],
    mfa: {
      required: false,
      enrolled: false,
      confirmed: false,
      verified: false,
      enrollment_required: false,
      challenge_required: false,
      step_up_fresh: false,
      step_up_fresh_until: null,
      recovery_codes_remaining: 0,
    },
  } as BootstrapPayload;
}

/** Render the server's host-context block for one account host. */
function serveHostContext(accountKey: string, host: string): void {
  resetAccountContext();

  const doc = document.implementation.createHTMLDocument('t');
  const script = doc.createElement('script');
  script.id = 'servana-account-context';
  script.type = 'application/json';
  script.textContent = JSON.stringify({
    account_key: accountKey,
    display_name: accountKey,
    host,
    environment: 'testing',
  });
  doc.body.appendChild(script);

  initAccountContext(doc, host);
}

const MERCHANT_ACCOUNTS = [
  'merchant_administrator',
  'merchant_branch',
  'merchant_human_resource',
  'merchant_finance',
  'merchant_front_office',
  'merchant_personnel',
  'merchant_audit',
];

describe('account-entry guard (UI01-ROLE-001)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    resetAccountContext();
  });

  it.each(MERCHANT_ACCOUNTS)(
    'denies %s the Super Administrator surface even on the platform host',
    (accountKey) => {
      const auth = useAuthStore();
      auth.applyBootstrap(bootstrapWith([accountKey]));

      // The strongest form of the original defect: the user is ON the platform host.
      serveHostContext('super_administrator', 'citrus.servana.test');

      const next = vi.fn();
      requiresAccount('super_administrator')({} as never, {} as never, next);

      expect(next).toHaveBeenCalledWith({ name: 'access-denied' });
      // Never a redirect to another account — not a broader one, and not their own.
      expect(next).not.toHaveBeenCalledWith({ name: 'home' });
      expect(next).not.toHaveBeenCalledWith();
    },
  );

  it('admits a genuine platform user on the platform host', () => {
    const auth = useAuthStore();
    auth.applyBootstrap(bootstrapWith(['super_administrator'], true));
    serveHostContext('super_administrator', 'citrus.servana.test');

    const next = vi.fn();
    requiresAccount('super_administrator')({} as never, {} as never, next);

    expect(next).toHaveBeenCalledWith();
  });

  it('denies a platform user the platform surface on a merchant host', () => {
    // The host must match the ROUTE's account too: a platform user on `finance.servana.test`
    // is asking for the wrong experience, and answering it would break the one-host-one-account
    // contract even though the user is authorized.
    const auth = useAuthStore();
    auth.applyBootstrap(bootstrapWith(['super_administrator'], true));
    serveHostContext('merchant_finance', 'finance.servana.test');

    const next = vi.fn();
    requiresAccount('super_administrator')({} as never, {} as never, next);

    expect(next).toHaveBeenCalledWith({ name: 'access-denied' });
  });

  it('fails closed when the server established no host context at all', () => {
    const auth = useAuthStore();
    auth.applyBootstrap(bootstrapWith(['super_administrator'], true));
    resetAccountContext(); // e.g. served outside the Laravel shell

    const next = vi.fn();
    requiresAccount('super_administrator')({} as never, {} as never, next);

    expect(next).toHaveBeenCalledWith({ name: 'access-denied' });
  });

  it('fails closed when the payload carries no account keys', () => {
    // A cached older shell must deny, not admit.
    const auth = useAuthStore();
    const payload = bootstrapWith([], true);
    delete (payload as { account_keys?: string[] }).account_keys;
    auth.applyBootstrap(payload);
    serveHostContext('super_administrator', 'citrus.servana.test');

    const next = vi.fn();
    requiresAccount('super_administrator')({} as never, {} as never, next);

    expect(next).toHaveBeenCalledWith({ name: 'access-denied' });
  });

  it('sends an anonymous visitor to sign in rather than to the denial state', () => {
    serveHostContext('super_administrator', 'citrus.servana.test');

    const next = vi.fn();
    requiresAccount('super_administrator')({} as never, {} as never, next);

    expect(next).toHaveBeenCalledWith({ name: 'auth.login' });
  });

  it('computes no permissions and no role mapping in the client', () => {
    // The guard's only inputs are the server's host context and the server's account list.
    const source = requiresAccount.toString();

    expect(source).not.toMatch(/merchant_admin['"]|branch_manager|front_office/);
    expect(source).not.toMatch(/permission/i);
  });
});
