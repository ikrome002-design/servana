import type { RouteLocationGeneric, RouteMeta, RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth, requiresPermission } from '@/router/guards';

/** Public invitation acceptance happens before a membership exists and is not one of the 160 pages. */
export const invitationRoutes: RouteRecordRaw[] = [
  {
    path: '/staff/accept',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      { path: '', name: 'staff.accept', component: () => import('@/pages/hr/StaffInvitationAccept.vue') },
    ],
  },
];

const layout = () => import('@/layouts/HumanResourceLayout.vue');
const redirect = (name: string) => (from: RouteLocationGeneric) => ({ name, query: from.query, hash: from.hash });
const meta = (screenKey: string | null): RouteMeta => ({ roleIdentity: 'merchant_human_resource', screenKey });

/** Human Resource canonical host-relative tree (Phase UI-11; exact Appendix A §8 contract). */
export const hrRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_human_resource')],
    meta: { accountKey: 'merchant_human_resource' },
    children: [
      {
        path: '/dashboard',
        name: 'hr.dashboard',
        beforeEnter: [requiresPermission('staff.view')],
        component: () => import('@/pages/hr/HrDashboard.vue'),
        meta: meta('dashboard'),
      },
      {
        path: '/get-started',
        name: 'hr.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: meta('get-started'),
      },
      {
        path: '/staff',
        name: 'hr.staff',
        beforeEnter: [requiresPermission('staff.view')],
        component: () => import('@/pages/hr/StaffList.vue'),
        meta: meta('staff'),
      },
      {
        path: '/staff/invite',
        name: 'hr.staff-invite',
        beforeEnter: [requiresPermission('staff.invite')],
        component: () => import('@/pages/hr/StaffInvitations.vue'),
        meta: meta('staff-invite'),
      },
      {
        path: '/staff/:staffUlid',
        name: 'hr.staff-detail',
        beforeEnter: [requiresPermission('staff.view')],
        component: () => import('@/pages/hr/StaffProfile.vue'),
        meta: meta('staff-detail'),
      },
      {
        path: '/staff/:staffUlid/lifecycle',
        name: 'hr.staff-detail-lifecycle',
        beforeEnter: [requiresPermission('staff.suspend')],
        component: () => import('@/pages/hr/StaffLifecycle.vue'),
        meta: meta('staff-detail-lifecycle'),
      },
      {
        path: '/eligibility',
        name: 'hr.eligibility',
        beforeEnter: [requiresPermission('personnel.eligibility.manage')],
        component: () => import('@/pages/hr/ServiceEligibility.vue'),
        meta: meta('eligibility'),
      },
      {
        path: '/availability',
        name: 'hr.availability',
        beforeEnter: [requiresPermission('personnel.availability.manage')],
        component: () => import('@/pages/hr/PersonnelAvailability.vue'),
        meta: meta('availability'),
      },
      {
        path: '/compensation',
        name: 'hr.compensation',
        beforeEnter: [requiresPermission('compensation.plan.view')],
        component: () => import('@/pages/hr/Compensation.vue'),
        meta: meta('compensation'),
      },
      {
        path: '/compensation/:staffUlid',
        name: 'hr.compensation-detail',
        beforeEnter: [requiresPermission('compensation.plan.view')],
        component: () => import('@/pages/hr/CompensationDetail.vue'),
        meta: meta('compensation-detail'),
      },
      {
        path: '/compensation/:staffUlid/setup',
        name: 'hr.compensation-setup',
        beforeEnter: [requiresPermission('compensation.plan.create')],
        component: () => import('@/pages/hr/CompensationSetup.vue'),
        meta: meta('compensation-setup'),
      },
      {
        path: '/compensation/:staffUlid/history',
        name: 'hr.compensation-history',
        beforeEnter: [requiresPermission('compensation.history.view')],
        component: () => import('@/pages/hr/CompensationHistory.vue'),
        meta: meta('compensation-history'),
      },
      {
        path: '/payouts',
        name: 'hr.payouts',
        component: () => import('@/pages/hr/PayoutRuns.vue'),
        meta: meta('payouts'),
      },
      {
        path: '/audit',
        name: 'hr.audit',
        beforeEnter: [requiresPermission('staff.view')],
        component: () => import('@/pages/hr/HrAuditActivity.vue'),
        meta: meta('audit'),
      },
      {
        path: '/account',
        name: 'hr.account',
        component: () => import('@/pages/hr/HrAccount.vue'),
        meta: meta('account'),
      },
    ],
  },
  {
    // Authenticated role landing and compatibility paths. Targets re-enter the guarded canonical
    // tree and retain query/hash. The two gated contextual paths are deliberately absent.
    path: '/hr',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_human_resource')],
    meta: { accountKey: 'merchant_human_resource' },
    children: [
      { path: '', name: 'hr.landing', component: () => import('@/pages/landing/RoleLanding.vue'), meta: meta(null) },
      { path: 'get-started', redirect: redirect('hr.get-started') },
      { path: 'staff', redirect: redirect('hr.staff') },
      { path: 'invitations', redirect: redirect('hr.staff-invite') },
      {
        path: 'staff/:id',
        redirect: (from) => ({
          name: 'hr.staff-detail',
          params: { staffUlid: from.params.id },
          query: from.query,
          hash: from.hash,
        }),
      },
      { path: 'eligibility', redirect: redirect('hr.eligibility') },
      { path: 'availability', redirect: redirect('hr.availability') },
      { path: 'compensation', redirect: redirect('hr.compensation') },
      { path: 'payout-runs', redirect: redirect('hr.payouts') },
    ],
  },
];

/**
 * Merchant Administrator's historical invitation URL remains available without registering the
 * HR account tree on the Merchant host. The Merchant page and server target-role policy remain
 * the authority; this supporting route is outside HR's nineteen-page contract.
 */
export const merchantHrInvitationRoutes: RouteRecordRaw[] = [
  {
    path: '/hr',
    component: () => import('@/layouts/MerchantLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_administrator')],
    meta: { accountKey: 'merchant_administrator' },
    children: [
      {
        path: 'invitations',
        name: 'merchant.hr-invitations',
        component: () => import('@/pages/hr/StaffInvitations.vue'),
      },
    ],
  },
];
