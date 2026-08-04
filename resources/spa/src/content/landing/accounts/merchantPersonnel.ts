import type { LandingComposition } from '@/content/landing/landingContract';

/**
 * Personnel public landing composition (Phase UI-06).
 *
 * Invitation-based, and own-scope: a personnel account reaches its own work, not the business.
 *
 * Its compiled testimonials region contains three quotations the SOURCE ITSELF marks as
 * suggestions to be replaced before launch (`UI05-CONTENT-001`,
 * `placeholder_testimonial_marked_in_source`). Binding decision §2.1 replaces the region with the
 * factual block below. The source text is untouched and stays compiled and hashed.
 */
const composition: LandingComposition = {
  accountKey: 'merchant_personnel',
  documentTitle: 'Servana for personnel — your clients, your work, your commissions',
  metaDescription:
    'Servana shows service personnel their assigned clients, daily queue, appointments, completed services and commission records in one place.',
  heroEyebrow: 'For service personnel',

  // From the compiled header section: Features | How It Works | Benefits | Security | FAQ.
  navigation: [
    { label: 'Features', region: 'features' },
    { label: 'How it works', region: 'how_it_works' },
    { label: 'Benefits', region: 'benefits' },
    { label: 'Security', region: 'security' },
    { label: 'FAQ', region: 'faq' },
  ],

  trust: {
    heading: 'Your work, your record, your limits',
    mode: 'approved_factual_alternative',
    intro:
      'A personnel account is deliberately narrow. These are the boundaries and controls that define it — implemented behaviour, not customer claims.',
    items: [
      {
        title: 'You see your own work',
        detail:
          'Queue, appointments, sessions and earnings are scoped to you. The account does not open the wider business to you, and it does not need to.',
        evidenceType: 'role_boundary',
        source: 'tests/Feature/Compensation/PersonnelEarningsReadModelTest.php',
        sourceReference: 'own-scope earnings read model',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Client contact export does not exist',
        detail:
          'There is no field, no endpoint and no screen anywhere in Servana that exports personnel client contacts. It is a product-wide prohibition, not a permission you have not been given.',
        evidenceType: 'policy_commitment',
        source: 'tests/Feature/Messaging/SmsContactExportProhibitionTest.php',
        sourceReference: 'contact-export prohibition — no field, no endpoint, no screen',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Commission comes from recorded work',
        detail:
          'Earned and pending commission are derived from completed, validated service records, so the figure has something behind it.',
        evidenceType: 'operational_workflow',
        source: 'docs/landing_page/merchant_personnel_landing_page_content.md',
        sourceReference: '§6 Features Section — "Commission Visibility"',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Sign-in is a link, not a password',
        detail:
          'You log in with a single-use Magic Link sent to the email your business activated. Nothing to remember, nothing to leak.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Security/MagicLinkTokenSecurityTest.php',
        sourceReference: 'token hashing, single-use consume and expiry',
        customerClaim: false,
        metricClaim: false,
      },
    ],
  },

  planAccess: {
    heading: 'Pricing is handled through your merchant business account.',
    mode: 'invitation_account_plan_access',
    // This account's compiled pricing section already says exactly this, states no amount, and
    // needs no alternative — it is rendered verbatim with the access facts below it.
    renderCompiledSource: true,
    points: [
      'You do not choose or pay for a plan. Your access is managed by the business you work under.',
      'If you run the business, the Merchant Administrator account is where a merchant account is created and a plan is chosen.',
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
        'config/account-hosts.json marks merchant_personnel selfRegistration:false, publicCtaCategory:invitation_sign_in.',
      sourceSection: '§2 Hero Section — "Already activated by your business? Log in with your email."',
    },
    {
      key: 'accept-invitation',
      label: 'Accept your invitation',
      kind: 'invitation_acceptance',
      emphasis: 'secondary',
      routeName: 'staff.accept',
      eligibilityReason:
        'config/account-hosts.json marks merchant_personnel invitationAcceptance:true; the route is host-relative and the emailed token remains the credential.',
      sourceSection:
        'Registry-derived. Supersedes the source CTA destination logic, which offers merchant account creation this account may not expose (UI06-CTA-001).',
    },
    {
      key: 'how-it-works',
      label: 'See how it works',
      kind: 'in_page_anchor',
      emphasis: 'secondary',
      anchorRegion: 'how_it_works',
      eligibilityReason: 'Same-page anchor; no navigation, no host change, no authorization.',
      sourceSection: '§2 Hero Section — "Secondary CTA: See how it works"',
    },
  ],

  faqLinkLabel: 'Read the full Personnel FAQ',
};

export default composition;
