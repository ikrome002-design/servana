import { createRouter, createWebHistory, type Router, type RouteRecordRaw } from 'vue-router';
import { currentAccountContext } from '@/host/accountHostContext';
import { useAuthStore } from '@/stores/authStore';
import { auditRoutes } from './routes/audit';
import { authRoutes } from './routes/auth';
import { branchRoutes } from './routes/branch';
import { financeRoutes } from './routes/finance';
import { frontOfficeRoutes } from './routes/frontOffice';
import { hrRoutes, invitationRoutes, merchantHrInvitationRoutes } from './routes/hr';
import { merchantRoutes } from './routes/merchant';
import { personnelRoutes } from './routes/personnel';
import { platformRoutes } from './routes/platform';
import { publicRoutes } from './routes/public';
import { searchRoutes } from './routes/search';


/**
 * The eight account route trees, keyed by the account they belong to (Phase UI-08 Increment 7B;
 * ADR-016/ADR-017).
 *
 * Registering all eight at once is what forced the Super Administrator's canonical paths to stay
 * unregistered: `/audit` belongs to BOTH the Super Administrator and the Merchant Audit account,
 * and `/dashboard`, `/account` and `/reports` collide the same way. Each account is served on its
 * own host, so only one tree is ever reachable in a browser — the collision was an artefact of
 * building one router for all of them.
 *
 * The host selects the EXPERIENCE and nothing else. It is not an input to any guard, policy or
 * query: every protected route still re-checks identity, membership, role, permission, tenant,
 * branch, own-scope and MFA against the server, and the server re-checks all of it again.
 */
const ACCOUNT_ROUTE_TREES: ReadonlyArray<{ owners: readonly string[]; routes: RouteRecordRaw[] }> = [
  { owners: ['super_administrator'], routes: platformRoutes },
  { owners: ['merchant_administrator'], routes: merchantRoutes },
  { owners: ['merchant_administrator'], routes: merchantHrInvitationRoutes },
  /*
   * Plan §10.2 gives the Merchant Administrator branch creation and branch record screens, so the
   * Branch tree is deliberately shared. HR's nineteen-page account tree is never shared: the one
   * historical Merchant Administrator invitation URL is the separate supporting route above.
   */
  { owners: ['merchant_branch', 'merchant_administrator'], routes: branchRoutes },
  { owners: ['merchant_human_resource'], routes: hrRoutes },
  { owners: ['merchant_finance'], routes: financeRoutes },
  { owners: ['merchant_front_office'], routes: frontOfficeRoutes },
  { owners: ['merchant_personnel'], routes: personnelRoutes },
  { owners: ['merchant_audit'], routes: auditRoutes },
];

/**
 * Build the router for one account host.
 *
 * `accountKey` is the SERVER-resolved account for this host, read from the embedded context before
 * the app is created. Passing `null` registers every account's tree: that is the static
 * cross-account case used by the coverage contracts, which must see all 160 pages at once, and it
 * is never how the application mounts.
 */
export function createAppRouter(accountKey: string | null): Router {
  const accountTrees = ACCOUNT_ROUTE_TREES.filter(
    (tree) => accountKey === null || tree.owners.includes(accountKey),
  ).flatMap((tree) => tree.routes);

  const router = createRouter({
    history: createWebHistory(),
    routes: [
      // Phase UI-06: the public surface every approved account host serves — the account's landing
      // page, its FAQ, its three legal documents at role-free paths, and the compatibility redirect
      // from the older `/legal/:role/:doc` shape. All host-derived; none of it is authorization.
      ...publicRoutes,
      {
        path: '/dev/design-system',
        name: 'dev.design-system',
        component: () => import('@/pages/dev/DesignSystemDemo.vue'),
      },
      ...authRoutes,
      // Pre-membership invitation acceptance: served on EVERY host, like auth and the public
      // surface, because it happens before the account it leads to exists.
      ...invitationRoutes,
      ...searchRoutes,
      ...accountTrees,
      {
        // Role-safe denial state (Phase UI-03; UI/UX plan §5.4). Reached by the account-entry guard
        // instead of a redirect to another account.
        path: '/access-denied',
        name: 'access-denied',
        component: () => import('@/pages/auth/AccessDenied.vue'),
      },
      {
        // Phase UI-06: an unknown address says so. It previously rendered the account entry
        // surface, which made every wrong path look like a working page.
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/public/PublicNotFound.vue'),
      },
    ],
  });

  installGuards(router);

  return router;
}

/**
 * Build the router for the host this page is served on.
 *
 * `initAccountContext()` must already have run — `main.ts` calls it before anything else, so the
 * account is known before a single route record exists. An unresolved context yields the public
 * and auth surface only, which is the correct answer for a host Servana does not recognise.
 */
export function createRouterForCurrentHost(): Router {
  return createAppRouter(currentAccountContext()?.accountKey ?? null);
}

function installGuards(router: Router): void {
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
}
