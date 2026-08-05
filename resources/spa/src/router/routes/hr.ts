import type { RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth, requiresPermission } from '@/router/guards';

export const hrRoutes: RouteRecordRaw[] = [
  // Public staff invitation acceptance (Scope §3.4). No auth — the emailed token
  // is the credential. Rendered standalone under the AuthLayout.
  {
    path: '/staff/accept',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      {
        path: '',
        name: 'staff.accept',
        component: () => import('@/pages/hr/StaffInvitationAccept.vue'),
      },
    ],
  },
  {
    path: '/hr',
    // Phase UI-04 (UI01-NAV-002): HR has its own shell. It previously mounted BranchLayout.
    component: () => import('@/layouts/HumanResourceLayout.vue'),
    // Phase UI-07 — the account guard UI-03 deferred to this phase. `/staff/accept` above stays
    // unguarded on purpose: invitation acceptance happens BEFORE the membership exists, and
    // UI/UX plan §7.5 excludes it from the 160 authenticated pages.
    //
    // `/hr` is a PATH PREFIX:
    // `hr.invitations` is served to the Merchant Administrator too (Plan §13 — that account issues
    // the initial Branch Manager and Human Resource invitations), so the tree admits both owners
    // and every HR-only child below re-asserts Human Resource.
    beforeEnter: [
      requiresAuth,
      requiresActiveMerchant,
      requiresAccount('merchant_human_resource', 'merchant_administrator'),
    ],
    meta: { accountKey: 'merchant_human_resource' },
    children: [
      {
        path: '',
        name: 'hr.landing',
        beforeEnter: [requiresAccount('merchant_human_resource')],
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_human_resource' },
      },
      {
        path: 'get-started',
        name: 'hr.get-started',
        beforeEnter: [requiresAccount('merchant_human_resource')],
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_human_resource' },
      },
      {
        path: 'staff',
        name: 'hr.staff',
        beforeEnter: [requiresAccount('merchant_human_resource')],
        component: () => import('@/pages/hr/StaffList.vue'),
      },
      {
        path: 'invitations',
        name: 'hr.invitations',
        component: () => import('@/pages/hr/StaffInvitations.vue'),
      },
      {
        path: 'permission-preview',
        name: 'hr.permission-preview',
        beforeEnter: [requiresAccount('merchant_human_resource')],
        component: () => import('@/pages/hr/PermissionPreview.vue'),
      },
      {
        path: 'staff/:id',
        name: 'hr.staff-profile',
        beforeEnter: [requiresAccount('merchant_human_resource')],
        component: () => import('@/pages/hr/StaffProfile.vue'),
      },
      {
        path: 'eligibility',
        name: 'hr.eligibility',
        beforeEnter: [requiresAccount('merchant_human_resource')],
        component: () => import('@/pages/hr/ServiceEligibility.vue'),
      },
      {
        path: 'availability',
        name: 'hr.availability',
        beforeEnter: [requiresAccount('merchant_human_resource')],
        component: () => import('@/pages/hr/PersonnelAvailability.vue'),
      },
      // Phase 20F — branch-scoped, HR-only compensation configuration. The guard is UX only; the
      // API (EnsureBranchScope + EnsurePermission + policy) is the security boundary.
      {
        path: 'compensation',
        name: 'hr.compensation',
        beforeEnter: [
          requiresAccount('merchant_human_resource'),
          requiresPermission('compensation.plan.view'),
        ],
        component: () => import('@/pages/hr/Compensation.vue'),
      },
      // Phase 20H — HR prepares payout DRAFTS (create/edit/submit/cancel). The screen renders its own
      // permission-gated forbidden state (no route guard, matching finance.liabilities); the API
      // (EnsureBranchScope + EnsurePermission + policy + state machine) is the security boundary. HR
      // never verifies, approves, or marks paid.
      {
        path: 'payout-runs',
        name: 'hr.payout-runs',
        beforeEnter: [requiresAccount('merchant_human_resource')],
        component: () => import('@/pages/hr/PayoutRuns.vue'),
        meta: { roleIdentity: 'merchant_human_resource' },
      },
    ],
  },
];
