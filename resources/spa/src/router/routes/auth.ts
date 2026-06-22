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
        path: 'register',
        name: 'auth.register',
        component: () => import('@/pages/auth/RegisterMerchant.vue'),
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
      {
        // Mandatory-MFA enrollment (Plan §18, Phase R3).
        path: 'mfa/setup',
        name: 'auth.mfa.setup',
        component: () => import('@/pages/auth/MfaSetup.vue'),
      },
      {
        // Per-session MFA / step-up challenge (Plan §18, Phase R3).
        path: 'mfa/challenge',
        name: 'auth.mfa.challenge',
        component: () => import('@/pages/auth/MfaChallenge.vue'),
      },
    ],
  },
];
