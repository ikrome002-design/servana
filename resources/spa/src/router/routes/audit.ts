import type { RouteLocationGeneric, RouteMeta, RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth, requiresPermission } from '@/router/guards';

const layout = () => import('@/layouts/AuditLayout.vue');
const meta = (screenKey: string): RouteMeta => ({ roleIdentity: 'merchant_audit', screenKey });

/**
 * A record-id path segment: exactly 26 alphanumerics (the ULID length). Its only job is to keep the
 * fixed words under `/audit` and `/exports` from being read as ids; the server validates the id.
 */
const ULID = '[0-9A-Za-z]{26}';

/** Same-host compatibility redirect that keeps the caller's query (filters, `from`). */
const redirect = (name: string, param?: [from: string, to: string]) => (from: RouteLocationGeneric) => ({
  name,
  query: from.query,
  hash: from.hash,
  ...(param ? { params: { [param[1]]: from.params[param[0]] } } : {}),
});

/**
 * Phase UI-15 canonical host-relative Audit tree (`audit.servana.ke`; Appendix A §12).
 *
 * Fourteen live contract pages plus three supporting records (flag review, export record, the
 * Phase 20E platform-fee view). Notifications is `disabled_by_gate` (Phase 21N / External Gate W)
 * and deliberately has NO route here. Every permission listed is discoverability only: the API's
 * EnsurePermission middleware, policies and branch scope remain the security boundary.
 *
 * Static `/audit/*` children are declared before the ULID-constrained event detail, and the ULID
 * pattern itself rejects words, so `/audit/flags` can never be read as an event id.
 */
export const auditRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_audit')],
    meta: { accountKey: 'merchant_audit' },
    children: [
      { path: '/dashboard', name: 'audit.dashboard', component: () => import('@/pages/audit/AuditDashboard.vue'), meta: meta('dashboard') },
      { path: '/get-started', name: 'audit.get-started', component: () => import('@/pages/get-started/RoleGetStarted.vue'), meta: meta('get-started') },
      { path: '/audit', name: 'audit.audit', beforeEnter: [requiresPermission('audit.branch_events.view')], component: () => import('@/pages/audit/AuditEventList.vue'), meta: meta('audit') },
      { path: '/audit/flags', name: 'audit.audit-flags', beforeEnter: [requiresPermission('audit.branch_events.view')], component: () => import('@/pages/audit/FlaggedEventQueue.vue'), meta: meta('audit-flags') },
      { path: `/audit/flags/:flagUlid(${ULID})`, name: 'audit.flag-detail', beforeEnter: [requiresPermission('audit.branch_events.view')], component: () => import('@/pages/audit/FlaggedEventDetail.vue'), meta: meta('audit-flags') },
      { path: '/audit/operations', name: 'audit.audit-operations', beforeEnter: [requiresPermission('audit.branch_events.view')], component: () => import('@/pages/audit/OperationalAudit.vue'), meta: meta('audit-operations') },
      { path: '/audit/finance', name: 'audit.audit-finance', beforeEnter: [requiresPermission('audit.finance.view')], component: () => import('@/pages/audit/FinanceAudit.vue'), meta: meta('audit-finance') },
      { path: '/audit/compensation', name: 'audit.audit-compensation', beforeEnter: [requiresPermission('audit.compensation.view')], component: () => import('@/pages/audit/CompensationAudit.vue'), meta: meta('audit-compensation') },
      { path: '/audit/access', name: 'audit.audit-access', beforeEnter: [requiresPermission('audit.branch_events.view')], component: () => import('@/pages/audit/AccessAudit.vue'), meta: meta('audit-access') },
      { path: '/audit/privacy-preferred', name: 'audit.audit-privacy-preferred', beforeEnter: [requiresPermission('audit.branch_events.view')], component: () => import('@/pages/audit/PrivacyPreferredAudit.vue'), meta: meta('audit-privacy-preferred') },
      { path: '/audit/integrity', name: 'audit.audit-integrity', beforeEnter: [requiresPermission('audit.branch_events.view')], component: () => import('@/pages/audit/AuditIntegrity.vue'), meta: meta('audit-integrity') },
      // Phase 20E — masked, read-only platform-fee visibility (`platform_fee.view`); a supporting
      // Audit route outside the fifteen-page contract, kept at its existing path.
      { path: '/audit/platform-fees', name: 'audit.platform-fees', beforeEnter: [requiresPermission('platform_fee.view')], component: () => import('@/pages/billing/PlatformFees.vue'), meta: { roleIdentity: 'merchant_audit' } },
      { path: `/audit/:eventUlid(${ULID})`, name: 'audit.audit-event-detail', beforeEnter: [requiresPermission('audit.branch_events.view')], component: () => import('@/pages/audit/AuditEventDetail.vue'), meta: meta('audit-event-detail') },
      { path: '/exports', name: 'audit.exports', beforeEnter: [requiresPermission('audit.export')], component: () => import('@/pages/audit/AuditExportList.vue'), meta: meta('exports') },
      { path: '/exports/history', name: 'audit.exports-history', beforeEnter: [requiresPermission('audit.export')], component: () => import('@/pages/audit/AuditExportHistory.vue'), meta: meta('exports-history') },
      { path: `/exports/:exportUlid(${ULID})`, name: 'audit.export-detail', beforeEnter: [requiresPermission('audit.export')], component: () => import('@/pages/audit/AuditExportDetail.vue'), meta: meta('exports-history') },
      { path: '/account', name: 'audit.account', component: () => import('@/pages/audit/AuditAccount.vue'), meta: meta('account') },

      // Phase 19 / UI-07 compatibility paths on the same host. They carry no capability of their
      // own: each lands on its canonical page, preserving query (filters) and record id.
      { path: '/audit/dashboard', redirect: redirect('audit.dashboard') },
      { path: '/audit/get-started', redirect: redirect('audit.get-started') },
      { path: '/audit/events', redirect: redirect('audit.audit') },
      { path: `/audit/events/:id(${ULID})`, redirect: redirect('audit.audit-event-detail', ['id', 'eventUlid']) },
      { path: '/audit/flagged', redirect: redirect('audit.audit-flags') },
      { path: `/audit/flagged/:id(${ULID})`, redirect: redirect('audit.flag-detail', ['id', 'flagUlid']) },
      { path: '/audit/exports', redirect: redirect('audit.exports') },
      { path: `/audit/exports/:id(${ULID})`, redirect: redirect('audit.export-detail', ['id', 'exportUlid']) },
    ],
  },
];
