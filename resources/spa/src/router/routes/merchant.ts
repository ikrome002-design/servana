import type { RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth, requiresPendingSetup } from '@/router/guards';

export const merchantRoutes: RouteRecordRaw[] = [
  // First-time setup wizard (Scope §3.2). Standalone page (no merchant nav),
  // gated to a signed-in owner whose setup is still required.
  //
  // Phase UI-06: `/setup` is the public route contract's name for this page on the Merchant
  // Administrator host (UI/UX plan §4.2). It is an ALIAS of the one implementation, so the same
  // `requiresAuth` + `requiresPendingSetup` guards apply to both paths — a separate route would
  // have been a second, ungoverned way in.
  {
    path: '/onboarding/first-time-setup',
    alias: ['/setup'],
    name: 'onboarding.first-time-setup',
    component: () => import('@/pages/onboarding/FirstTimeSetup.vue'),
    // Phase UI-07: first-time setup is contract page §6.4.1 and belongs to the Merchant
    // Administrator account, so it carries the account guard like the rest of that tree.
    beforeEnter: [requiresAuth, requiresAccount('merchant_administrator'), requiresPendingSetup],
    meta: { accountKey: 'merchant_administrator' },
  },
  {
    path: '/merchant',
    component: () => import('@/layouts/MerchantLayout.vue'),
    // Phase UI-07 — the account guard UI-03 deferred to this phase.
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_administrator')],
    meta: { accountKey: 'merchant_administrator' },
    children: [
      {
        path: '',
        name: 'merchant.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_administrator' },
      },
      {
        path: 'get-started',
        name: 'merchant.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_administrator' },
      },
      {
        path: 'dashboard',
        name: 'merchant.dashboard',
        component: () => import('@/pages/merchant/Dashboard.vue'),
      },
      {
        path: 'period-reopen-approvals',
        name: 'merchant.period-reopen-approvals',
        component: () => import('@/pages/merchant/PeriodReopenApprovals.vue'),
      },
      // REM-SCR-002A — merchant business profile (Plan §27.3 Merchant Administrator "merchant
      // profile"). Backend authoritative: `merchant.profile.view` / `merchant.profile.update` +
      // MerchantProfilePolicy + EnsureBillingMutable on the write.
      {
        path: 'profile',
        name: 'merchant.profile',
        component: () => import('@/pages/merchant/MerchantProfile.vue'),
      },
      // Phase 20B — subscription self-service (Merchant Administrator). Backend remains
      // authoritative (MerchantSubscriptionPolicy + EnsureBillingMutable); these are UX surfaces.
      {
        path: 'subscription',
        name: 'merchant.subscription',
        component: () => import('@/pages/merchant/SubscriptionDashboard.vue'),
      },
      {
        path: 'plan',
        name: 'merchant.plan',
        component: () => import('@/pages/merchant/PlanManagement.vue'),
      },
      {
        path: 'subscription-invoices',
        name: 'merchant.invoices',
        component: () => import('@/pages/merchant/SubscriptionInvoices.vue'),
      },
      {
        // Phase 20E — merchant-wide masked platform-fee visibility + dispute creation. Backend
        // authoritative (server-side merchant scope + `platform_fee.view`/`platform_fee.dispute`).
        path: 'platform-fees',
        name: 'merchant.platform-fees',
        component: () => import('@/pages/billing/PlatformFees.vue'),
        meta: { roleIdentity: 'merchant_administrator' },
      },
      {
        // Phase 20H — Merchant Administrator compensation summary + high-value payout approvals. Backend
        // authoritative (`merchant.compensation_summary.view` masked read; `merchant.payout
        // .approve_high_value` + fresh step-up + Idempotency-Key). MA never creates/verifies/marks-paid.
        path: 'compensation-summary',
        name: 'merchant.compensation-summary',
        component: () => import('@/pages/merchant/CompensationSummary.vue'),
        meta: { roleIdentity: 'merchant_administrator' },
      },
    ],
  },
];
