import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';
import { auditRoutes } from './routes/audit';
import { authRoutes } from './routes/auth';
import { branchRoutes } from './routes/branch';
import { financeRoutes } from './routes/finance';
import { frontOfficeRoutes } from './routes/frontOffice';
import { hrRoutes } from './routes/hr';
import { merchantRoutes } from './routes/merchant';
import { personnelRoutes } from './routes/personnel';
import { platformRoutes } from './routes/platform';
import { searchRoutes } from './routes/search';

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('@/pages/Home.vue'),
    },
    {
      path: '/dev/design-system',
      name: 'dev.design-system',
      component: () => import('@/pages/dev/DesignSystemDemo.vue'),
    },
    {
      // Rendered role-specific legal documents (Phase 11). Public: no secret
      // data; sourced verbatim from docs/legal/**.
      path: '/legal/:role/:doc',
      name: 'legal.document',
      component: () => import('@/pages/legal/LegalDocument.vue'),
    },
    ...authRoutes,
    ...searchRoutes,
    ...platformRoutes,
    ...merchantRoutes,
    ...branchRoutes,
    ...hrRoutes,
    ...financeRoutes,
    ...frontOfficeRoutes,
    ...personnelRoutes,
    ...auditRoutes,
    {
      // Role-safe denial state (Phase UI-03; UI/UX plan §5.4). Reached by the account-entry guard
      // instead of a redirect to another account.
      path: '/access-denied',
      name: 'access-denied',
      component: () => import('@/pages/auth/AccessDenied.vue'),
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/pages/Home.vue'),
    },
  ],
});

// Resolve the session once before any per-route guard runs (Plan §6.2). The
// /me bootstrap is async, so without this the auth/tenant guards would evaluate
// against empty state on a hard navigation/reload and bounce a logged-in user.
router.beforeEach(async () => {
  const auth = useAuthStore();
  if (!auth.bootstrapped) {
    await auth.bootstrap();
  }
});

// Mandatory MFA gate (Plan §18, Phase R3) — UX only; the API is the security
// boundary. An authenticated mandatory-role user is routed to enrollment or the
// session challenge before any privileged page. The MFA pages and logout/login
// are always reachable so the flow can complete.
const MFA_EXEMPT = new Set([
  'auth.mfa.setup',
  'auth.mfa.challenge',
  'auth.login',
  'auth.verify',
  'auth.check-email',
]);

router.beforeEach((to) => {
  const auth = useAuthStore();

  if (!auth.isAuthenticated() || MFA_EXEMPT.has(String(to.name))) {
    return true;
  }

  if (auth.mfaEnrollmentRequired()) {
    return { name: 'auth.mfa.setup' };
  }

  if (auth.mfaChallengeRequired()) {
    return { name: 'auth.mfa.challenge' };
  }

  return true;
});
