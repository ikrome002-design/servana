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
    ],
  },
];
