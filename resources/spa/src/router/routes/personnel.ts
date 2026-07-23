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
      {
        path: 'sessions',
        name: 'personnel.sessions',
        component: () => import('@/pages/personnel/MyServiceSessions.vue'),
      },
      {
        // Phase 20H — Personnel own-scope earnings, payout history, statements and earnings queries.
        // Backend authoritative (`personnel.my_earnings/compensation/payouts/statements.view/download` +
        // `personnel.my_earnings_query.create`; own-scope derived from the membership, never selectable).
        path: 'earnings',
        name: 'personnel.earnings',
        component: () => import('@/pages/personnel/Earnings.vue'),
      },
      {
        // Phase 21S — Personnel bulk SMS to PERSONALLY SERVED clients. Backend authoritative
        // (`personnel.my_served_clients.view` for the masked served-client read,
        // `personnel.my_sms.send` + the `sms` entitlement + the billing-status gate for sending;
        // own-scope derived from the membership, never selectable). No contact export exists
        // anywhere on this screen (ADR-010).
        path: 'sms',
        name: 'personnel.sms',
        component: () => import('@/pages/personnel/ClientSms.vue'),
      },
    ],
  },
];
