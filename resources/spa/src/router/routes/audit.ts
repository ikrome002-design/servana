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
        name: 'audit.dashboard',
        component: () => import('@/pages/audit/DashboardStub.vue'),
      },
    ],
  },
];
