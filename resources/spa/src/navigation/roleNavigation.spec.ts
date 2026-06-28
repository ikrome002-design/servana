import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { ROLE_NAVIGATION, navigationFor } from './roleNavigation';
import { serializeRoleNavigation } from './navigationFixture';
import { ROLE_IDENTITIES } from '@/types/roles';

// Resolve the fixture relative to this module's directory (repo root is four up).
const FIXTURE_PATH = resolve(
  import.meta.dirname,
  '../../../../docs/frontend/navigation/role-navigation.yaml',
);

describe('role navigation registry', () => {
  it('matches the version-controlled YAML fixture exactly', async () => {
    // The registry is the source of truth; the YAML fixture is generated from it.
    // Any drift in labels/ordering/availability fails here (run with -u to sync).
    await expect(serializeRoleNavigation()).toMatchFileSnapshot(FIXTURE_PATH);
  });

  it('covers all eight role identities', () => {
    for (const identity of ROLE_IDENTITIES) {
      expect(navigationFor(identity).length).toBeGreaterThan(0);
    }
  });

  it('uses unique nav keys within each role', () => {
    for (const identity of ROLE_IDENTITIES) {
      const keys = navigationFor(identity).map((i) => i.key);
      expect(new Set(keys).size).toBe(keys.length);
    }
  });

  it('live items point to a route and planned items never do (no dead links)', () => {
    for (const identity of ROLE_IDENTITIES) {
      for (const item of navigationFor(identity)) {
        if (item.availability === 'live') {
          expect(item.routeName, `${item.key} is live`).toBeTruthy();
        } else {
          expect(item.routeName, `${item.key} is planned`).toBeUndefined();
          expect(item.phase, `${item.key} planned phase`).toBeTruthy();
        }
      }
    }
  });

  it('never exposes forbidden role capabilities (Plan §2.1, §19.4; Scope §4.x)', () => {
    const allText = ROLE_IDENTITIES.flatMap((id) =>
      navigationFor(id).map((i) => `${i.key} ${i.label}`.toLowerCase()),
    ).join(' | ');

    // Super Administrator: no merchant creation/operations.
    expect(allText).not.toContain('create merchant');
    expect(allText).not.toContain('new merchant');
    // Personnel: contact export is permanently prohibited.
    expect(allText).not.toContain('contact export');
    expect(allText).not.toContain('export contact');
  });

  it('does not give the Super Administrator any merchant-create item', () => {
    const sa = navigationFor('super_administrator');
    expect(sa.some((i) => /merchant.*create|create.*merchant/i.test(i.key))).toBe(false);
  });

  it('does not give Personnel any contact-export item', () => {
    const personnel = navigationFor('merchant_personnel');
    expect(personnel.some((i) => /contact|export/i.test(i.key))).toBe(false);
  });

  it('keeps audit navigation read-only (no mutating verbs)', () => {
    const audit = navigationFor('merchant_audit');
    expect(audit.some((i) => /create|update|delete|validate|approve|refund/i.test(i.key))).toBe(
      false,
    );
  });

  it('exposes a registry entry for every identity', () => {
    expect(Object.keys(ROLE_NAVIGATION).sort()).toEqual([...ROLE_IDENTITIES].sort());
  });
});
