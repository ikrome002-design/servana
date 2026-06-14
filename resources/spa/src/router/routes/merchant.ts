import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const merchantRoutes: RouteRecordRaw[] = [
  {
    path: '/merchant',
    component: () => import('@/layouts/MerchantLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
    children: [
      {
        path: '',
        name: 'merchant.dashboard',
        component: () => import('@/pages/merchant/DashboardStub.vue'),
      },
    ],
  },
];
