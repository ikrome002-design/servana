import type { RouteRecordRaw } from 'vue-router';

/**
 * Magic Link authentication pages (Plan §9.1), all under AuthLayout.
 *
 * Phase UI-06 reconciled the public route contract (UI/UX plan §4.2), which names `/login`,
 * `/register`, `/auth/magic-link/request` and `/auth/magic-link/consume`. Those are added as
 * ALIASES of the routes that already exist rather than as new pages:
 *
 *  - one implementation, so there is no second login screen to drift or to secure separately;
 *  - `router.resolve({ name: 'auth.login' })` still yields `/auth/login`, so every existing
 *    redirect, guard and UI-03 authentication test is unaffected;
 *  - an alias preserves the query string exactly, which matters for the consume path — a redirect
 *    that dropped `?token=` would break the Magic Link flow outright;
 *  - the alias is a path on the SAME host, so account context is preserved and no redirect loop
 *    is possible.
 *
 * The Magic Link security model is untouched: the same page, the same endpoints, the same
 * single-use hashed token with its fifteen-minute expiry and its seven request-and-consume checks.
 */
export const authRoutes: RouteRecordRaw[] = [
  {
    path: '/auth',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      {
        path: 'login',
        // `/login` is the plan's canonical public path; `/auth/magic-link/request` is the named
        // request step. Both render this one screen.
        alias: ['/login', '/auth/magic-link/request'],
        name: 'auth.login',
        component: () => import('@/pages/auth/Login.vue'),
      },
      {
        path: 'register',
        // Merchant self-registration. Only the Merchant Administrator account LINKS here — the
        // CTA resolver refuses to expose it anywhere the registry says selfRegistration:false.
        alias: ['/register'],
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
        // The consume step. An ALIAS, not a redirect: a redirect would have to carry `?token=`
        // through a second navigation, which both lengthens the token's life in browser history
        // and risks dropping it.
        alias: ['/auth/magic-link/consume'],
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
