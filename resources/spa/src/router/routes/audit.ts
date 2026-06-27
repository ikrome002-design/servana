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
    ],
  },
];
