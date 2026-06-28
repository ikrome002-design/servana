import type { RouteRecordRaw } from 'vue-router';
import { requiresAuth } from '@/router/guards';

export const platformRoutes: RouteRecordRaw[] = [
  {
    path: '/platform',
    component: () => import('@/layouts/PlatformAdminLayout.vue'),
    beforeEnter: [requiresAuth],
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
    ],
  },
];
