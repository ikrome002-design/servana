import type { RouteLocationGeneric, RouteMeta, RouteRecordRaw } from 'vue-router';
import {
  requiresAccount,
  requiresActiveMerchant,
  requiresAnyPermission,
  requiresAuth,
  requiresPermission,
} from '@/router/guards';

const layout = () => import('@/layouts/FinanceLayout.vue');
const redirect = (name: string) => (from: RouteLocationGeneric) => ({ name, query: from.query, hash: from.hash });
const meta = (screenKey: string | null): RouteMeta => ({ roleIdentity: 'merchant_finance', screenKey });

/** Finance canonical host-relative tree (Phase UI-12; exact Appendix A §9 contract). */
export const financeRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_finance')],
    meta: { accountKey: 'merchant_finance' },
    children: [
      {
        path: '/dashboard',
        name: 'finance.dashboard',
        beforeEnter: [requiresPermission('customer_payment.view')],
        component: () => import('@/pages/finance/FinanceDashboard.vue'),
        meta: meta('dashboard'),
      },
      {
        path: '/get-started',
        name: 'finance.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: meta('get-started'),
      },
      {
        path: '/tasks',
        name: 'finance.tasks',
        beforeEnter: [requiresPermission('customer_payment.view')],
        component: () => import('@/pages/finance/FinanceTaskInbox.vue'),
        meta: meta('tasks'),
      },
      {
        path: '/payments/validations',
        name: 'finance.payments-validations',
        beforeEnter: [requiresPermission('customer_payment.validate')],
        component: () => import('@/pages/payments/PaymentGroupList.vue'),
        meta: meta('payments-validations'),
      },
      {
        path: '/payments/validations/:groupUlid',
        name: 'finance.payments-validation-detail',
        beforeEnter: [requiresPermission('customer_payment.view')],
        component: () => import('@/pages/payments/PaymentGroupDetail.vue'),
        meta: meta('payments-validation-detail'),
      },
      {
        path: '/payments/duplicates',
        name: 'finance.payments-duplicates',
        beforeEnter: [requiresPermission('customer_payment.duplicate_override')],
        component: () => import('@/pages/finance/DuplicateReferenceReview.vue'),
        meta: meta('payments-duplicates'),
      },
      {
        path: '/invoices',
        name: 'finance.invoices',
        beforeEnter: [requiresPermission('invoice.view')],
        component: () => import('@/pages/invoicing/InvoiceList.vue'),
        meta: meta('invoices'),
      },
      {
        path: '/payments',
        name: 'finance.payments',
        beforeEnter: [requiresPermission('customer_payment.view')],
        component: () => import('@/pages/payments/PaymentGroupList.vue'),
        meta: meta('payments'),
      },
      {
        path: '/payments/partial-split',
        name: 'finance.payments-partial-split',
        beforeEnter: [requiresPermission('customer_payment.view')],
        component: () => import('@/pages/finance/PartialSplitPayments.vue'),
        meta: meta('payments-partial-split'),
      },
      {
        path: '/receipts',
        name: 'finance.receipts',
        beforeEnter: [requiresPermission('receipt.view')],
        component: () => import('@/pages/finance/ReceiptList.vue'),
        meta: meta('receipts'),
      },
      {
        path: '/disputes',
        name: 'finance.disputes',
        beforeEnter: [requiresPermission('finance_dispute.manage')],
        component: () => import('@/pages/finance/DisputeList.vue'),
        meta: meta('disputes'),
      },
      {
        path: '/refunds',
        name: 'finance.refunds',
        beforeEnter: [requiresPermission('refund.create')],
        component: () => import('@/pages/finance/RefundList.vue'),
        meta: meta('refunds'),
      },
      {
        path: '/cash-up',
        name: 'finance.cash-up',
        beforeEnter: [requiresPermission('cash_up.view')],
        component: () => import('@/pages/finance/CashUpReviewList.vue'),
        meta: meta('cash-up'),
      },
      {
        path: '/periods',
        name: 'finance.periods',
        beforeEnter: [requiresPermission('period_lock.create')],
        component: () => import('@/pages/finance/PeriodLockList.vue'),
        meta: meta('periods'),
      },
      {
        path: '/payouts',
        name: 'finance.payouts',
        beforeEnter: [requiresPermission('payout_run.verify')],
        component: () => import('@/pages/finance/PayoutRuns.vue'),
        meta: meta('payouts'),
      },
      {
        path: '/compensation/liabilities',
        name: 'finance.compensation-liabilities',
        component: () => import('@/pages/finance/CompensationLiabilities.vue'),
        meta: meta('compensation-liabilities'),
      },
      {
        path: '/compensation/queries',
        name: 'finance.compensation-queries',
        beforeEnter: [requiresPermission('earnings_query.respond')],
        component: () => import('@/pages/finance/EarningsQueries.vue'),
        meta: meta('compensation-queries'),
      },
      {
        path: '/exports',
        name: 'finance.exports',
        beforeEnter: [requiresAnyPermission('finance_export.create', 'finance_export.download')],
        component: () => import('@/pages/finance/FinanceExportList.vue'),
        meta: meta('exports'),
      },
      {
        path: '/audit',
        name: 'finance.audit',
        beforeEnter: [requiresPermission('finance.audit.view')],
        component: () => import('@/pages/finance/FinanceAuditView.vue'),
        meta: meta('audit'),
      },
      {
        path: '/settings',
        name: 'finance.settings',
        component: () => import('@/pages/finance/FinanceSettings.vue'),
        meta: meta('settings'),
      },
    ],
  },
  {
    // Guarded same-account compatibility and supporting detail routes. The four gated contract
    // identities deliberately have no route/component/request, and the retired platform-fee
    // destination is not aliased onto an unrelated Finance page.
    path: '/finance',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_finance')],
    meta: { accountKey: 'merchant_finance' },
    children: [
      { path: '', name: 'finance.landing', component: () => import('@/pages/landing/RoleLanding.vue'), meta: meta(null) },
      { path: 'dashboard', redirect: redirect('finance.dashboard') },
      { path: 'get-started', redirect: redirect('finance.get-started') },
      { path: 'pending-validations', redirect: redirect('finance.payments-validations') },
      { path: 'payment-records', redirect: redirect('finance.payments') },
      {
        path: 'payment-records/:id',
        redirect: (from) => ({
          name: 'finance.payments-validation-detail',
          params: { groupUlid: from.params.id },
          query: from.query,
          hash: from.hash,
        }),
      },
      { path: 'receipts', redirect: redirect('finance.receipts') },
      { path: 'receipts/:id', name: 'finance.receipts.detail', component: () => import('@/pages/finance/ReceiptDetail.vue') },
      { path: 'refunds', redirect: redirect('finance.refunds') },
      { path: 'refunds/:id', name: 'finance.refunds.detail', component: () => import('@/pages/finance/RefundDetail.vue') },
      { path: 'disputes', redirect: redirect('finance.disputes') },
      { path: 'disputes/:id', name: 'finance.disputes.detail', component: () => import('@/pages/finance/DisputeDetail.vue') },
      { path: 'cash-up', redirect: redirect('finance.cash-up') },
      { path: 'cash-up/:id', name: 'finance.cash-up.detail', component: () => import('@/pages/finance/CashUpDetail.vue') },
      { path: 'periods', redirect: redirect('finance.periods') },
      { path: 'exports', redirect: redirect('finance.exports') },
      { path: 'invoices', redirect: redirect('finance.invoices') },
      { path: 'invoices/:id', name: 'finance.invoices.detail', component: () => import('@/pages/invoicing/InvoiceDetail.vue') },
      { path: 'audit', redirect: redirect('finance.audit') },
      { path: 'liabilities', redirect: redirect('finance.compensation-liabilities') },
      { path: 'payout-runs', redirect: redirect('finance.payouts') },
      { path: 'earnings-queries', redirect: redirect('finance.compensation-queries') },
    ],
  },
];
