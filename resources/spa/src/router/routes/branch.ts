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
        name: 'branch.dashboard',
        component: () => import('@/pages/branch/DashboardStub.vue'),
      },
    ],
  },
];
