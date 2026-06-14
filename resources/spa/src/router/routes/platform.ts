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
        name: 'platform.dashboard',
        component: () => import('@/pages/platform/DashboardStub.vue'),
      },
    ],
  },
];
