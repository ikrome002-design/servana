import type { LandingComposition } from '@/content/landing/landingContract';

/**
 * Audit public landing composition (Phase UI-06).
 *
 * Invitation-based and read-only. Its source supplies no testimonials section
 * (`UI05-CONTENT-002`); binding decision §2.2 supplies the approved factual alternative below.
 *
 * Its `pricing` region is titled "Pricing / Access Section" in the source, states no amount, and is
 * already access-shaped — so it is rendered verbatim rather than replaced.
 */
const composition: LandingComposition = {
  accountKey: 'merchant_audit',
  documentTitle: 'Servana for audit — review what happened, without changing it',
  metaDescription:
    'Servana gives audit teams a read-only view of role changes, invoices, payment validations, receipts, queue movements and flagged activity.',
  heroEyebrow: 'For audit teams',

  // From the compiled header section: Features, How It Works, Audit Records, Security, FAQs.
  // "Audit Records" points at the product-showcase region, which is where the audit record view is
  // actually described on this page.
  navigation: [
    { label: 'Features', region: 'features' },
    { label: 'How it works', region: 'how_it_works' },
    { label: 'Audit records', region: 'product_showcase' },
    { label: 'Security', region: 'security' },
    { label: 'FAQs', region: 'faq' },
  ],

  trust: {
    heading: 'Independence you can check',
    mode: 'approved_factual_alternative',
    intro:
      'An audit account is only worth having if its independence is structural. These are the mechanisms that make it so — implemented behaviour, not customer evidence.',
    items: [
      {
        title: 'Read-only is enforced, not agreed',
        detail:
          'The audit account cannot edit clients, services, invoices, payments, receipts, users, queues or commissions. The API refuses, not the interface.',
        evidenceType: 'role_boundary',
        source: 'tests/Feature/Auth/AuditReadOnlyTest.php',
        sourceReference: 'mutation denial across every audited surface',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'The record is append-only and chained',
        detail:
          'Audit entries cannot be updated or deleted, and each is hash-chained to the one before it, so a silent edit would break the chain.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Audit/AuditChainVerificationTest.php',
        sourceReference: 'append-only enforcement and hash-chain verification',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'An export is scoped to your own business',
        detail:
          'Audit exports are generated within the merchant tenant and are not readable from another one.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Audit/AuditExportIsolationTest.php',
        sourceReference: 'cross-tenant export isolation',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Review does not slow the floor down',
        detail:
          'Audit reads the record after the fact. It sits outside the queue, the till and the receipt, so reviewing never blocks the people serving clients.',
        evidenceType: 'factual_account_purpose',
        source: 'docs/landing_page/merchant_audit_landing_page_content.md',
        sourceReference: '§11 Security / Trust Section — "designed for oversight, not control"',
        customerClaim: false,
        metricClaim: false,
      },
    ],
  },

  planAccess: {
    heading: 'Start with the oversight your business needs.',
    mode: 'invitation_account_plan_access',
    renderCompiledSource: true,
    points: [
      'Audit access is granted inside the merchant business account; there is no separate audit plan to buy.',
      'As the source section says, access depends on your merchant setup, assigned role and business configuration.',
      'The business owner selects and manages the subscription from the Merchant Administrator account.',
    ],
    withheld: [],
    showsAmount: false,
    purchaseCta: false,
  },

  ctas: [
    {
      key: 'login',
      label: 'Log in',
      kind: 'sign_in',
      emphasis: 'primary',
      routeName: 'auth.login',
      eligibilityReason:
        'config/account-hosts.json marks merchant_audit selfRegistration:false, publicCtaCategory:invitation_sign_in.',
      sourceSection: '§1 Header / Navigation — "Login Link: Login"',
    },
    {
      key: 'accept-invitation',
      label: 'Accept your invitation',
      kind: 'invitation_acceptance',
      emphasis: 'secondary',
      routeName: 'staff.accept',
      eligibilityReason:
        'config/account-hosts.json marks merchant_audit invitationAcceptance:true; the route is host-relative and the emailed token remains the credential.',
      sourceSection:
        'Registry-derived. Supersedes the source CTA behaviour, which opens merchant account creation this account may not expose (UI06-CTA-001).',
    },
    {
      key: 'oversight-flow',
      label: 'See how audit oversight works',
      kind: 'in_page_anchor',
      emphasis: 'secondary',
      anchorRegion: 'how_it_works',
      eligibilityReason: 'Same-page anchor; no navigation, no host change, no authorization.',
      sourceSection: '§2 Hero Section — "Secondary Text Link: See how audit oversight works"',
    },
  ],

  faqLinkLabel: 'Read the full Audit FAQ',
};

export default composition;
