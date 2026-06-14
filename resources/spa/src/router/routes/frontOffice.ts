import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const frontOfficeRoutes: RouteRecordRaw[] = [
  {
    path: '/front-office',
    component: () => import('@/layouts/FrontOfficeLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
    children: [
      {
        path: '',
        name: 'front-office.dashboard',
        component: () => import('@/pages/front-office/DashboardStub.vue'),
      },
    ],
  },
];
