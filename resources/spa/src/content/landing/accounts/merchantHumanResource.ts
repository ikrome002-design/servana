import type { LandingComposition } from '@/content/landing/landingContract';

/**
 * Human Resource public landing composition (Phase UI-06).
 *
 * The one account whose region-11 source is ALREADY a factual trust statement with no customer
 * claim ("Trust Statement Section", `renderPermitted: true`). Binding decision §2.1 requires
 * preserving that approach where it applies, so this composition renders the compiled section
 * verbatim and adds its evidence items beneath it — `mode: 'compiled_source_section'`.
 */
const composition: LandingComposition = {
  accountKey: 'merchant_human_resource',
  documentTitle: 'Servana for HR — manage staff records, roles and access with control',
  metaDescription:
    'Servana keeps staff records, roles, branch assignments, availability and account access organised for growing service businesses.',
  heroEyebrow: 'For human resource teams',

  // From the compiled header section: Features | How It Works | Security | FAQ.
  navigation: [
    { label: 'Features', region: 'features' },
    { label: 'How it works', region: 'how_it_works' },
    { label: 'Security', region: 'security' },
    { label: 'FAQ', region: 'faq' },
  ],

  trust: {
    heading: 'Trust through clear responsibility',
    mode: 'compiled_source_section',
    intro:
      'The commitments below are how that is actually enforced — implemented behaviour, not customer evidence.',
    items: [
      {
        title: 'HR works within its own branch',
        detail:
          'Staff records, roles, availability and compensation setup are scoped to the branch HR is assigned to.',
        evidenceType: 'role_boundary',
        source: 'tests/Feature/Compensation/CompensationScopeIsolationTest.php',
        sourceReference: 'branch-scoped compensation and staff reads',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'HR cannot grant itself more access',
        detail:
          'Permission changes are checked against the role boundary, so an HR user cannot escalate its own or a peer role beyond what the matrix allows.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Auth/PermissionRoleBoundaryTest.php',
        sourceReference: 'self-escalation denial',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Every access change is checked before it applies',
        detail:
          'Whether the user is active, belongs to the correct merchant, holds the right role and may reach the relevant branch is evaluated on each request.',
        evidenceType: 'security_control',
        source: 'docs/landing_page/merchant_human_resource_landing_page_content.md',
        sourceReference: '§14 Security / Compliance Section',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Staff and client records stay separate concerns',
        detail:
          'HR manages people. It does not export client records or payment details, and no contact-export capability exists for it.',
        evidenceType: 'policy_commitment',
        source: 'tests/Feature/Messaging/SmsContactExportProhibitionTest.php',
        sourceReference: 'contact-export prohibition — no field, no endpoint, no screen',
        customerClaim: false,
        metricClaim: false,
      },
    ],
  },

  planAccess: {
    heading: 'Choose a setup that fits how your business works.',
    mode: 'invitation_account_plan_access',
    renderCompiledSource: true,
    points: [
      'HR access is granted inside the merchant business account; it is not bought separately.',
      'The business owner selects and manages the subscription from the Merchant Administrator account.',
      'Staff and branch capacity follow that subscription, and the current limits are visible inside the business account.',
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
        'config/account-hosts.json marks merchant_human_resource selfRegistration:false, publicCtaCategory:invitation_sign_in.',
      sourceSection: '§1 Header / Navigation — "Login Link: Login"',
    },
    {
      key: 'accept-invitation',
      label: 'Accept your invitation',
      kind: 'invitation_acceptance',
      emphasis: 'secondary',
      routeName: 'staff.accept',
      eligibilityReason:
        'config/account-hosts.json marks merchant_human_resource invitationAcceptance:true; the route is host-relative and the emailed token remains the credential.',
      sourceSection:
        'Registry-derived. Supersedes the source CTA, which opens merchant account creation this account may not expose (UI06-CTA-001).',
    },
    {
      key: 'supports-your-team',
      label: 'See how Servana supports your team',
      kind: 'in_page_anchor',
      emphasis: 'secondary',
      anchorRegion: 'features',
      eligibilityReason: 'Same-page anchor; no navigation, no host change, no authorization.',
      sourceSection: '§2 Hero Section — "Secondary link: See how Servana supports your team"',
    },
  ],

  faqLinkLabel: 'Read the full Human Resource FAQ',
};

export default composition;
