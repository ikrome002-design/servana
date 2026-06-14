import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const hrRoutes: RouteRecordRaw[] = [
  {
    path: '/hr',
    component: () => import('@/layouts/BranchLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
    children: [
      {
        path: '',
        name: 'hr.dashboard',
        component: () => import('@/pages/hr/DashboardStub.vue'),
      },
    ],
  },
];
