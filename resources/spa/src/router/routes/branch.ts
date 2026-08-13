import type { RouteLocationGeneric, RouteMeta, RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth } from '@/router/guards';

/** Branch Manager canonical host-relative tree (Phase UI-10; ADR-016/ADR-017). */
const layout = () => import('@/layouts/BranchLayout.vue');
const to = (name: string) => (from: RouteLocationGeneric) => ({ name, query: from.query, hash: from.hash });
const meta = (screenKey: string | null): RouteMeta => ({ roleIdentity: 'merchant_branch', screenKey });

export const branchRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_branch')],
    meta: { accountKey: 'merchant_branch' },
    children: [
      { path: '/dashboard', name: 'branch.dashboard', component: () => import('@/pages/branch/BranchDashboard.vue'), meta: meta('dashboard') },
      { path: '/get-started', name: 'branch.get-started', component: () => import('@/pages/get-started/RoleGetStarted.vue'), meta: meta('get-started') },
      { path: '/branch/profile', name: 'branch.branch-profile', component: () => import('@/pages/branch/BranchProfile.vue'), meta: meta('branch-profile') },
      { path: '/branch/calendar', name: 'branch.branch-calendar', component: () => import('@/pages/branch/BranchCalendar.vue'), meta: meta('branch-calendar') },
      { path: '/branch/day', name: 'branch.branch-day', component: () => import('@/pages/branch/BranchDay.vue'), meta: meta('branch-day') },
      { path: '/services', name: 'branch.services', component: () => import('@/pages/branch/ServiceCatalogue.vue'), meta: meta('services') },
      { path: '/staff', name: 'branch.staff', component: () => import('@/pages/branch/PersonnelSchedule.vue'), meta: meta('staff') },
      { path: '/operations/queue', name: 'branch.operations-queue', component: () => import('@/pages/branch/QueueReadOnly.vue'), meta: meta('operations-queue') },
      { path: '/operations/appointments', name: 'branch.operations-appointments', component: () => import('@/pages/branch/AppointmentsReadOnly.vue'), meta: meta('operations-appointments') },
      { path: '/finance/invoices', name: 'branch.finance-invoices', component: () => import('@/pages/branch/FinancialVisibility.vue'), props: { kind: 'invoices' }, meta: meta('finance-invoices') },
      { path: '/finance/payments', name: 'branch.finance-payments', component: () => import('@/pages/branch/FinancialVisibility.vue'), props: { kind: 'payments' }, meta: meta('finance-payments') },
      { path: '/finance/receipts', name: 'branch.finance-receipts', component: () => import('@/pages/branch/BranchReceipts.vue'), meta: meta('finance-receipts') },
      { path: '/cash-up', name: 'branch.cash-up', component: () => import('@/pages/branch/CashUp.vue'), meta: meta('cash-up') },
      { path: '/audit', name: 'branch.audit', component: () => import('@/pages/branch/BranchAudit.vue'), meta: meta('audit') },
      { path: '/account', name: 'branch.account', component: () => import('@/pages/branch/BranchAccount.vue'), meta: meta('account') },
    ],
  },
  {
    // Authenticated role landing plus compatibility URLs with real existing consumers. Targets
    // re-enter the guarded canonical tree and retain query/hash. Supporting queue/settings and
    // operating-hours screens remain outside the eighteen-page register.
    path: '/branch',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_branch')],
    meta: { accountKey: 'merchant_branch' },
    children: [
      { path: '', name: 'branch.landing', component: () => import('@/pages/landing/RoleLanding.vue'), meta: meta(null) },
      { path: 'get-started', redirect: to('branch.get-started') },
      { path: 'services', redirect: to('branch.services') },
      { path: 'personnel-schedule', redirect: to('branch.staff') },
      { path: 'queue', redirect: to('branch.operations-queue') },
      { path: 'appointments', redirect: to('branch.operations-appointments') },
      { path: 'cash-up', redirect: to('branch.cash-up') },
      { path: 'queue-configuration', name: 'branch.queue-configuration', component: () => import('@/pages/branch/QueueConfiguration.vue'), meta: meta(null) },
      { path: ':id/operating-hours', name: 'branch.operating-hours', component: () => import('@/pages/branch/OperatingHours.vue'), meta: meta(null) },
      { path: ':id/calendar', redirect: to('branch.branch-calendar') },
      { path: 'platform-fees', name: 'branch.platform-fees', component: () => import('@/pages/billing/PlatformFees.vue'), meta: meta(null) },
    ],
  },
];
