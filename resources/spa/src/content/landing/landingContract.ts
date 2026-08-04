/**
 * Public landing-page composition contract (Phase UI-06; UI/UX plan §8.1–§8.6, §15; ADR-016/017).
 *
 * One shared architecture, eight genuinely different pages. This module owns the TYPES and the
 * rules; each account owns its own composition under `accounts/`, loaded on demand so a visitor to
 * one host never downloads another account's page.
 *
 * Three things this contract exists to make impossible:
 *
 *  - **A generic page with the role name swapped.** §8.1 forbids it explicitly. Every account
 *    supplies its own navigation vocabulary, trust evidence, plan-access treatment and call-to-action
 *    contract, and `landingComposition.spec.ts` proves each of those differs from all seven others.
 *  - **Fabricated evidence.** `TrustEvidenceItem` cannot be constructed without naming a source and
 *    declaring `customerClaim: false` / `metricClaim: false`, and `PlanAccessBlock` cannot carry an
 *    amount at all. The types are the guard-rail, not a review convention.
 *  - **A call to action that contradicts the security model.** A CTA declares its KIND; the resolver
 *    checks that kind against the account-host registry and the live route table, so an
 *    invitation-only account cannot expose merchant self-registration even if its source copy asks
 *    for it (§2.5 — the current security boundary wins and the conflict is recorded).
 */

import type { ContentAccountKey, LandingRegion } from '@/content/generated/contentTypes.generated';

/** The sixteen semantic regions, in UI/UX plan §8.3 order. Never reordered per account. */
export const LANDING_REGION_ORDER: readonly LandingRegion[] = Object.freeze([
  'header_navigation',
  'hero',
  'social_proof',
  'problem',
  'solution',
  'features',
  'how_it_works',
  'benefits',
  'product_showcase',
  'use_cases',
  'testimonials',
  'pricing',
  'security',
  'faq',
  'final_cta',
  'footer',
]);

/**
 * The regions rendered as page SECTIONS between the header and the fixed footer.
 *
 * `header_navigation` and `footer` are regions 1 and 16 and are absolutely present — as the real
 * header and the real `SvFixedFooter`, not as prose. Rendering their compiled markdown as body copy
 * would print `**Logo:** Servana` and a duplicate link list into the page.
 */
export const LANDING_BODY_REGIONS: readonly LandingRegion[] = Object.freeze(
  LANDING_REGION_ORDER.filter((region) => region !== 'header_navigation' && region !== 'footer'),
);

/** The DOM id each region's section carries. Stable, unique, and safe in a URL fragment. */
export function regionAnchorId(region: LandingRegion): string {
  return `section-${region.replace(/_/g, '-')}`;
}

// ---------------------------------------------------------------------------------------------
// Trust evidence — the approved factual alternative to customer testimonials
// ---------------------------------------------------------------------------------------------

/**
 * What KIND of fact an evidence item is. The vocabulary is closed and contains no category that
 * could carry a customer claim: there is deliberately no `testimonial`, `rating`, `adoption` or
 * `outcome` member, so an unverified quote cannot be expressed in this type at all.
 */
export type TrustEvidenceType =
  | 'source_backed_capability'
  | 'security_control'
  | 'role_boundary'
  | 'operational_workflow'
  | 'policy_commitment'
  | 'factual_account_purpose';

export interface TrustEvidenceItem {
  /** Short factual label. */
  readonly title: string;
  /** One factual sentence. No metric, no customer, no organisation. */
  readonly detail: string;
  readonly evidenceType: TrustEvidenceType;
  /** A repository path, or the identifier of a behaviour a test proves. Never a person. */
  readonly source: string;
  /** The exact section, heading or test name within `source`. */
  readonly sourceReference: string;
  /** Structural: an item that made a customer claim could not set this. */
  readonly customerClaim: false;
  /** Structural: no adoption figure, rating, count or improvement percentage. */
  readonly metricClaim: false;
}

export interface TrustEvidenceBlock {
  /** Role-appropriate heading. Derived from the account's own source where one applies. */
  readonly heading: string;
  /**
   * `compiled_source_section` renders the account's own already-factual source section verbatim
   * above the items — Human Resource's "Trust Statement Section" is exactly that, and the binding
   * decision requires preserving it. `approved_factual_alternative` renders the items alone.
   */
  readonly mode: 'compiled_source_section' | 'approved_factual_alternative';
  /** One factual framing sentence. Never a claim about customers. */
  readonly intro: string;
  readonly items: readonly TrustEvidenceItem[];
}

// ---------------------------------------------------------------------------------------------
// Pricing and plan access
// ---------------------------------------------------------------------------------------------

export type PlanAccessMode =
  /** The one account that buys a Servana subscription. */
  | 'merchant_subscription_plan_access'
  /** An invitation-based account: access comes from the merchant, never from a purchase. */
  | 'invitation_account_plan_access'
  /** The platform operator's own account: it administers plan availability, it does not buy. */
  | 'platform_plan_administration';

export interface WithheldPlanContent {
  readonly what: string;
  readonly reason: string;
}

export interface PlanAccessBlock {
  readonly heading: string;
  readonly mode: PlanAccessMode;
  /**
   * Render the account's compiled `pricing` region verbatim. True only when that region is present,
   * renderable and states no amount.
   */
  readonly renderCompiledSource: boolean;
  /** Approved factual statements, rendered as the block body. Never a price. */
  readonly points: readonly string[];
  /** Source content deliberately not published, with the reason. Never silently omitted. */
  readonly withheld: readonly WithheldPlanContent[];
  /** Structural: a public landing page states no amount it cannot prove (§2.4). */
  readonly showsAmount: false;
  /** Structural: no purchase action is offered from a public page (§19.4). */
  readonly purchaseCta: false;
}

// ---------------------------------------------------------------------------------------------
// Calls to action
// ---------------------------------------------------------------------------------------------

export type CtaKind =
  /** Merchant self-registration. Permitted only where the registry says `selfRegistration`. */
  | 'self_registration'
  /** Magic Link sign-in. Available to every account, always on the current host. */
  | 'sign_in'
  /** Invitation acceptance. Permitted only where the registry says `invitationAcceptance`. */
  | 'invitation_acceptance'
  /** A same-page anchor. Grants nothing and leaves no host. */
  | 'in_page_anchor';

export interface CtaSpec {
  readonly key: string;
  /** Sentence case, per the Brand Identity "Buttons" rule. */
  readonly label: string;
  readonly kind: CtaKind;
  readonly emphasis: 'primary' | 'secondary';
  /** Required for every kind except `in_page_anchor`. Must be a live route name. */
  readonly routeName?: string;
  /** Required for `in_page_anchor`. Must be a region that actually renders. */
  readonly anchorRegion?: LandingRegion;
  /** Why this account may offer this action. Recorded in the CTA matrix. */
  readonly eligibilityReason: string;
  /** The compiled source section this CTA comes from, or the decision that supersedes it. */
  readonly sourceSection: string;
}

// ---------------------------------------------------------------------------------------------
// The composition
// ---------------------------------------------------------------------------------------------

export interface LandingNavItem {
  /** The account's own navigation wording, from its compiled header section. */
  readonly label: string;
  /** The region it scrolls to. Must be a region that renders, so no anchor is ever dead. */
  readonly region: LandingRegion;
}

export interface LandingComposition {
  readonly accountKey: ContentAccountKey;
  /** Browser tab title. Role-specific. */
  readonly documentTitle: string;
  /** Meta description. Factual, role-specific, no claim the source does not make. */
  readonly metaDescription: string;
  /** Eyebrow above the hero heading, naming the account this page is for. */
  readonly heroEyebrow: string;
  /** In-page navigation, in the account's own source vocabulary. */
  readonly navigation: readonly LandingNavItem[];
  readonly trust: TrustEvidenceBlock;
  readonly planAccess: PlanAccessBlock;
  readonly ctas: readonly CtaSpec[];
  /** Label for the link from the landing FAQ region to the account's full FAQ page. */
  readonly faqLinkLabel: string;
}

/** Every navigation label an account uses, lower-cased — used by the distinctness proof. */
export function navigationSignature(composition: LandingComposition): string {
  return composition.navigation.map((item) => `${item.label}>${item.region}`).join('|');
}

/** Every CTA an account exposes, as a stable string — used by the distinctness proof. */
export function ctaSignature(composition: LandingComposition): string {
  return composition.ctas
    .map((cta) => `${cta.emphasis}:${cta.kind}:${cta.label}:${cta.routeName ?? cta.anchorRegion ?? ''}`)
    .join('|');
}
