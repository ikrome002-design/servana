import type { RouteRecordRaw } from 'vue-router';

// Auth routes use AuthLayout. Full Magic Link flow lands in Phase 5.
export const authRoutes: RouteRecordRaw[] = [
  {
    path: '/auth',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      {
        path: 'login',
        name: 'auth.login',
        component: () => import('@/pages/auth/LoginStub.vue'),
      },
      {
        path: 'verify',
        name: 'auth.verify',
        component: () => import('@/pages/auth/VerifyStub.vue'),
      },
    ],
  },
];
