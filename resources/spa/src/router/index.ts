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
    ...authRoutes,
    ...platformRoutes,
    ...merchantRoutes,
    ...branchRoutes,
    ...hrRoutes,
    ...financeRoutes,
    ...frontOfficeRoutes,
    ...personnelRoutes,
    ...auditRoutes,
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
