import { describe, expect, it } from 'vitest';
import { resolveCtas } from './ctaResolver';
import type { CtaSpec } from './landingContract';

/**
 * Phase UI-06 — the CTA resolver's NEGATIVE behaviour.
 *
 * The positive path is covered by `landingComposition.spec.ts`, which resolves all eight accounts'
 * real CTAs. What matters here is what the resolver refuses, because each refusal corresponds to a
 * way a landing page could contradict the security model — and six of the eight approved sources
 * ask for exactly the first one.
 */

const ROUTES: Record<string, string> = {
  'auth.login': '/auth/login',
  'auth.register': '/auth/register',
  'staff.accept': '/staff/accept',
};

const resolve = (name: string): string | null => ROUTES[name] ?? null;
const RENDERED = new Set(['hero', 'how_it_works']);

function cta(overrides: Partial<CtaSpec> = {}): CtaSpec {
  return {
    key: 'test',
    label: 'Get started',
    kind: 'sign_in',
    emphasis: 'primary',
    routeName: 'auth.login',
    eligibilityReason: 'sign-in is available on every host',
    sourceSection: '§1',
    ...overrides,
  };
}

describe('resolveCtas', () => {
  it('rejects merchant self-registration on an account the registry marks invitation-only', () => {
    const { resolved, rejected } = resolveCtas(
      [cta({ kind: 'self_registration', routeName: 'auth.register' })],
      { selfRegistration: false, invitationAcceptance: true },
      resolve,
      RENDERED,
    );

    expect(resolved).toEqual([]);
    expect(rejected[0].reason).toContain('selfRegistration:false');
  });

  it('allows merchant self-registration where the registry permits it', () => {
    const { resolved, rejected } = resolveCtas(
      [cta({ kind: 'self_registration', routeName: 'auth.register' })],
      { selfRegistration: true, invitationAcceptance: false },
      resolve,
      RENDERED,
    );

    expect(rejected).toEqual([]);
    expect(resolved[0].href).toBe('/auth/register');
  });

  it('rejects invitation acceptance where the registry forbids it', () => {
    const { resolved, rejected } = resolveCtas(
      [cta({ kind: 'invitation_acceptance', routeName: 'staff.accept' })],
      { selfRegistration: false, invitationAcceptance: false },
      resolve,
      RENDERED,
    );

    expect(resolved).toEqual([]);
    expect(rejected[0].reason).toContain('invitationAcceptance:false');
  });

  it('rejects a CTA whose route does not exist', () => {
    // A dead CTA is worse than a missing one: it looks like a way in and is not.
    const { rejected } = resolveCtas(
      [cta({ routeName: 'auth.not-a-route' })],
      { selfRegistration: true, invitationAcceptance: true },
      resolve,
      RENDERED,
    );

    expect(rejected[0].reason).toContain('does not exist');
  });

  it('rejects a route that resolves off-host', () => {
    const { rejected } = resolveCtas(
      [cta({ routeName: 'external' })],
      { selfRegistration: true, invitationAcceptance: true },
      (name) => (name === 'external' ? 'https://elsewhere.example/login' : null),
      RENDERED,
    );

    expect(rejected[0].reason).toContain('off-host');
  });

  it('rejects a protocol-relative destination, which would also leave the host', () => {
    const { rejected } = resolveCtas(
      [cta({ routeName: 'external' })],
      { selfRegistration: true, invitationAcceptance: true },
      (name) => (name === 'external' ? '//elsewhere.example/login' : null),
      RENDERED,
    );

    expect(rejected[0].reason).toContain('off-host');
  });

  it('rejects an anchor pointing at a region the page does not render', () => {
    const { rejected } = resolveCtas(
      [cta({ kind: 'in_page_anchor', routeName: undefined, anchorRegion: 'pricing' })],
      { selfRegistration: true, invitationAcceptance: true },
      resolve,
      new Set(['hero']),
    );

    expect(rejected[0].reason).toContain('would be dead');
  });

  it('builds an anchor from the region id when the region renders', () => {
    const { resolved } = resolveCtas(
      [cta({ kind: 'in_page_anchor', routeName: undefined, anchorRegion: 'how_it_works' })],
      { selfRegistration: true, invitationAcceptance: true },
      resolve,
      RENDERED,
    );

    expect(resolved[0].href).toBe('#section-how-it-works');
    expect(resolved[0].routeName).toBeNull();
  });

  it('rejects a navigation CTA that names no route and an anchor that names no region', () => {
    const { rejected } = resolveCtas(
      [
        cta({ key: 'no-route', routeName: undefined }),
        cta({ key: 'no-region', kind: 'in_page_anchor', routeName: undefined }),
      ],
      { selfRegistration: true, invitationAcceptance: true },
      resolve,
      RENDERED,
    );

    expect(rejected.map((entry) => entry.key)).toEqual(['no-route', 'no-region']);
  });

  it('carries the eligibility reason and source through to the resolved CTA', () => {
    // The CTA matrix is built from these, so a resolution that dropped them would leave the audit
    // artifact unable to say why an action is offered.
    const { resolved } = resolveCtas(
      [cta({ eligibilityReason: 'because the registry says so', sourceSection: '§2 Hero' })],
      { selfRegistration: false, invitationAcceptance: false },
      resolve,
      RENDERED,
    );

    expect(resolved[0].eligibilityReason).toBe('because the registry says so');
    expect(resolved[0].sourceSection).toBe('§2 Hero');
  });
});
