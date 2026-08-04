import type { LandingComposition } from '@/content/landing/landingContract';

/**
 * Merchant Administrator public landing composition (Phase UI-06).
 *
 * The only account that creates a Servana merchant and buys a subscription, and therefore the only
 * one whose page may expose self-registration (`selfRegistration: true` in the account-host
 * registry; §2.5).
 *
 * Its `pricing` section is the only one of the eight that states amounts. They are NOT published —
 * see `planAccess.withheld` for the proof and the binding decision behind it.
 */
const composition: LandingComposition = {
  accountKey: 'merchant_administrator',
  documentTitle: 'Servana by Citrus — run your service business with clearer records',
  metaDescription:
    'Servana gives service-business owners one secure dashboard for clients, branches, staff, invoices, offline payment records, commissions and reports.',
  heroEyebrow: 'For business owners',

  // The account's own navigation vocabulary, from its compiled "Header / Navigation" section:
  // "Features | How It Works | Pricing | Security | Support". "Support" points at the FAQ region,
  // which is where this page's support content actually is — a label may not promise a destination
  // the page does not have.
  navigation: [
    { label: 'Features', region: 'features' },
    { label: 'How it works', region: 'how_it_works' },
    { label: 'Pricing', region: 'pricing' },
    { label: 'Security', region: 'security' },
    { label: 'Support', region: 'faq' },
  ],

  trust: {
    heading: 'Built for accountable service operations',
    mode: 'approved_factual_alternative',
    intro:
      'This account owns the business record. These are the controls that come with it — product capability and security architecture, not customer claims.',
    items: [
      {
        title: 'Your business is its own tenant',
        detail:
          'Every record belongs to one merchant. Another merchant cannot read, infer, edit, export or enumerate it.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Isolation/CrossTenantAccessTest.php',
        sourceReference: 'cross-tenant read/write denial across every tenant-owned model',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'No passwords to lose',
        detail:
          'Sign-in is a single-use Magic Link, hashed at rest, valid for fifteen minutes and bound to the account address it was requested from.',
        evidenceType: 'security_control',
        source: 'tests/Feature/Security/MagicLinkTokenSecurityTest.php',
        sourceReference: 'token hashing, single-use consume and expiry',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Access follows the role, not the person',
        detail:
          'Branch, HR, Finance, Front Office, Personnel and Audit each see the work their role owns, and nothing beyond it.',
        evidenceType: 'role_boundary',
        source: 'tests/Feature/Auth/AuthorityBoundariesTest.php',
        sourceReference: 'authority-boundary matrix per role',
        customerClaim: false,
        metricClaim: false,
      },
      {
        title: 'Servana never touches client money',
        detail:
          'Clients pay your business offline. Servana records the method, amount, reference and validation state so the record is reviewable.',
        evidenceType: 'operational_workflow',
        source: 'docs/landing_page/merchant_administrator_landing_page_content.md',
        sourceReference: '§6 Features Section — "Invoices and offline payment records"',
        customerClaim: false,
        metricClaim: false,
      },
    ],
  },

  planAccess: {
    heading: 'Start with the plan that fits your business.',
    mode: 'merchant_subscription_plan_access',
    // The compiled source is not rendered verbatim here: it is the only pricing section of the
    // eight that carries amounts, and the withheld entry below records exactly why they are not
    // published.
    renderCompiledSource: false,
    points: [
      'Your free period starts when you create your Merchant Administrator account, so early setup does not reduce your trial time.',
      'After the free period, you choose a monthly plan that matches your branch and staff needs.',
      'The launch billing model is subscription-first, with no percentage platform fee active at launch.',
      'Current plan names, branch and staff limits, and prices are shown to you during account creation and in your subscription dashboard, where they come from the live plan catalogue.',
    ],
    withheld: [
      {
        what: 'The four plan tiers and their monthly amounts stated in the compiled pricing section.',
        reason:
          'The canonical price authority is the plan-price catalogue the platform operator maintains at runtime (`subscription_plan_prices`, Phase 20A). No repository fixture seeds it and no public endpoint exposes it — `GET /api/v1/subscription/plans` requires an authenticated merchant session and the platform catalogue requires `platform.plan.view`. A public page therefore cannot prove any amount is current, and UI/UX plan §8.5 forbids showing a stale one. Binding decision §2.4 applies: render the plan-access explanation and no amount. The source text is unchanged and remains compiled and hashed.',
      },
    ],
    showsAmount: false,
    purchaseCta: false,
  },

  ctas: [
    {
      key: 'register',
      // The source writes "**GET STARTED**". Sentence case is required by the Brand Identity
      // "Buttons" rule ("Use sentence case. Good: Create invoice. Avoid: CREATE INVOICE"), which is
      // the reason and the proof §2.5 asks for. The action and destination are unchanged.
      label: 'Get started',
      kind: 'self_registration',
      emphasis: 'primary',
      routeName: 'auth.register',
      eligibilityReason:
        'config/account-hosts.json marks merchant_administrator selfRegistration:true; it is the only merchant-creation path.',
      sourceSection: '§2 Hero Section / §14 Final CTA Section — "CTA: GET STARTED"',
    },
    {
      key: 'login',
      label: 'Log in',
      kind: 'sign_in',
      emphasis: 'secondary',
      routeName: 'auth.login',
      eligibilityReason: 'Magic Link sign-in is available on every account host and grants nothing by itself.',
      sourceSection: '§1 Header / Navigation — "Login Link: Login"',
    },
    {
      key: 'how-it-works',
      label: 'See how it works',
      kind: 'in_page_anchor',
      emphasis: 'secondary',
      anchorRegion: 'how_it_works',
      eligibilityReason: 'Same-page anchor; no navigation, no host change, no authorization.',
      sourceSection: '§2 Hero Section — "Optional secondary link: See How It Works"',
    },
  ],

  faqLinkLabel: 'Read the full Merchant Administrator FAQ',
};

export default composition;
