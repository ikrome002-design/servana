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
        name: 'front-office.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_front_office' },
      },
      {
        path: 'get-started',
        name: 'front-office.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_front_office' },
      },
      {
        path: 'dashboard',
        name: 'front-office.dashboard',
        component: () => import('@/pages/front-office/DashboardStub.vue'),
      },
      {
        path: 'clients',
        name: 'front-office.clients',
        component: () => import('@/pages/front-office/ClientList.vue'),
      },
      {
        path: 'clients/create',
        name: 'front-office.clients.create',
        component: () => import('@/pages/front-office/ClientCreate.vue'),
      },
      {
        path: 'clients/:id',
        name: 'front-office.clients.detail',
        component: () => import('@/pages/front-office/ClientDetail.vue'),
      },
      {
        path: 'appointments',
        name: 'front-office.appointments',
        component: () => import('@/pages/front-office/AppointmentList.vue'),
      },
      {
        path: 'appointments/create',
        name: 'front-office.appointments.create',
        component: () => import('@/pages/front-office/AppointmentCreate.vue'),
      },
      {
        path: 'appointments/:id',
        name: 'front-office.appointments.detail',
        component: () => import('@/pages/front-office/AppointmentDetail.vue'),
      },
    ],
  },
];
