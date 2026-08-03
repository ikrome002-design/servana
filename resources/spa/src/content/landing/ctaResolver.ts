/**
 * Public call-to-action resolver (Phase UI-06; UI/UX plan §8.6, §18.5; ADR-017; binding §2.5).
 *
 * A landing page's calls to action are the one place where marketing copy can quietly contradict
 * the security model. Six of the eight accounts have source copy whose CTA opens "login and create
 * account" — but the account-host registry marks those six `selfRegistration: false`. Publishing
 * the copy's intention would put an open merchant-registration action on an invitation-only host.
 *
 * So a CTA is never taken at face value. Each declares a KIND, and this resolver checks that kind
 * against two authorities that already exist:
 *
 *  1. the account-host registry (`config/account-hosts.json` → `accountHosts.generated.ts`) for
 *     whether this account may self-register or accept an invitation;
 *  2. the live router for whether the named route exists at all.
 *
 * A CTA that fails either check is REJECTED, not silently hidden — the caller surfaces the rejection
 * and the contract tests fail on it, because a CTA that quietly disappears in production is how a
 * page ends up with no way in.
 *
 * The resolver produces same-host, path-only destinations. It never reads a hostname from the
 * request, and resolving a host grants nothing (ADR-017): every protected request is still
 * authorized against the database.
 */

import type { AccountHostDefinition } from '@/host/accountHosts.generated';
import type { CtaSpec } from '@/content/landing/landingContract';
import { regionAnchorId } from '@/content/landing/landingContract';

export interface ResolvedCta {
  readonly key: string;
  readonly label: string;
  readonly kind: CtaSpec['kind'];
  readonly emphasis: CtaSpec['emphasis'];
  /** A same-host path (`/auth/login`) or a page fragment (`#section-how-it-works`). Never absolute. */
  readonly href: string;
  /** Route name for an internal navigation CTA; null for an anchor. */
  readonly routeName: string | null;
  readonly eligibilityReason: string;
  readonly sourceSection: string;
}

export interface RejectedCta {
  readonly key: string;
  readonly reason: string;
}

export interface CtaResolution {
  readonly resolved: readonly ResolvedCta[];
  readonly rejected: readonly RejectedCta[];
}

/** What the resolver needs from the router. Passed in so this module stays pure and testable. */
export interface RouteResolver {
  /** The path a route name resolves to, or null when the route does not exist. */
  (routeName: string): string | null;
}

/** The registry fields that decide eligibility. Nothing here is an authorization input. */
export interface CtaAccountCapabilities {
  readonly selfRegistration: AccountHostDefinition['selfRegistration'];
  readonly invitationAcceptance: AccountHostDefinition['invitationAcceptance'];
}

function eligibilityFailure(spec: CtaSpec, capabilities: CtaAccountCapabilities): string | null {
  switch (spec.kind) {
    case 'self_registration':
      return capabilities.selfRegistration
        ? null
        : 'the account-host registry marks this account selfRegistration:false, so it may not expose merchant self-registration';
    case 'invitation_acceptance':
      return capabilities.invitationAcceptance
        ? null
        : 'the account-host registry marks this account invitationAcceptance:false';
    case 'sign_in':
    case 'in_page_anchor':
      return null;
  }
}

/**
 * Resolve an account's declared CTAs against the registry and the live route table.
 *
 * `renderedRegions` is the set of regions the page actually renders, so an anchor CTA can never
 * point at a section that was withheld.
 */
export function resolveCtas(
  specs: readonly CtaSpec[],
  capabilities: CtaAccountCapabilities,
  resolveRoute: RouteResolver,
  renderedRegions: ReadonlySet<string>,
): CtaResolution {
  const resolved: ResolvedCta[] = [];
  const rejected: RejectedCta[] = [];

  for (const spec of specs) {
    const failure = eligibilityFailure(spec, capabilities);
    if (failure !== null) {
      rejected.push({ key: spec.key, reason: failure });
      continue;
    }

    if (spec.kind === 'in_page_anchor') {
      if (spec.anchorRegion === undefined) {
        rejected.push({ key: spec.key, reason: 'an in-page CTA must name the region it scrolls to' });
        continue;
      }
      if (!renderedRegions.has(spec.anchorRegion)) {
        rejected.push({
          key: spec.key,
          reason: `region ${spec.anchorRegion} does not render on this page, so the anchor would be dead`,
        });
        continue;
      }

      resolved.push({
        key: spec.key,
        label: spec.label,
        kind: spec.kind,
        emphasis: spec.emphasis,
        href: `#${regionAnchorId(spec.anchorRegion)}`,
        routeName: null,
        eligibilityReason: spec.eligibilityReason,
        sourceSection: spec.sourceSection,
      });
      continue;
    }

    if (spec.routeName === undefined) {
      rejected.push({ key: spec.key, reason: 'a navigation CTA must name a route' });
      continue;
    }

    const path = resolveRoute(spec.routeName);
    if (path === null) {
      rejected.push({ key: spec.key, reason: `route ${spec.routeName} does not exist` });
      continue;
    }

    // A public CTA must stay on the host the visitor is already on. A path that is not root-relative
    // — an absolute URL, a protocol-relative `//host`, anything with a scheme — would leave it.
    if (!path.startsWith('/') || path.startsWith('//')) {
      rejected.push({ key: spec.key, reason: `route ${spec.routeName} resolved off-host to ${path}` });
      continue;
    }

    resolved.push({
      key: spec.key,
      label: spec.label,
      kind: spec.kind,
      emphasis: spec.emphasis,
      href: path,
      routeName: spec.routeName,
      eligibilityReason: spec.eligibilityReason,
      sourceSection: spec.sourceSection,
    });
  }

  return { resolved, rejected };
}
