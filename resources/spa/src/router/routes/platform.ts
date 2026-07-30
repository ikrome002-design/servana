import type { RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresAuth } from '@/router/guards';

export const platformRoutes: RouteRecordRaw[] = [
  {
    path: '/platform',
    component: () => import('@/layouts/PlatformAdminLayout.vue'),
    // Phase UI-03 closes `UI01-ROLE-001`. This tree previously carried `requiresAuth` alone, so
    // ANY authenticated user — Personnel included — rendered the Super Administrator shell. The
    // account guard now requires the route's account, the server-resolved host account and a
    // context the user actually holds to agree before anything mounts.
    beforeEnter: [requiresAuth, requiresAccount('super_administrator')],
    children: [
      {
        path: '',
        name: 'platform.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'super_administrator' },
      },
      {
        path: 'get-started',
        name: 'platform.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'super_administrator' },
      },
      {
        path: 'dashboard',
        name: 'platform.dashboard',
        component: () => import('@/pages/platform/DashboardStub.vue'),
      },
      {
        // Phase 20A — the single genuine platform screen: billing settings, plans,
        // prices, entitlements and the preferred-personnel fee rule. Backend remains
        // authoritative (ResolvePlatformContext + EnsurePermission + policies + MFA/
        // step-up); the tabs/controls here are UX-only permission gates.
        path: 'billing-settings',
        name: 'platform.billing-settings',
        component: () => import('@/pages/platform/BillingSettings.vue'),
        meta: { roleIdentity: 'super_administrator' },
      },
      {
        // Phase 20B — consolidated registration monitoring + merchant directory/detail +
        // operational governance (suspend/reactivate/deactivate). Backend authoritative
        // (ResolvePlatformContext + MFA + fresh merchant_governance step-up + mandatory reason).
        // The "Merchant directory" nav label routes here too (one coherent screen). NO
        // merchant-creation/first-admin/impersonation/payment path exists.
        path: 'registration-monitoring',
        name: 'platform.registration-monitoring',
        component: () => import('@/pages/platform/RegistrationMonitoring.vue'),
        meta: { roleIdentity: 'super_administrator' },
      },
      {
        // Phase 20C — consolidated promotions surface: promotional discounts + free-period
        // offers (Plan §53). Backend authoritative (ResolvePlatformContext + EnsurePermission +
        // policies + MFA + fresh step-up); the sections/controls here are UX-only permission gates.
        path: 'promotions',
        name: 'platform.promotions',
        component: () => import('@/pages/platform/Promotions.vue'),
        meta: { roleIdentity: 'super_administrator' },
      },
    ],
  },
];
