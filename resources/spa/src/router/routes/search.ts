import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

/**
 * Global search (Plan §68; Phase 22).
 *
 * A top-level authenticated route rather than a per-role screen: search is cross-role by nature, and
 * neither a layout search slot nor a planned search screen existed at Phase 22 entry, so this is the
 * smallest addition that matches the current architecture (there is deliberately no duplicate screen
 * per role).
 *
 * The guards are UX only, as everywhere in this SPA — the API is the security boundary. There is no
 * permission guard because there is no search permission key: `GET /api/v1/search` grants access to
 * nothing and returns only what the caller's existing per-type authority already allows
 * (decision D-22-01). A role with no searchable authority reaches this page and sees the empty state,
 * which is the correct, non-enumerating outcome.
 */
export const searchRoutes: RouteRecordRaw[] = [
  {
    path: '/search',
    name: 'search',
    component: () => import('@/pages/search/GlobalSearch.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
  },
];
