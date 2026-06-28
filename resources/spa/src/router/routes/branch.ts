import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const branchRoutes: RouteRecordRaw[] = [
  {
    path: '/branch',
    component: () => import('@/layouts/BranchLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
    children: [
      {
        path: '',
        name: 'branch.landing',
        component: () => import('@/pages/landing/RoleLanding.vue'),
        meta: { roleIdentity: 'merchant_branch' },
      },
      {
        path: 'get-started',
        name: 'branch.get-started',
        component: () => import('@/pages/get-started/RoleGetStarted.vue'),
        meta: { roleIdentity: 'merchant_branch' },
      },
      {
        path: 'list',
        name: 'branch.list',
        component: () => import('@/pages/branch/BranchList.vue'),
      },
      {
        path: 'create',
        name: 'branch.create',
        component: () => import('@/pages/branch/BranchCreate.vue'),
      },
      {
        path: ':id',
        name: 'branch.detail',
        component: () => import('@/pages/branch/BranchDetail.vue'),
      },
      {
        path: ':id/operating-hours',
        name: 'branch.operating-hours',
        component: () => import('@/pages/branch/OperatingHours.vue'),
      },
    ],
  },
];
