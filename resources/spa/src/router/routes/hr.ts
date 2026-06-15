import type { RouteRecordRaw } from 'vue-router';
import { requiresActiveMerchant, requiresAuth } from '@/router/guards';

export const hrRoutes: RouteRecordRaw[] = [
  // Public staff invitation acceptance (Scope §3.4). No auth — the emailed token
  // is the credential. Rendered standalone under the AuthLayout.
  {
    path: '/staff/accept',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      {
        path: '',
        name: 'staff.accept',
        component: () => import('@/pages/hr/StaffInvitationAccept.vue'),
      },
    ],
  },
  {
    path: '/hr',
    component: () => import('@/layouts/BranchLayout.vue'),
    beforeEnter: [requiresAuth, requiresActiveMerchant],
    children: [
      {
        path: '',
        name: 'hr.staff',
        component: () => import('@/pages/hr/StaffList.vue'),
      },
      {
        path: 'invitations',
        name: 'hr.invitations',
        component: () => import('@/pages/hr/StaffInvitations.vue'),
      },
      {
        path: 'permission-preview',
        name: 'hr.permission-preview',
        component: () => import('@/pages/hr/PermissionPreview.vue'),
      },
      {
        path: 'staff/:id',
        name: 'hr.staff-profile',
        component: () => import('@/pages/hr/StaffProfile.vue'),
      },
    ],
  },
];
