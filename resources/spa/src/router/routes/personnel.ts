import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const personnelRoutes: RouteRecordRaw[] = [
  {
    path: '/personnel',
    component: () => import('@/layouts/PersonnelLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
    children: [
      {
        path: '',
        name: 'personnel.dashboard',
        component: () => import('@/pages/personnel/DashboardStub.vue'),
      },
    ],
  },
];
