import type { RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const branchRoutes: RouteRecordRaw[] = [
  {
    path: '/branch',
    component: () => import('@/layouts/BranchLayout.vue'),
    // Phase UI-07 — the account guard UI-03 deferred to this phase.
    //
    // `/branch` is a PATH PREFIX, not an account boundary. Four screens beneath it — the branch
    // list, branch creation, the branch record and its operating hours — are recorded in
    // `docs/frontend/screens/inventory.json` as Merchant Administrator screens, and branch
    // CREATION is the Merchant Administrator's alone (Plan §10.2; the §13 guard matrix gives the
    // Branch Manager "No"). Guarding the whole tree as `merchant_branch` denied the account the
    // Plan assigns the capability to, so those four declare both owners on their own records
    // below. The canonical contract already reserves the Merchant Administrator's own `/branches`
    // and `/branches/:branchUlid` routes as `planned` (UI-09) — this is the current delivery,
    // recorded truthfully rather than widened.
    beforeEnter: [
      requiresAuth,
      requiresActiveMerchant,
      requiresAccount('merchant_branch', 'merchant_administrator'),
    ],
    meta: { accountKey: 'merchant_branch' },
    children: [
      {
        path: '',
        name: 'branch.landing',
        beforeEnter: [requiresAccount('merchant_branch')],
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_branch' },
      },
      {
        path: 'get-started',
        name: 'branch.get-started',
        beforeEnter: [requiresAccount('merchant_branch')],
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
        beforeEnter: [requiresAccount('merchant_branch')],
        component: () => import('@/pages/branch/ServiceCatalogue.vue'),
      },
      {
        path: 'personnel-schedule',
        name: 'branch.personnel-schedule',
        beforeEnter: [requiresAccount('merchant_branch')],
        component: () => import('@/pages/branch/PersonnelSchedule.vue'),
      },
      {
        path: 'appointments',
        name: 'branch.appointments',
        beforeEnter: [requiresAccount('merchant_branch')],
        component: () => import('@/pages/branch/AppointmentsReadOnly.vue'),
      },
      {
        path: 'queue',
        name: 'branch.queue',
        beforeEnter: [requiresAccount('merchant_branch')],
        component: () => import('@/pages/branch/QueueReadOnly.vue'),
      },
      {
        path: 'queue-configuration',
        name: 'branch.queue-configuration',
        beforeEnter: [requiresAccount('merchant_branch')],
        component: () => import('@/pages/branch/QueueConfiguration.vue'),
      },
      {
        path: 'cash-up',
        name: 'branch.cash-up',
        beforeEnter: [requiresAccount('merchant_branch')],
        component: () => import('@/pages/branch/CashUp.vue'),
      },
      {
        // Phase 20E — Branch Manager branch-attributable, read-only platform-fee visibility. Backend
        // server-scopes to the actor's assigned branches (`platform_fee.view`); no mutation controls.
        // Declared before the `:id` catch-all so `/branch/platform-fees` resolves here.
        path: 'platform-fees',
        name: 'branch.platform-fees',
        beforeEnter: [requiresAccount('merchant_branch')],
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
        beforeEnter: [requiresAccount('merchant_branch')],
        component: () => import('@/pages/branch/BranchCalendar.vue'),
      },
    ],
  },
];
