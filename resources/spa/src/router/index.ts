import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

// Minimal Phase 1 router. Role route modules + guards land in Phase 4/11
// (Plan §6.1, §27).
const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/pages/Home.vue'),
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
