import type { LandingComposition } from '@/content/landing/landingContract';

/**
 * Front Office public landing composition (Phase UI-06).
 *
 * Invitation-based. Its compiled testimonials region carries an unverified attributed customer
 * quotation and is not renderable (`UI05-CONTENT-001`); binding decision §2.1 replaces it with the
 * factual block below. The source text is untouched and stays compiled and hashed.
 */
const composition: LandingComposition = {
  accountKey: 'merchant_front_office',
  documentTitle: 'Servana for front office — serve clients faster, record the day clearly',
  metaDescription:
    'Servana helps the front desk handle walk-ins, appointments, queues, client records, services, invoices and payment details from one dashboard.',
  heroEyebrow: 'For front-office teams',

  // From the compiled header section: Features | How It Works | Front Office | Security | FAQ.
  // "Front Office" points at the use-cases region, where this page's front-desk situations are.
  navigation: [
    { label: 'Features', region: 'features' },
    { label: 'How it works', region: 'how_it_works' },
    { label: 'Front office', region: 'use_cases' },
    { label: 'Security', region: 'security' },
    { label: 'FAQ', region: 'faq' },
  ],

  trust: {
    heading: 'Built for accountable service at the front desk',
    mode: 'approved_factual_alternative',
    intro:
      'The front desk touches clients, queues and money records, so its limits matter as much as its tools. These are implemented behaviours, not customer claims.',
    items: [
      {
        title: 'Record a payment, never validate one',
        detail:
          'Front Office captures the method, amount and reference. Confirming it and issuing the receipt belong to Finance.',
        evidenceType: 'role_boundary',
        source: 'tests/Feature/Auth/AuthorityBoundariesTest.php',
        sourceReference: 'front-office cannot validate payments or issue receipts',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Two clients cannot hold the same slot',
        detail:
          'Overlapping appointments for one person are rejected by a database exclusion constraint, not only by a form check.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Scheduling/AppointmentConflictTest.php',
        sourceReference: 'appointment exclusion constraint',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'A duplicate payment reference is caught',
        detail:
          'A reference already recorded against another payment is flagged for review instead of being quietly accepted twice.',
        evidenceType: 'operational_workflow',
        source: 'tests/Feature/Payments/PaymentGroupValidationTest.php',
        sourceReference: 'duplicate-reference detection on recording',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Client records stay inside the business',
        detail:
          'Client data belongs to the merchant tenant. It is not readable by another business, and there is no contact-export capability anywhere in the product.',
        evidenceType: 'policy_commitment',
        source: 'tests/Feature/Messaging/SmsContactExportProhibitionTest.php',
        sourceReference: 'contact-export prohibition — no field, no endpoint, no screen',
        customerClaim: false,
        metricClaim: false,
      },
    ],
  },

  planAccess: {
    heading: 'Start with the setup that fits your service business.',
    mode: 'invitation_account_plan_access',
    renderCompiledSource: true,
    points: [
      'Front-office access is granted inside the merchant business account; it is not purchased separately.',
      'The business owner selects and manages the subscription from the Merchant Administrator account.',
      'Branch and staff capacity follow that subscription, and the current limits are visible inside the business account.',
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
        'config/account-hosts.json marks merchant_front_office selfRegistration:false, publicCtaCategory:invitation_sign_in.',
      sourceSection: '§1 Header / Navigation — "Login Link: Login"',
    },
    {
      key: 'accept-invitation',
      label: 'Accept your invitation',
      kind: 'invitation_acceptance',
      emphasis: 'secondary',
      routeName: 'staff.accept',
      eligibilityReason:
        'config/account-hosts.json marks merchant_front_office invitationAcceptance:true; the route is host-relative and the emailed token remains the credential.',
      sourceSection:
        'Registry-derived. Supersedes the source CTA behaviour, which opens merchant account creation this account may not expose (UI06-CTA-001).',
    },
    {
      key: 'front-office-flow',
      label: 'See how Servana helps front-office teams',
      kind: 'in_page_anchor',
      emphasis: 'secondary',
      anchorRegion: 'how_it_works',
      eligibilityReason: 'Same-page anchor; no navigation, no host change, no authorization.',
      sourceSection: '§2 Hero Section — "Secondary link: See how Servana helps front-office teams"',
    },
  ],

  faqLinkLabel: 'Read the full Front Office FAQ',
};

export default composition;
