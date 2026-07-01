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
      {
        path: 'queue',
        name: 'front-office.queue',
        component: () => import('@/pages/front-office/QueueBoard.vue'),
      },
      {
        path: 'walk-in',
        name: 'front-office.walk-in',
        component: () => import('@/pages/front-office/WalkInCreate.vue'),
      },
      {
        path: 'queue/:id',
        name: 'front-office.queue.detail',
        component: () => import('@/pages/front-office/QueueEntryDetail.vue'),
      },
      {
        path: 'sessions',
        name: 'front-office.sessions',
        component: () => import('@/pages/front-office/ServiceSessionList.vue'),
      },
      {
        path: 'invoices',
        name: 'front-office.invoices',
        component: () => import('@/pages/invoicing/InvoiceList.vue'),
      },
      {
        path: 'invoices/create',
        name: 'front-office.invoices.create',
        component: () => import('@/pages/invoicing/InvoiceCreate.vue'),
      },
      {
        path: 'invoices/:id',
        name: 'front-office.invoices.detail',
        component: () => import('@/pages/invoicing/InvoiceDetail.vue'),
      },
    ],
  },
];
