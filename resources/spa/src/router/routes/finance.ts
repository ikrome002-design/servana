import type { RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const financeRoutes: RouteRecordRaw[] = [
  {
    path: '/finance',
    component: () => import('@/layouts/FinanceLayout.vue'),
    // Phase UI-07 — the account guard UI-03 deferred to this phase.
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_finance')],
    meta: { accountKey: 'merchant_finance' },
    children: [
      {
        path: '',
        name: 'finance.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_finance' },
      },
      {
        path: 'get-started',
        name: 'finance.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_finance' },
      },
      {
        path: 'dashboard',
        name: 'finance.dashboard',
        component: () => import('@/pages/finance/TaskInbox.vue'),
      },
      {
        path: 'pending-validations',
        name: 'finance.pending-validations',
        component: () => import('@/pages/payments/PaymentGroupList.vue'),
      },
      {
        path: 'receipts',
        name: 'finance.receipts',
        component: () => import('@/pages/finance/ReceiptList.vue'),
      },
      {
        path: 'receipts/:id',
        name: 'finance.receipts.detail',
        component: () => import('@/pages/finance/ReceiptDetail.vue'),
      },
      {
        path: 'refunds',
        name: 'finance.refunds',
        component: () => import('@/pages/finance/RefundList.vue'),
      },
      {
        path: 'refunds/:id',
        name: 'finance.refunds.detail',
        component: () => import('@/pages/finance/RefundDetail.vue'),
      },
      {
        path: 'disputes',
        name: 'finance.disputes',
        component: () => import('@/pages/finance/DisputeList.vue'),
      },
      {
        path: 'disputes/:id',
        name: 'finance.disputes.detail',
        component: () => import('@/pages/finance/DisputeDetail.vue'),
      },
      {
        path: 'cash-up',
        name: 'finance.cash-up',
        component: () => import('@/pages/finance/CashUpReviewList.vue'),
      },
      {
        path: 'cash-up/:id',
        name: 'finance.cash-up.detail',
        component: () => import('@/pages/finance/CashUpDetail.vue'),
      },
      {
        path: 'periods',
        name: 'finance.periods',
        component: () => import('@/pages/finance/PeriodLockList.vue'),
      },
      {
        path: 'exports',
        name: 'finance.exports',
        component: () => import('@/pages/finance/FinanceExportList.vue'),
      },
      {
        path: 'invoices',
        name: 'finance.invoices',
        component: () => import('@/pages/invoicing/InvoiceList.vue'),
      },
      {
        path: 'invoices/:id',
        name: 'finance.invoices.detail',
        component: () => import('@/pages/invoicing/InvoiceDetail.vue'),
      },
      {
        path: 'payment-records',
        name: 'finance.payment-records',
        component: () => import('@/pages/payments/PaymentGroupList.vue'),
      },
      {
        path: 'payment-records/:id',
        name: 'finance.payment-records.detail',
        component: () => import('@/pages/payments/PaymentGroupDetail.vue'),
      },
      // Phase 19 — the Finance role's own MFA-gated, branch-scoped, masked read of the
      // finance-domain audit trail (finance.audit.view; distinct from Audit's audit.finance.view).
      {
        path: 'audit',
        name: 'finance.audit',
        component: () => import('@/pages/finance/FinanceAuditView.vue'),
      },
      {
        // Phase 20E — Finance platform-fee reconciliation + dispute review/resolve/reject worklist.
        // Backend authoritative (`platform_fee.view` + `platform_fee.dispute.review`; fresh step-up +
        // period-lock + maker/checker on resolve/reject).
        path: 'platform-fees',
        name: 'finance.platform-fees',
        component: () => import('@/pages/billing/PlatformFees.vue'),
        meta: { roleIdentity: 'merchant_finance' },
      },
      {
        // Phase 20G — Finance compensation liabilities (salary accrual + earned commission + manual
        // adjustments). Backend authoritative (`compensation.liability.view` for masked merchant-scoped
        // reads; `compensation.adjustment.create` + fresh step-up + idempotency for a standalone additive
        // adjustment). The browser never computes authoritative liability money.
        path: 'liabilities',
        name: 'finance.liabilities',
        component: () => import('@/pages/finance/CompensationLiabilities.vue'),
        meta: { roleIdentity: 'merchant_finance' },
      },
      {
        // Phase 20H — Finance payout worklist (verify/approve/reject/mark-paid). Backend authoritative
        // (`payout_run.verify/approve_standard/reject/mark_paid`; fresh step-up + Idempotency-Key on the
        // financial-mutation routes). Servana records external settlements only; it moves no money.
        path: 'payout-runs',
        name: 'finance.payout-runs',
        component: () => import('@/pages/finance/PayoutRuns.vue'),
        meta: { roleIdentity: 'merchant_finance' },
      },
      {
        // Phase 20H — Finance earnings-query responder (D-H12-1). A correction is an additive adjustment,
        // never a ledger edit (`earnings_query.respond`; Idempotency-Key server-enforced).
        path: 'earnings-queries',
        name: 'finance.earnings-queries',
        component: () => import('@/pages/finance/EarningsQueries.vue'),
        meta: { roleIdentity: 'merchant_finance' },
      },
    ],
  },
];
