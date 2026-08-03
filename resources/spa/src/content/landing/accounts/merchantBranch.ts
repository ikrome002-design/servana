import type { LandingComposition } from '@/content/landing/landingContract';

/**
 * Branch public landing composition (Phase UI-06).
 *
 * An invitation-based account (`selfRegistration: false`, `invitationAcceptance: true`). Its source
 * CTA opens "login and account creation"; the registry says this account cannot self-register, so
 * the primary action is sign-in and the secondary is invitation acceptance on this same host.
 *
 * Its compiled testimonials region carries an unverified customer quotation and is not renderable
 * (`UI05-CONTENT-001`). Binding decision §2.1 replaces it with the factual block below; the source
 * text is untouched and stays compiled.
 */
const composition: LandingComposition = {
  accountKey: 'merchant_branch',
  documentTitle: 'Servana for branch managers — run the branch day with clearer records',
  metaDescription:
    'Servana gives each branch one dashboard for the queue, appointments, service catalogue, invoices, payment records and daily branch activity.',
  heroEyebrow: 'For branch managers',

  // From the compiled header section: Home, Features, How It Works, Branch Operations, Security,
  // FAQ. "Branch Operations" points at the use-cases region, which is where this page's branch
  // situations actually are.
  navigation: [
    { label: 'Home', region: 'hero' },
    { label: 'Features', region: 'features' },
    { label: 'How it works', region: 'how_it_works' },
    { label: 'Branch operations', region: 'use_cases' },
    { label: 'Security', region: 'security' },
    { label: 'FAQ', region: 'faq' },
  ],

  trust: {
    heading: 'Designed for controlled branch operations',
    mode: 'approved_factual_alternative',
    intro:
      'A branch account is scoped to its branch by design. These are the controls that keep it that way — implemented behaviour, not customer evidence.',
    items: [
      {
        title: 'Branch scope is enforced by the server',
        detail:
          'A branch account reaches its own branch. Branch assignment is re-checked on every protected request, never inferred from the page you are on.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Isolation/CrossTenantBranchOwnedModelTest.php',
        sourceReference: 'branch-owned model scope enforcement',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'The branch owns its service catalogue and its day',
        detail:
          'Services, pricing and the operating calendar belong to the branch. Queue, appointment and payment records stay readable to it.',
        evidenceType: 'role_boundary',
        source: 'docs/landing_page/merchant_branch_landing_page_content.md',
        sourceReference: '§6 Features Section — service catalogue and branch day',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Recording a payment is not validating one',
        detail:
          'The branch sees payment records. Validating a payment and issuing a receipt stay with Finance, which is what keeps the receipt trustworthy.',
        evidenceType: 'operational_workflow',
        source: 'tests/Feature/Auth/AuthorityBoundariesTest.php',
        sourceReference: 'branch cannot validate payments or issue receipts',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Access is checked, not assumed',
        detail:
          'User status, role status, merchant assignment, branch assignment and Magic Link rules are all evaluated before a branch record is returned.',
        evidenceType: 'security_control',
        source: 'docs/landing_page/merchant_branch_landing_page_content.md',
        sourceReference: '§14 Security / Compliance Section',
        customerClaim: false,
        metricClaim: false,
      },
    ],
  },

  planAccess: {
    heading: 'Simple pricing for growing service businesses.',
    mode: 'invitation_account_plan_access',
    // The compiled pricing section states no amount — it describes what pricing is structured
    // around — so it is rendered verbatim, with the access facts below it.
    renderCompiledSource: true,
    points: [
      'A branch account is not purchased separately. Your access comes from the merchant business you belong to.',
      'The business owner chooses and manages the subscription from the Merchant Administrator account.',
      'Branch and staff limits follow that subscription; the current limits are shown inside the business account.',
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
        'config/account-hosts.json marks merchant_branch selfRegistration:false, publicCtaCategory:invitation_sign_in.',
      sourceSection: '§1 Header / Navigation — "Login Link: Login"',
    },
    {
      key: 'accept-invitation',
      label: 'Accept your invitation',
      kind: 'invitation_acceptance',
      emphasis: 'secondary',
      routeName: 'staff.accept',
      eligibilityReason:
        'config/account-hosts.json marks merchant_branch invitationAcceptance:true; the route is host-relative and the emailed token remains the credential.',
      sourceSection:
        'Registry-derived. Supersedes the source CTA, which opens merchant account creation this account may not expose (UI06-CTA-001).',
    },
    {
      key: 'branch-operations',
      label: 'See how branch operations work',
      kind: 'in_page_anchor',
      emphasis: 'secondary',
      anchorRegion: 'how_it_works',
      eligibilityReason: 'Same-page anchor; no navigation, no host change, no authorization.',
      sourceSection: '§2 Hero Section — "Secondary CTA: See how branch operations work"',
    },
  ],

  faqLinkLabel: 'Read the full Branch FAQ',
};

export default composition;
