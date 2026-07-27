import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const branchRoutes: RouteRecordRaw[] = [
  {
    path: '/branch',
    component: () => import('@/layouts/BranchLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
    children: [
      {
        path: '',
        name: 'branch.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_branch' },
      },
      {
        path: 'get-started',
        name: 'branch.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_branch' },
      },
      {
        path: 'list',
        name: 'branch.list',
        component: () => import('@/pages/branch/BranchList.vue'),
      },
      {
        path: 'create',
        name: 'branch.create',
        component: () => import('@/pages/branch/BranchCreate.vue'),
      },
      {
        path: 'services',
        name: 'branch.services',
        component: () => import('@/pages/branch/ServiceCatalogue.vue'),
      },
      {
        path: 'personnel-schedule',
        name: 'branch.personnel-schedule',
        component: () => import('@/pages/branch/PersonnelSchedule.vue'),
      },
      {
        path: 'appointments',
        name: 'branch.appointments',
        component: () => import('@/pages/branch/AppointmentsReadOnly.vue'),
      },
      {
        path: 'queue',
        name: 'branch.queue',
        component: () => import('@/pages/branch/QueueReadOnly.vue'),
      },
      {
        path: 'queue-configuration',
        name: 'branch.queue-configuration',
        component: () => import('@/pages/branch/QueueConfiguration.vue'),
      },
      {
        path: 'cash-up',
        name: 'branch.cash-up',
        component: () => import('@/pages/branch/CashUp.vue'),
      },
      {
        // Phase 20E — Branch Manager branch-attributable, read-only platform-fee visibility. Backend
        // server-scopes to the actor's assigned branches (`platform_fee.view`); no mutation controls.
        // Declared before the `:id` catch-all so `/branch/platform-fees` resolves here.
        path: 'platform-fees',
        name: 'branch.platform-fees',
        component: () => import('@/pages/billing/PlatformFees.vue'),
        meta: { roleIdentity: 'merchant_branch' },
      },
      {
        path: ':id',
        name: 'branch.detail',
        component: () => import('@/pages/branch/BranchDetail.vue'),
      },
      {
        path: ':id/operating-hours',
        name: 'branch.operating-hours',
        component: () => import('@/pages/branch/OperatingHours.vue'),
      },
      // REM-SCR-002B — branch CALENDAR: the date-specific overrides on top of the weekly operating
      // hours above (Plan §27.3 Branch Manager "branch profile/calendar"). Backend authoritative:
      // EnsureBranchScope + `branch.calendar.manage` + BranchCalendarExceptionPolicy, and
      // EnsureBillingMutable on every write.
      {
        path: ':id/calendar',
        name: 'branch.calendar',
        component: () => import('@/pages/branch/BranchCalendar.vue'),
      },
    ],
  },
];
