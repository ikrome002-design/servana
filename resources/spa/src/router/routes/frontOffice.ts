import type { RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const frontOfficeRoutes: RouteRecordRaw[] = [
  {
    path: '/front-office',
    component: () => import('@/layouts/FrontOfficeLayout.vue'),
    // Phase UI-07 — the account guard UI-03 deferred to this phase.
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_front_office')],
    meta: { accountKey: 'merchant_front_office' },
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
      // Phase UI-07 removed `front-office.dashboard`: it rendered the "Phase 4 stub"
      // placeholder, exposing contract page §10.4.1 as a live route that implemented nothing
      // (UI/UX plan §7.2). `merchant_front_office.dashboard` reserves the identity as
      // `planned`; UI-13 implements it.
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
      {
        path: 'payments',
        name: 'front-office.payments',
        component: () => import('@/pages/payments/RecordPaymentEntry.vue'),
      },
      {
        path: 'payments/record/:id',
        name: 'front-office.payments.record',
        component: () => import('@/pages/payments/RecordPayment.vue'),
      },
      {
        path: 'receipts',
        name: 'front-office.receipts',
        component: () => import('@/pages/finance/ReceiptList.vue'),
      },
      {
        path: 'receipts/:id',
        name: 'front-office.receipts.detail',
        component: () => import('@/pages/finance/ReceiptDetail.vue'),
      },
    ],
  },
];
