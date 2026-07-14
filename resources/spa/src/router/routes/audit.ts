import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const auditRoutes: RouteRecordRaw[] = [
  {
    path: '/audit',
    component: () => import('@/layouts/AuditLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
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
      {
        path: 'dashboard',
        name: 'audit.dashboard',
        component: () => import('@/pages/audit/DashboardStub.vue'),
      },
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
