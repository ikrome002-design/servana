import type { RouteLocationGeneric, RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresAuth } from '@/router/guards';

/**
 * The Super Administrator account tree, at its CANONICAL host-relative paths (Phase UI-08
 * Increment 7B; UI/UX plan §5.4, ADR-016/ADR-018).
 *
 * ## Why the paths are no longer prefixed
 *
 * The contract's 22 Super Administrator pages live at `/dashboard`, `/billing/…`, `/merchants/…`,
 * `/audit`, `/platform-access`, `/account` and `/platform/feature-flags`. Four of those collide with
 * another account's contract route — `/audit` is also the Merchant Audit account's tree root — which
 * is exactly why they could not be registered while one router carried all eight accounts. Increment
 * 7B step 1 made the router per host (`createAppRouter(accountKey)`), so only this tree is ever
 * registered on `citrus.servana.ke` and the collision cannot occur.
 *
 * **The host is not authorization.** It selects which experience is mounted. Every route below still
 * runs `requiresAuth` and `requiresAccount('super_administrator')`, and the server re-checks
 * identity, membership, role, permission, tenant, branch, own-scope and MFA on every request
 * (ADR-017). A user who reaches this host without the account gets the account-denied state, not a
 * page.
 *
 * ## Two records, one layout
 *
 * The canonical paths share no prefix, so the tree is one parent at `/` whose children declare
 * ABSOLUTE paths. That keeps a single `PlatformAdminLayout` instance across navigations — the shell,
 * its header navigation and its scroll position survive — which per-route layout wrappers would not.
 * The parent never answers for `/` itself: the public landing owns that path on every account host
 * (UI-06) and is registered first, which `appRouterFactory.spec.ts` asserts.
 *
 * The second record keeps `/platform` as the authenticated role landing (7A deliberately preserved
 * it; it is NOT a twenty-third contract page), carries the one canonical route that genuinely lives
 * under `/platform` — Feature Flags — and holds the four compatibility redirects.
 *
 * ## Metadata, and where it is NOT duplicated
 *
 * Each route carries `accountKey` and `screenKey`. Its navigation group, title, permission set, MFA
 * and step-up requirements are NOT copied here: they live in the canonical navigation map and reach
 * the runtime through `navigationRegistry.generated.ts`. A second copy in route meta would be a
 * second authority that could disagree with the contract, which is the failure mode UI-07 exists to
 * prevent. Route meta carries only what the router itself must know.
 */

/** `screenKey` is read by the UI-07 generator to pin a runtime route to its contract page. */
const layout = () => import('@/layouts/PlatformAdminLayout.vue');

/** Compatibility redirects preserve the query and the hash explicitly (Increment 7B). */
const to = (name: string) => (from: RouteLocationGeneric) => ({
  name,
  query: from.query,
  hash: from.hash,
});

export const platformRoutes: RouteRecordRaw[] = [
  {
    // Canonical Super Administrator destinations. Children declare absolute paths, so the shared
    // layout wraps every page without forcing a common URL prefix the contract does not have.
    path: '/',
    component: layout,
    // Phase UI-03 closed `UI01-ROLE-001`: this tree previously carried `requiresAuth` alone, so ANY
    // authenticated user rendered the Super Administrator shell. The account guard requires the
    // route's account, the server-resolved host account and a context the user actually holds to
    // agree before anything mounts.
    beforeEnter: [requiresAuth, requiresAccount('super_administrator')],
    // Phase UI-07 makes the route's account machine-readable so the coverage contract can prove it
    // from the router itself rather than from the guard closure, whose function name is empty.
    meta: { accountKey: 'super_administrator' },
    children: [
      {
        // §5.4.1 — the platform governance control centre. One server-side aggregate read; the
        // browser computes no total, and a gate-blocked panel renders the gate, never a zero.
        path: '/dashboard',
        name: 'platform.dashboard',
        component: () => import('@/pages/platform/PlatformDashboard.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'dashboard' },
      },
      {
        // §5.4.2 — configuration guidance in dependency order, composed from six shipped reads.
        path: '/get-started',
        name: 'platform.get-started',
        component: () => import('@/pages/platform/PlatformGetStarted.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'get-started' },
      },
      {
        // §5.4.3 — Phase 20A billing settings, narrowed to its own page.
        path: '/billing/settings',
        name: 'platform.billing-settings',
        component: () => import('@/pages/platform/PlatformBillingSettings.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'billing-settings' },
      },
      {
        // §5.4.4 — plan metadata and the server-enforced entitlements attached to each plan.
        path: '/billing/plans',
        name: 'platform.billing-plans',
        component: () => import('@/pages/platform/PlansAndEntitlements.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'billing-plans' },
      },
      {
        // §5.4.5 — effective-dated prices across the five billing intervals.
        path: '/billing/prices',
        name: 'platform.billing-prices',
        component: () => import('@/pages/platform/PlanPrices.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'billing-prices' },
      },
      {
        // §5.4.6 — promotional discounts; at most one applies to an issuance, resolved server-side.
        path: '/billing/promotions',
        name: 'platform.billing-promotions',
        component: () => import('@/pages/platform/PromotionalDiscounts.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'billing-promotions' },
      },
      {
        // §5.4.7 — free-period offers, sharing the Phase 20C form with promotions.
        path: '/billing/free-periods',
        name: 'platform.billing-free-periods',
        component: () => import('@/pages/platform/FreePeriodOffers.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'billing-free-periods' },
      },
      {
        // §5.4.8 — preferred-personnel fee rules; supersede, never edit.
        path: '/billing/preferred-personnel-fees',
        name: 'platform.billing-preferred-personnel-fees',
        component: () => import('@/pages/platform/PreferredPersonnelFeeRules.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'billing-preferred-personnel-fees' },
      },
      {
        // §5.4.9 — SMS billing rules and usage (COR-UI08-001). Snapshots are immutable.
        path: '/billing/sms',
        name: 'platform.billing-sms',
        component: () => import('@/pages/platform/SmsBillingSettings.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'billing-sms' },
      },
      {
        // §5.4.13 — platform-wide subscription operations. Monitoring only: no mutation exists.
        path: '/billing/subscriptions',
        name: 'platform.billing-subscriptions',
        component: () => import('@/pages/platform/SubscriptionOperations.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'billing-subscriptions' },
      },
      {
        // §5.4.10 — registration monitoring. Declared BEFORE the parameterised merchant detail for
        // readability; vue-router ranks the static segment higher regardless of order, which
        // `MerchantPages.spec.ts` pins.
        path: '/merchants/registrations',
        name: 'platform.merchant-registrations',
        component: () => import('@/pages/platform/MerchantRegistrations.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'merchant-registrations' },
      },
      {
        // §5.4.11 — the merchant directory. A row is a link to the detail route, not a selection.
        path: '/merchants',
        name: 'platform.merchants',
        component: () => import('@/pages/platform/MerchantDirectory.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'merchants' },
      },
      {
        // §5.4.12 — merchant detail and governance. `navigation_visibility: detail_route`: it is
        // reached from the directory and never from the header, because a parameterised route
        // cannot be resolved from a route name alone.
        path: '/merchants/:merchantUlid',
        name: 'platform.merchant-detail',
        component: () => import('@/pages/platform/MerchantDetail.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'merchant-detail' },
      },
      {
        // §5.4.18 — the append-only platform governance chain. Also a Merchant Audit contract path;
        // host-scoped registration is what lets both accounts keep their canonical URL.
        path: '/audit',
        name: 'platform.audit',
        component: () => import('@/pages/platform/PlatformAudit.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'audit' },
      },
      {
        // §5.4.19 — internal Citrus platform access (COR-UI08-001). Sole-admin lockout enforced
        // server-side; self-escalation refused.
        path: '/platform-access',
        name: 'platform.platform-access',
        component: () => import('@/pages/platform/InternalPlatformAccess.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'platform-access' },
      },
      {
        // §5.4.22 — the signed-in Super Administrator's own identity, MFA, sessions and preferences.
        path: '/account',
        name: 'platform.account',
        component: () => import('@/pages/platform/AccountAndSecurity.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'account' },
      },
    ],
  },
  {
    path: '/platform',
    component: layout,
    beforeEnter: [requiresAuth, requiresAccount('super_administrator')],
    meta: { accountKey: 'super_administrator' },
    children: [
      {
        // The authenticated role landing. Preserved deliberately by 7A: the role-entry contract
        // routes every account to its landing, and this is NOT a twenty-third contract page. It is
        // not redirected to `/dashboard`.
        path: '',
        name: 'platform.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: null },
      },
      {
        // §5.4.20 — feature flags (COR-UI08-001). The one canonical Super Administrator route whose
        // contract path genuinely lives under `/platform`. A flag can never grant a permission,
        // bypass an entitlement or billing state, or open an external gate.
        path: 'feature-flags',
        name: 'platform.feature-flags',
        component: () => import('@/pages/platform/FeatureFlags.vue'),
        meta: { roleIdentity: 'super_administrator', screenKey: 'feature-flags' },
      },

      /*
       * The four compatibility redirects (Increment 7B).
       *
       * Each has a PROVEN current consumer — the Phase 20A/20B/20C/20E end-to-end specs and the
       * release-audit matrix drove these paths before the canonical routes existed. They are
       * same-account only, they preserve the query and the hash explicitly, and they resolve to a
       * route inside the guarded canonical tree, so `requiresAccount` still applies at the target.
       *
       * There is deliberately NO `/platform/dashboard` redirect: UI-07 removed that route because
       * it rendered a Phase 4 stub, and resurrecting it through compatibility routing would give a
       * placeholder a second life. `/platform` itself is not redirected either — it is the role
       * landing above.
       */
      { path: 'get-started', redirect: to('platform.get-started') },
      { path: 'billing-settings', redirect: to('platform.billing-settings') },
      { path: 'promotions', redirect: to('platform.billing-promotions') },
      { path: 'registration-monitoring', redirect: to('platform.merchant-registrations') },
    ],
  },
];
