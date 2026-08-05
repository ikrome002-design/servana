import type { RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const auditRoutes: RouteRecordRaw[] = [
  {
    path: '/audit',
    component: () => import('@/layouts/AuditLayout.vue'),
    // Phase UI-07 — the account guard UI-03 deferred to this phase. Before it, `requiresAuth` +
    // `requiresActiveMerchant` let ANY authenticated merchant-side user render the Audit shell.
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_audit')],
    meta: { accountKey: 'merchant_audit' },
    children: [
      {
        path: '',
        name: 'audit.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_audit' },
      },
      {
        path: 'get-started',
        name: 'audit.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_audit' },
      },
      // Phase UI-07 removed `audit.dashboard`. It rendered `DashboardStub.vue` — a literal
      // "Phase 4 stub" placeholder — so the Audit Dashboard contract page (§12.4.1) was exposed
      // as a live route that implemented nothing. UI/UX plan §7.2 forbids a planned page from
      // creating a dead or fake destination. The contract entry `merchant_audit.dashboard` now
      // reserves the route identity with `implementation_status: planned`; UI-15 implements it.
      // Phase 19 — Audit read + review + export surfaces. All read-only over source
      // records; only flagged-review metadata + export request/revoke may mutate.
      {
        path: 'events',
        name: 'audit.branch-events',
        component: () => import('@/pages/audit/AuditEventList.vue'),
      },
      {
        path: 'events/:id',
        name: 'audit.event-detail',
        component: () => import('@/pages/audit/AuditEventDetail.vue'),
      },
      {
        path: 'flagged',
        name: 'audit.flagged-events',
        component: () => import('@/pages/audit/FlaggedEventQueue.vue'),
      },
      {
        path: 'flagged/:id',
        name: 'audit.flagged-detail',
        component: () => import('@/pages/audit/FlaggedEventDetail.vue'),
      },
      {
        path: 'finance',
        name: 'audit.finance',
        component: () => import('@/pages/audit/FinanceAudit.vue'),
      },
      {
        path: 'compensation',
        name: 'audit.compensation',
        component: () => import('@/pages/audit/CompensationAudit.vue'),
      },
      {
        path: 'exports',
        name: 'audit.exports',
        component: () => import('@/pages/audit/AuditExportList.vue'),
      },
      {
        path: 'exports/:id',
        name: 'audit.export-detail',
        component: () => import('@/pages/audit/AuditExportDetail.vue'),
      },
      {
        // Phase 20E — Audit masked, branch-scoped, READ-ONLY platform-fee visibility (`platform_fee.view`;
        // Audit holds no dispute-create/review permission, so the UI shows no mutation controls).
        path: 'platform-fees',
        name: 'audit.platform-fees',
        component: () => import('@/pages/billing/PlatformFees.vue'),
        meta: { roleIdentity: 'merchant_audit' },
      },
    ],
  },
];
