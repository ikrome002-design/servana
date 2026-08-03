import type { LandingComposition } from '@/content/landing/landingContract';

/**
 * Super Administrator public landing composition (Phase UI-06).
 *
 * The platform operator's own account. It neither self-registers nor accepts an invitation
 * (`selfRegistration: false`, `invitationAcceptance: false`, `publicCtaCategory:
 * 'platform_sign_in'`), so this page offers exactly one action: sign in.
 *
 * Its source supplies no testimonials section and no pricing section. Binding decisions §2.2 and
 * §2.3 supply the approved factual alternatives, applied below.
 */
const composition: LandingComposition = {
  accountKey: 'super_administrator',
  documentTitle: 'Servana platform administration — Citrus Labs',
  metaDescription:
    'The Servana platform account: manage merchants, platform fees, permissions, reports and audit activity from one secure dashboard.',
  heroEyebrow: 'Platform administration',

  // From the compiled "Header / Navigation" section: Platform, Features, How It Works, Security,
  // FAQ. "Platform" points at the platform-positioning section, which is region 3 on this page.
  navigation: [
    { label: 'Platform', region: 'social_proof' },
    { label: 'Features', region: 'features' },
    { label: 'How it works', region: 'how_it_works' },
    { label: 'Security', region: 'security' },
    { label: 'FAQ', region: 'faq' },
  ],

  trust: {
    heading: 'Security and accountability',
    mode: 'approved_factual_alternative',
    intro:
      'This account governs the platform. What follows is the architecture that governs it in turn — implemented controls, not customer evidence.',
    items: [
      {
        title: 'Merchant data stays separated',
        detail:
          'Every merchant is an isolated tenant. Platform-level reads cross that boundary only through explicit platform services, never through an ordinary query.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Isolation/CrossTenantAccessTest.php',
        sourceReference: 'tenant-scope enforcement and platform-context exemption',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Privileged access requires a second factor',
        detail:
          'The platform account requires MFA enrollment, and sensitive platform changes require a fresh step-up challenge.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Auth/PermissionStepUpCoverageTest.php',
        sourceReference: 'step-up coverage for platform-governed mutations',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'The audit record cannot be rewritten',
        detail:
          'Audit entries are append-only and hash-chained, and the chain is verified on a schedule rather than on request.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Audit/AuditChainVerificationTest.php',
        sourceReference: 'hash-chain verification and tamper detection',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Oversight without interference',
        detail:
          'Platform governance reviews merchant activity; it does not operate a merchant’s day. Merchants keep their own clients, queues, invoices and receipts.',
        evidenceType: 'role_boundary',
        source: 'docs/landing_page/super_administrator_landing_page_content.md',
        sourceReference: '§10 Use Cases Section — "Audit oversight"',
        customerClaim: false,
        metricClaim: false,
      },
    ],
  },

  planAccess: {
    heading: 'Plan access and administration',
    mode: 'platform_plan_administration',
    // The source supplies no pricing section at all (UI05-CONTENT-002). Binding decision §2.3
    // supplies this alternative; nothing here is invented beyond it.
    renderCompiledSource: false,
    points: [
      'This account administers platform plan availability and platform access. It configures which plans exist, what they include, and when their prices take effect.',
      'Merchant subscriptions are not purchased from this page. A merchant buys its own plan from its own Merchant Administrator account.',
      'No merchant subscription price is offered to the Super Administrator user here, and no plan amount is shown, because the live plan catalogue is the only authority for current amounts.',
      'Plan and price administration happens inside the authenticated platform billing screens, against the live catalogue.',
    ],
    withheld: [
      {
        what: 'A pricing section for this account.',
        reason:
          'The Super Administrator landing source supplies none. UI/UX plan §8.3 forbids inventing missing commercial evidence and §8.5 forbids generic tiers, so binding decision §2.3 replaces the region with plan-access-and-administration content rather than a pricing table.',
      },
    ],
    showsAmount: false,
    purchaseCta: false,
  },

  ctas: [
    {
      key: 'login',
      // The source's "Get Started" would ordinarily open "login and create account". There is no
      // create-account path for this account, and merchant registration must never be exposed here
      // (§2.5), so the action is sign-in only. Recorded as UI06-CTA-001.
      label: 'Log in',
      kind: 'sign_in',
      emphasis: 'primary',
      routeName: 'auth.login',
      eligibilityReason:
        'config/account-hosts.json marks super_administrator selfRegistration:false, invitationAcceptance:false, publicCtaCategory:platform_sign_in.',
      sourceSection: '§13 Final CTA Section — "Secondary CTA: Log In" (promoted to primary)',
    },
    {
      key: 'how-it-works',
      label: 'See how it works',
      kind: 'in_page_anchor',
      emphasis: 'secondary',
      anchorRegion: 'how_it_works',
      eligibilityReason: 'Same-page anchor; no navigation, no host change, no authorization.',
      sourceSection: '§7 How It Works Section',
    },
  ],

  faqLinkLabel: 'Read the full platform FAQ',
};

export default composition;
