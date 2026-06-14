import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const financeRoutes: RouteRecordRaw[] = [
  {
    path: '/finance',
    component: () => import('@/layouts/FinanceLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
    children: [
      {
        path: '',
        name: 'finance.dashboard',
        component: () => import('@/pages/finance/DashboardStub.vue'),
      },
    ],
  },
];
