import type { RouteLocationGeneric, RouteMeta, RouteRecordRaw } from 'vue-router';
import { requiresAccount, requiresActiveMerchant, requiresAuth, requiresPermission } from '@/router/guards';

const layout = () => import('@/layouts/PersonnelLayout.vue');
const redirect = (name: string) => (from: RouteLocationGeneric) => ({ name, query: from.query, hash: from.hash });
const meta = (screenKey: string): RouteMeta => ({ roleIdentity: 'merchant_personnel', screenKey });

/** Phase UI-14 canonical host-relative Personnel tree. Every live route is own-scope. */
export const personnelRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_personnel')],
    meta: { accountKey: 'merchant_personnel' },
    children: [
      { path: '/dashboard', name: 'personnel.dashboard', component: () => import('@/pages/personnel/PersonnelDashboard.vue'), meta: meta('dashboard') },
      { path: '/get-started', name: 'personnel.get-started', component: () => import('@/pages/personnel/PersonnelGetStarted.vue'), meta: meta('get-started') },
      { path: '/work/queue', name: 'personnel.work-queue', beforeEnter: [requiresPermission('personnel.my_queue.view')], component: () => import('@/pages/personnel/MyQueue.vue'), meta: meta('work-queue') },
      { path: '/work/appointments', name: 'personnel.work-appointments', beforeEnter: [requiresPermission('personnel.my_appointments.view')], component: () => import('@/pages/personnel/MyAppointments.vue'), meta: meta('work-appointments') },
      { path: '/work/sessions', name: 'personnel.work-sessions', beforeEnter: [requiresPermission('personnel.my_sessions.view')], component: () => import('@/pages/personnel/MyServiceSessions.vue'), meta: meta('work-sessions') },
      { path: '/work/history', name: 'personnel.work-history', beforeEnter: [requiresPermission('personnel.my_sessions.view')], component: () => import('@/pages/personnel/ServiceHistory.vue'), meta: meta('work-history') },
      { path: '/work/preferred-requests', name: 'personnel.work-preferred-requests', beforeEnter: [requiresPermission('personnel.my_queue.view')], component: () => import('@/pages/personnel/PreferredRequests.vue'), meta: meta('work-preferred-requests') },
      { path: '/clients', name: 'personnel.clients', beforeEnter: [requiresPermission('personnel.my_served_clients.view')], component: () => import('@/pages/personnel/ServedClients.vue'), meta: meta('clients') },
      { path: '/messages/compose', name: 'personnel.messages-compose', beforeEnter: [requiresPermission('personnel.my_sms.send')], component: () => import('@/pages/personnel/SmsComposer.vue'), meta: meta('messages-compose') },
      { path: '/messages', name: 'personnel.messages', beforeEnter: [requiresPermission('personnel.my_sms.send')], component: () => import('@/pages/personnel/MessageHistory.vue'), meta: meta('messages') },
      { path: '/earnings', name: 'personnel.earnings', beforeEnter: [requiresPermission('personnel.my_earnings.view')], component: () => import('@/pages/personnel/EarningsOverview.vue'), meta: meta('earnings') },
      { path: '/earnings/commission', name: 'personnel.earnings-commission', beforeEnter: [requiresPermission('personnel.my_earnings.view')], component: () => import('@/pages/personnel/Commission.vue'), meta: meta('earnings-commission') },
      { path: '/earnings/salary', name: 'personnel.earnings-salary', beforeEnter: [requiresPermission('personnel.my_earnings.view')], component: () => import('@/pages/personnel/Salary.vue'), meta: meta('earnings-salary') },
      { path: '/earnings/payouts', name: 'personnel.earnings-payouts', beforeEnter: [requiresPermission('personnel.my_payouts.view')], component: () => import('@/pages/personnel/Payouts.vue'), meta: meta('earnings-payouts') },
      { path: '/earnings/terms', name: 'personnel.earnings-terms', beforeEnter: [requiresPermission('personnel.my_compensation.view')], component: () => import('@/pages/personnel/CompensationTerms.vue'), meta: meta('earnings-terms') },
      { path: '/earnings/statements', name: 'personnel.earnings-statements', beforeEnter: [requiresPermission('personnel.my_statements.download')], component: () => import('@/pages/personnel/EarningsStatements.vue'), meta: meta('earnings-statements') },
      { path: '/earnings/queries', name: 'personnel.earnings-queries', beforeEnter: [requiresPermission('personnel.my_earnings_query.create')], component: () => import('@/pages/personnel/EarningsQueries.vue'), meta: meta('earnings-queries') },
      { path: '/availability', name: 'personnel.availability', beforeEnter: [requiresPermission('personnel.my_appointments.view')], component: () => import('@/pages/personnel/AvailabilityStatus.vue'), meta: meta('availability') },
      { path: '/account', name: 'personnel.account', component: () => import('@/pages/personnel/PersonnelAccount.vue'), meta: meta('account') },
    ],
  },
  {
    path: '/personnel',
    component: layout,
    beforeEnter: [requiresAuth, requiresActiveMerchant, requiresAccount('merchant_personnel')],
    meta: { accountKey: 'merchant_personnel' },
    children: [
      { path: '', redirect: redirect('personnel.dashboard') },
      { path: 'get-started', redirect: redirect('personnel.get-started') },
      { path: 'queue', redirect: redirect('personnel.work-queue') },
      { path: 'appointments', redirect: redirect('personnel.work-appointments') },
      { path: 'sessions', redirect: redirect('personnel.work-sessions') },
      { path: 'earnings', redirect: redirect('personnel.earnings') },
      { path: 'sms', redirect: redirect('personnel.messages-compose') },
    ],
  },
];
