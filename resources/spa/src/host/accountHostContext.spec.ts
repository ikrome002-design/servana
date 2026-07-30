import { beforeEach, describe, expect, it } from 'vitest';

import {
  ACCOUNT_HOSTS,
  ACCOUNT_KEYS,
  ALL_ACCOUNT_HOSTS,
  accountKeyForHost,
} from '@/host/accountHosts.generated';
import {
  ACCOUNT_CONTEXT_ELEMENT_ID,
  accountContextResult,
  currentAccountContext,
  initAccountContext,
  resetAccountContext,
  resolveAccountContext,
} from '@/host/accountHostContext';
import { ROLE_IDENTITIES } from '@/types/roles';

/**
 * Phase UI-02 — frontend account-host context (ADR-016, ADR-017).
 *
 * The browser must never decide its own account identity. These tests pin two things: the
 * generated registry stays in parity with the canonical role identities, and the context
 * resolver FAILS CLOSED on anything it cannot fully trust.
 */

function contextPayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    account_key: 'merchant_finance',
    display_name: 'Finance',
    host: 'finance.servana.test',
    environment: 'local',
    ...overrides,
  };
}

function documentWith(json: string | null): Document {
  const doc = document.implementation.createHTMLDocument('test');
  if (json !== null) {
    const script = doc.createElement('script');
    script.id = ACCOUNT_CONTEXT_ELEMENT_ID;
    script.type = 'application/json';
    script.textContent = json;
    doc.body.appendChild(script);
  }

  return doc;
}

describe('generated account-host registry', () => {
  it('contains exactly the eight canonical accounts', () => {
    expect(ACCOUNT_KEYS).toHaveLength(8);
    expect([...ACCOUNT_KEYS].sort()).toEqual([...ROLE_IDENTITIES].sort());
  });

  it('gives every account a production, staging and local host', () => {
    for (const key of ACCOUNT_KEYS) {
      const { hosts } = ACCOUNT_HOSTS[key];
      expect(hosts.production).toMatch(/\.?servana\.ke$/);
      expect(hosts.local).toMatch(/\.?servana\.test$/);
      expect(hosts.staging).not.toBe('');
      // No two environments may collapse onto the same hostname.
      expect(new Set([hosts.production, hosts.staging, hosts.local]).size).toBe(3);
    }
  });

  it('maps every host to exactly one account', () => {
    expect(ALL_ACCOUNT_HOSTS).toHaveLength(24);
    expect(new Set(ALL_ACCOUNT_HOSTS).size).toBe(24);

    for (const key of ACCOUNT_KEYS) {
      for (const host of Object.values(ACCOUNT_HOSTS[key].hosts)) {
        expect(accountKeyForHost(host)).toBe(key);
      }
    }
  });

  it('gives only the Super Administrator header navigation', () => {
    for (const key of ACCOUNT_KEYS) {
      expect(ACCOUNT_HOSTS[key].navigationPlacement).toBe(
        key === 'super_administrator' ? 'header' : 'sidebar',
      );
    }
  });

  it('normalises case and port, and refuses anything unrecognised', () => {
    expect(accountKeyForHost('FINANCE.SERVANA.TEST')).toBe('merchant_finance');
    expect(accountKeyForHost('finance.servana.test:8080')).toBe('merchant_finance');

    for (const host of ['', '   ', 'evil.test', 'evil-servana.ke', 'servana.ke.attacker.test']) {
      expect(accountKeyForHost(host)).toBeNull();
    }
  });
});

describe('account context resolution', () => {
  beforeEach(() => resetAccountContext());

  it('accepts a consistent server context', () => {
    const result = resolveAccountContext(contextPayload(), 'finance.servana.test');

    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.context.accountKey).toBe('merchant_finance');
      expect(result.context.displayName).toBe('Finance');
      expect(result.context.navigationPlacement).toBe('sidebar');
      // Registry-derived metadata is filled from the generated registry, not from the
      // payload, so a tampered payload cannot rewrite it.
      expect(result.context.routeNamePrefix).toBe(ACCOUNT_HOSTS.merchant_finance.routeNamePrefix);
    }
  });

  it('resolves every account for its own host', () => {
    for (const key of ACCOUNT_KEYS) {
      const definition = ACCOUNT_HOSTS[key];
      const result = resolveAccountContext(
        contextPayload({
          account_key: key,
          display_name: definition.displayName,
          host: definition.hosts.local,
        }),
        definition.hosts.local,
      );

      expect(result.ok, `${key} must resolve on its own host`).toBe(true);
    }
  });

  it('fails closed when the context is missing', () => {
    expect(resolveAccountContext(null, 'servana.test')).toEqual({ ok: false, failure: 'missing' });
  });

  it('fails closed on a malformed context', () => {
    for (const raw of [
      'not-an-object',
      {},
      contextPayload({ account_key: '' }),
      contextPayload({ account_key: 42 }),
      contextPayload({ environment: 'wonderland' }),
      contextPayload({ display_name: null }),
    ]) {
      const result = resolveAccountContext(raw, 'servana.test');
      expect(result.ok, `must reject ${JSON.stringify(raw)}`).toBe(false);
    }
  });

  it('fails closed on an account key the frontend does not know', () => {
    // A key the backend knows but the frontend does not means the registries drifted.
    // Guessing would render one account's experience under another account's host.
    expect(resolveAccountContext(contextPayload({ account_key: 'merchant_ops' }), null)).toEqual({
      ok: false,
      failure: 'unknown_account',
    });
  });

  it('fails closed when the server context and the address bar disagree', () => {
    const result = resolveAccountContext(
      contextPayload({ account_key: 'merchant_personnel', display_name: 'Personnel' }),
      'citrus.servana.test',
    );

    expect(result).toEqual({ ok: false, failure: 'host_mismatch' });
  });

  it('trusts the server on a hostname it does not recognise', () => {
    // A custom or proxied hostname is not evidence of a mismatch — only a hostname that
    // maps to a DIFFERENT known account is. The server stays the authority.
    const result = resolveAccountContext(contextPayload(), 'internal-proxy.example');

    expect(result.ok).toBe(true);
  });

  it('never infers an account from the address bar alone', () => {
    // No context block, correct hostname: still a failure. The browser does not get to
    // promote itself into an account experience.
    const result = resolveAccountContext(null, 'citrus.servana.test');

    expect(result).toEqual({ ok: false, failure: 'missing' });
  });
});

describe('account context bootstrap', () => {
  beforeEach(() => resetAccountContext());

  it('reads and memoises the embedded context', () => {
    const result = initAccountContext(documentWith(JSON.stringify(contextPayload())), 'finance.servana.test');

    expect(result.ok).toBe(true);
    expect(currentAccountContext()?.accountKey).toBe('merchant_finance');
    expect(accountContextResult()).toBe(result);
  });

  it('reports an unparseable block as malformed, not missing', () => {
    const result = initAccountContext(documentWith('{ not json'), 'servana.test');

    expect(result).toEqual({ ok: false, failure: 'malformed' });
  });

  it('reports an absent block as missing', () => {
    expect(initAccountContext(documentWith(null), 'servana.test')).toEqual({
      ok: false,
      failure: 'missing',
    });
  });

  it('returns a missing failure before boot rather than throwing', () => {
    expect(accountContextResult()).toEqual({ ok: false, failure: 'missing' });
    expect(currentAccountContext()).toBeNull();
  });
});
