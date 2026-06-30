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
        name: 'personnel.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_personnel' },
      },
      {
        path: 'get-started',
        name: 'personnel.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_personnel' },
      },
      {
        path: 'dashboard',
        name: 'personnel.dashboard',
        component: () => import('@/pages/personnel/DashboardStub.vue'),
      },
      {
        path: 'appointments',
        name: 'personnel.appointments',
        component: () => import('@/pages/personnel/MyAppointments.vue'),
      },
      {
        path: 'queue',
        name: 'personnel.queue',
        component: () => import('@/pages/personnel/MyQueue.vue'),
      },
    ],
  },
];
