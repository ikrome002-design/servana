import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth, requiresPendingSetup } from '@/router/guards';

export const merchantRoutes: RouteRecordRaw[] = [
  // First-time setup wizard (Scope §3.2). Standalone page (no merchant nav),
  // gated to a signed-in owner whose setup is still required.
  {
    path: '/onboarding/first-time-setup',
    name: 'onboarding.first-time-setup',
    component: () => import('@/pages/onboarding/FirstTimeSetup.vue'),
    beforeEnter: [requiresAuth, requiresPendingSetup],
  },
  {
    path: '/merchant',
    component: () => import('@/layouts/MerchantLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
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
