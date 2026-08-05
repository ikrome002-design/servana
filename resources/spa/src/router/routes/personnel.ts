import type { RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const personnelRoutes: RouteRecordRaw[] = [
  {
    path: '/personnel',
    component: () => import('@/layouts/PersonnelLayout.vue'),
    // Phase UI-07 — the account guard UI-03 deferred to this phase. Personnel is strictly
    // own-scope, so rendering this shell for another account was the widest of the seven gaps.
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_personnel')],
    meta: { accountKey: 'merchant_personnel' },
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
      // Phase UI-07 removed `personnel.dashboard`: it rendered the "Phase 4 stub" placeholder,
      // exposing contract page §11.4.1 as a live route that implemented nothing (UI/UX plan
      // §7.2). `merchant_personnel.dashboard` reserves the identity as `planned`; UI-14
      // implements it.
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
