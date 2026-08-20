import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

/**
 * Global search (Plan §68; Phase 22).
 *
 * Search is cross-role by nature, so it remains one route and one screen. It now renders through the
 * shared role shell: UI-13 proved that a top-level standalone page discarded the active account,
 * assigned-branch context and primary navigation even when entered from Front Office Quick Access.
 *
 * The guards are UX only, as everywhere in this SPA — the API is the security boundary. There is no
 * permission guard because there is no search permission key: `GET /api/v1/search` grants access to
 * nothing and returns only what the caller's existing per-type authority already allows
 * (decision D-22-01). A role with no searchable authority reaches this page and sees the empty state,
 * which is the correct, non-enumerating outcome.
 */
export const searchRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/components/layout/RoleShell.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
    children: [
      {
        path: '/search',
        name: 'search',
        component: () => import('@/pages/search/GlobalSearch.vue'),
      },
    ],
  },
];
