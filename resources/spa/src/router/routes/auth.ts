import type { RouteRecordRaw } from 'vue-router';

// Magic Link authentication pages (Plan §9.1), all under AuthLayout.
export const authRoutes: RouteRecordRaw[] = [
  {
    path: '/auth',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      {
        path: 'login',
        name: 'auth.login',
        component: () => import('@/pages/auth/Login.vue'),
      },
      {
        path: 'check-email',
        name: 'auth.check-email',
        component: () => import('@/pages/auth/CheckEmail.vue'),
      },
      {
        path: 'verify',
        name: 'auth.verify',
        component: () => import('@/pages/auth/Verify.vue'),
      },
    ],
  },
];
