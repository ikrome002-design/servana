import type { LandingComposition } from '@/content/landing/landingContract';

/**
 * Finance public landing composition (Phase UI-06).
 *
 * Invitation-based. Its compiled testimonials region carries an unverified attributed customer
 * quotation and is not renderable (`UI05-CONTENT-001`); binding decision §2.1 replaces it with the
 * factual block below. The source text is untouched and stays compiled and hashed.
 */
const composition: LandingComposition = {
  accountKey: 'merchant_finance',
  documentTitle: 'Servana for finance — validate payments and keep records you can trust',
  metaDescription:
    'Servana gives finance teams one place to review invoices, validate offline payments, issue receipts, track balances and monitor commission obligations.',
  heroEyebrow: 'For finance teams',

  // From the compiled header section: Features, How It Works, Security, FAQ.
  navigation: [
    { label: 'Features', region: 'features' },
    { label: 'How it works', region: 'how_it_works' },
    { label: 'Security', region: 'security' },
    { label: 'FAQ', region: 'faq' },
  ],

  trust: {
    heading: 'Operational confidence in the money record',
    mode: 'approved_factual_alternative',
    intro:
      'Finance is the validating role, so the controls around it are the point. These are implemented behaviours, not customer claims.',
    items: [
      {
        title: 'A receipt cannot exist before validation',
        detail:
          'Receipt issuance is driven by validation, and the rule is enforced in the database rather than only in application code.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Receipts/ReceiptIssuanceTest.php',
        sourceReference: 'receipt-only-after-validation enforcement',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Receipt and invoice numbers are unique by construction',
        detail:
          'Numbering is allocated under a database uniqueness guarantee, so two concurrent issues cannot produce the same number.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Receipts/ReceiptNumberConcurrencyTest.php',
        sourceReference: 'concurrent receipt-number allocation',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Recording and validating are different hands',
        detail:
          'Front Office records what a client paid. Finance decides whether that record is accurate. Neither role can do both.',
        evidenceType: 'role_boundary',
        source: 'tests/Feature/Auth/AuthorityBoundariesTest.php',
        sourceReference: 'record-versus-validate separation',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Servana takes no client payment',
        detail:
          'Clients pay the business offline by cash, M-Pesa, bank transfer, card terminal, voucher or split payment. Servana holds the record, not the money.',
        evidenceType: 'operational_workflow',
        source: 'docs/landing_page/merchant_finance_landing_page_content.md',
        sourceReference: '§3 Social Proof / Trust Statement',
        customerClaim: false,
        metricClaim: false,
      },
    ],
  },

  planAccess: {
    heading: 'Start with the finance control your business actually needs.',
    mode: 'invitation_account_plan_access',
    renderCompiledSource: true,
    points: [
      'Finance access is granted inside the merchant business account; there is no separate finance plan to buy.',
      'The business owner selects and manages the subscription from the Merchant Administrator account.',
      'As the source section says, available plans and merchant setup details are shown during account creation.',
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
        'config/account-hosts.json marks merchant_finance selfRegistration:false, publicCtaCategory:invitation_sign_in.',
      sourceSection: '§1 Header / Navigation — "Login"',
    },
    {
      key: 'accept-invitation',
      label: 'Accept your invitation',
      kind: 'invitation_acceptance',
      emphasis: 'secondary',
      routeName: 'staff.accept',
      eligibilityReason:
        'config/account-hosts.json marks merchant_finance invitationAcceptance:true; the route is host-relative and the emailed token remains the credential.',
      sourceSection:
        'Registry-derived. Supersedes the source CTA behaviour, which opens merchant account creation this account may not expose (UI06-CTA-001).',
    },
    {
      key: 'finance-flow',
      label: 'See how finance works in Servana',
      kind: 'in_page_anchor',
      emphasis: 'secondary',
      anchorRegion: 'how_it_works',
      eligibilityReason: 'Same-page anchor; no navigation, no host change, no authorization.',
      sourceSection: '§2 Hero Section — "Secondary link: See how finance works in Servana"',
    },
  ],

  faqLinkLabel: 'Read the full Finance FAQ',
};

export default composition;
