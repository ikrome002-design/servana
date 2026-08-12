import type { RouteLocationGeneric, RouteRecordRaw } from 'vue-router';
import {
  requiresAccount,
  requiresActiveMerchant,
  requiresAuth,
  requiresPendingSetup,
} from '@/router/guards';

/** Merchant Administrator canonical host-relative tree (Phase UI-09; ADR-016/ADR-017). */
const layout = () => import('@/layouts/MerchantLayout.vue');
const to = (name: string) => (from: RouteLocationGeneric) => ({
  name,
  query: from.query,
  hash: from.hash,
});

export const merchantRoutes: RouteRecordRaw[] = [
  {
    path: '/setup',
    alias: ['/onboarding/first-time-setup'],
    name: 'merchant.setup',
    component: () => import('@/pages/onboarding/FirstTimeSetup.vue'),
    beforeEnter: [requiresAuth, requiresAccount('merchant_administrator'), requiresPendingSetup],
    meta: { accountKey: 'merchant_administrator', roleIdentity: 'merchant_administrator', screenKey: 'setup' },
  },
  {
    path: '/',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_administrator')],
    meta: { accountKey: 'merchant_administrator' },
    children: [
      {
        path: '/dashboard',
        name: 'merchant.dashboard',
        component: () => import('@/pages/merchant/Dashboard.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'dashboard' },
      },
      {
        path: '/get-started',
        name: 'merchant.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'get-started' },
      },
      {
        path: '/merchant/profile',
        name: 'merchant.merchant-profile',
        component: () => import('@/pages/merchant/MerchantProfile.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'merchant-profile' },
      },
      {
        path: '/branches',
        name: 'merchant.branches',
        component: () => import('@/pages/branch/BranchList.vue'),
        props: { merchantOwnerView: true },
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'branches' },
      },
      {
        path: '/branches/:branchUlid',
        name: 'merchant.branch-detail',
        component: () => import('@/pages/merchant/MerchantBranchDetail.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'branch-detail' },
      },
      {
        path: '/staff',
        name: 'merchant.staff',
        component: () => import('@/pages/merchant/StaffOverview.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'staff' },
      },
      {
        path: '/subscription',
        name: 'merchant.subscription',
        component: () => import('@/pages/merchant/SubscriptionDashboard.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'subscription' },
      },
      {
        path: '/subscription/plan',
        name: 'merchant.subscription-plan',
        component: () => import('@/pages/merchant/PlanManagement.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'subscription-plan' },
      },
      {
        path: '/subscription/invoices',
        name: 'merchant.subscription-invoices',
        component: () => import('@/pages/merchant/SubscriptionInvoices.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'subscription-invoices' },
      },
      {
        path: '/subscription/invoices/:invoiceUlid',
        name: 'merchant.subscription-invoice-detail',
        component: () => import('@/pages/merchant/SubscriptionInvoiceDetail.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'subscription-invoice-detail' },
      },
      {
        path: '/compensation',
        name: 'merchant.compensation',
        component: () => import('@/pages/merchant/CompensationSummary.vue'),
        props: { mode: 'summary' },
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'compensation' },
      },
      {
        path: '/compensation/payout-approvals',
        name: 'merchant.compensation-payout-approvals',
        component: () => import('@/pages/merchant/CompensationSummary.vue'),
        props: { mode: 'approvals' },
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'compensation-payout-approvals' },
      },
      {
        path: '/finance/period-reopen-approvals',
        name: 'merchant.finance-period-reopen-approvals',
        component: () => import('@/pages/merchant/PeriodReopenApprovals.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'finance-period-reopen-approvals' },
      },
      {
        path: '/account',
        name: 'merchant.account',
        component: () => import('@/pages/merchant/AccountAndSecurity.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: 'account' },
      },
    ],
  },
  {
    // Authenticated role landing and compatibility URLs with real existing consumers. Every target
    // re-enters the guarded canonical tree and query/hash are retained.
    path: '/merchant',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_administrator')],
    meta: { accountKey: 'merchant_administrator' },
    children: [
      {
        path: '',
        name: 'merchant.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: null },
      },
      { path: 'dashboard', redirect: to('merchant.dashboard') },
      { path: 'get-started', redirect: to('merchant.get-started') },
      { path: 'subscription', redirect: to('merchant.subscription') },
      { path: 'plan', redirect: to('merchant.subscription-plan') },
      { path: 'subscription-invoices', redirect: to('merchant.subscription-invoices') },
      { path: 'compensation-summary', redirect: to('merchant.compensation') },
      { path: 'period-reopen-approvals', redirect: to('merchant.finance-period-reopen-approvals') },
      {
        // Supporting Phase 20E screen retained outside the 23-page UI-09 count.
        path: 'platform-fees',
        name: 'merchant.platform-fees',
        component: () => import('@/pages/billing/PlatformFees.vue'),
        meta: { roleIdentity: 'merchant_administrator', screenKey: null },
      },
    ],
  },
];
