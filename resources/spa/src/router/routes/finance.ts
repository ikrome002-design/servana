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
        name: 'finance.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_finance' },
      },
      {
        path: 'get-started',
        name: 'finance.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_finance' },
      },
      {
        path: 'dashboard',
        name: 'finance.dashboard',
        component: () => import('@/pages/finance/DashboardStub.vue'),
      },
      {
        path: 'invoices',
        name: 'finance.invoices',
        component: () => import('@/pages/invoicing/InvoiceList.vue'),
      },
      {
        path: 'invoices/:id',
        name: 'finance.invoices.detail',
        component: () => import('@/pages/invoicing/InvoiceDetail.vue'),
      },
      {
        path: 'payment-records',
        name: 'finance.payment-records',
        component: () => import('@/pages/payments/PaymentGroupList.vue'),
      },
      {
        path: 'payment-records/:id',
        name: 'finance.payment-records.detail',
        component: () => import('@/pages/payments/PaymentGroupDetail.vue'),
      },
    ],
  },
];
