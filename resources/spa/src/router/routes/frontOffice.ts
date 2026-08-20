import type { RouteLocationGeneric, RouteMeta, RouteRecordRaw } from 'vue-router';
import {
  requiresAccount,
  requiresActiveMerchant,
  requiresAuth,
  requiresPermission,
} from '@/router/guards';

const layout = () => import('@/layouts/FrontOfficeLayout.vue');
const redirect = (name: string) => (from: RouteLocationGeneric) => ({ name, query: from.query, hash: from.hash });
const meta = (screenKey: string | null): RouteMeta => ({ roleIdentity: 'merchant_front_office', screenKey });

/** Front Office canonical host-relative tree (Phase UI-13; exact Appendix A §10 contract). */
export const frontOfficeRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_front_office')],
    meta: { accountKey: 'merchant_front_office' },
    children: [
      {
        path: '/dashboard',
        name: 'front-office.dashboard',
        beforeEnter: [requiresPermission('front_office.search')],
        component: () => import('@/pages/front-office/FrontOfficeDashboard.vue'),
        meta: meta('dashboard'),
      },
      {
        path: '/get-started',
        name: 'front-office.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: meta('get-started'),
      },
      {
        path: '/clients',
        name: 'front-office.clients',
        beforeEnter: [requiresPermission('client.view')],
        component: () => import('@/pages/front-office/ClientList.vue'),
        meta: meta('clients'),
      },
      {
        path: '/clients/create',
        name: 'front-office.clients-create',
        beforeEnter: [requiresPermission('client.create')],
        component: () => import('@/pages/front-office/ClientCreate.vue'),
        meta: meta('clients-create'),
      },
      {
        path: '/clients/:clientUlid',
        name: 'front-office.client-detail',
        beforeEnter: [requiresPermission('client.view')],
        component: () => import('@/pages/front-office/ClientDetail.vue'),
        meta: meta('client-detail'),
      },
      {
        path: '/appointments',
        name: 'front-office.appointments',
        beforeEnter: [requiresPermission('appointment.view')],
        component: () => import('@/pages/front-office/AppointmentList.vue'),
        meta: meta('appointments'),
      },
      {
        path: '/walk-ins',
        name: 'front-office.walk-ins',
        beforeEnter: [requiresPermission('queue.create')],
        component: () => import('@/pages/front-office/WalkInCreate.vue'),
        meta: meta('walk-ins'),
      },
      {
        path: '/queue',
        name: 'front-office.queue',
        beforeEnter: [requiresPermission('queue.view')],
        component: () => import('@/pages/front-office/QueueBoard.vue'),
        meta: meta('queue'),
      },
      {
        path: '/queue/:queueUlid/transfer',
        name: 'front-office.queue-transfer',
        beforeEnter: [requiresPermission('queue.transfer')],
        component: () => import('@/pages/front-office/QueueTransfer.vue'),
        meta: meta('queue-transfer'),
      },
      {
        path: '/sessions',
        name: 'front-office.sessions',
        beforeEnter: [requiresPermission('service_session.view')],
        component: () => import('@/pages/front-office/ServiceSessionList.vue'),
        meta: meta('sessions'),
      },
      {
        path: '/invoices',
        name: 'front-office.invoices',
        beforeEnter: [requiresPermission('invoice.view')],
        component: () => import('@/pages/invoicing/InvoiceList.vue'),
        meta: meta('invoices'),
      },
      {
        path: '/invoices/create',
        name: 'front-office.invoices-create',
        beforeEnter: [requiresPermission('invoice.create')],
        component: () => import('@/pages/invoicing/InvoiceCreate.vue'),
        meta: meta('invoices-create'),
      },
      {
        path: '/invoices/:invoiceUlid/payments/create',
        name: 'front-office.invoice-payment-create',
        beforeEnter: [requiresPermission('customer_payment.record')],
        component: () => import('@/pages/payments/RecordPayment.vue'),
        meta: meta('invoice-payment-create'),
      },
      {
        path: '/payments/status',
        name: 'front-office.payments-status',
        beforeEnter: [requiresPermission('customer_payment.record')],
        component: () => import('@/pages/front-office/PaymentReceiptStatus.vue'),
        meta: meta('payments-status'),
      },
      {
        path: '/activity',
        name: 'front-office.activity',
        beforeEnter: [requiresPermission('front_office.search')],
        component: () => import('@/pages/front-office/DailyActivity.vue'),
        meta: meta('activity'),
      },
      {
        path: '/account',
        name: 'front-office.account',
        component: () => import('@/pages/front-office/FrontOfficeAccount.vue'),
        meta: meta('account'),
      },
      {
        path: '/appointments/create',
        name: 'front-office.appointment-create',
        beforeEnter: [requiresPermission('appointment.create')],
        component: () => import('@/pages/front-office/AppointmentCreate.vue'),
        meta: meta(null),
      },
      {
        path: '/appointments/:appointmentUlid',
        name: 'front-office.appointment-detail',
        beforeEnter: [requiresPermission('appointment.view')],
        component: () => import('@/pages/front-office/AppointmentDetail.vue'),
        meta: meta(null),
      },
      {
        path: '/queue/:queueUlid',
        name: 'front-office.queue-entry',
        beforeEnter: [requiresPermission('queue.view')],
        component: () => import('@/pages/front-office/QueueEntryDetail.vue'),
        meta: meta(null),
      },
      {
        path: '/invoices/:invoiceUlid',
        name: 'front-office.invoice-detail',
        beforeEnter: [requiresPermission('invoice.view')],
        component: () => import('@/pages/invoicing/InvoiceDetail.vue'),
        meta: meta(null),
      },
      {
        path: '/receipts/:receiptUlid',
        name: 'front-office.receipt-detail',
        beforeEnter: [requiresPermission('receipt.view')],
        component: () => import('@/pages/finance/ReceiptDetail.vue'),
        meta: meta(null),
      },
    ],
  },
  {
    path: '/front-office',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_front_office')],
    meta: { accountKey: 'merchant_front_office' },
    children: [
      { path: '', redirect: redirect('front-office.dashboard') },
      { path: 'get-started', redirect: redirect('front-office.get-started') },
      { path: 'clients', redirect: redirect('front-office.clients') },
      { path: 'clients/create', redirect: redirect('front-office.clients-create') },
      {
        path: 'clients/:id',
        redirect: (from) => ({ name: 'front-office.client-detail', params: { clientUlid: from.params.id }, query: from.query, hash: from.hash }),
      },
      { path: 'appointments', redirect: redirect('front-office.appointments') },
      { path: 'appointments/create', redirect: redirect('front-office.appointment-create') },
      {
        path: 'appointments/:id',
        redirect: (from) => ({ name: 'front-office.appointment-detail', params: { appointmentUlid: from.params.id }, query: from.query, hash: from.hash }),
      },
      { path: 'walk-in', redirect: redirect('front-office.walk-ins') },
      { path: 'queue', redirect: redirect('front-office.queue') },
      {
        path: 'queue/:id',
        redirect: (from) => ({ name: 'front-office.queue-entry', params: { queueUlid: from.params.id }, query: from.query, hash: from.hash }),
      },
      { path: 'sessions', redirect: redirect('front-office.sessions') },
      { path: 'invoices', redirect: redirect('front-office.invoices') },
      { path: 'invoices/create', redirect: redirect('front-office.invoices-create') },
      {
        path: 'invoices/:id',
        redirect: (from) => ({ name: 'front-office.invoice-detail', params: { invoiceUlid: from.params.id }, query: from.query, hash: from.hash }),
      },
      { path: 'payments', redirect: redirect('front-office.invoices') },
      {
        path: 'payments/record/:id',
        redirect: (from) => ({ name: 'front-office.invoice-payment-create', params: { invoiceUlid: from.params.id }, query: from.query, hash: from.hash }),
      },
      { path: 'receipts', redirect: redirect('front-office.payments-status') },
      {
        path: 'receipts/:id',
        redirect: (from) => ({ name: 'front-office.receipt-detail', params: { receiptUlid: from.params.id }, query: from.query, hash: from.hash }),
      },
    ],
  },
];
