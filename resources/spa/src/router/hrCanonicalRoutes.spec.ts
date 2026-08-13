import { describe, expect, it } from 'vitest';
import { createAppRouter } from '@/router';

const LIVE_HR_ROUTES = {
  'hr.dashboard': '/dashboard',
  'hr.get-started': '/get-started',
  'hr.staff': '/staff',
  'hr.staff-invite': '/staff/invite',
  'hr.staff-detail': '/staff/:staffUlid',
  'hr.staff-detail-lifecycle': '/staff/:staffUlid/lifecycle',
  'hr.eligibility': '/eligibility',
  'hr.availability': '/availability',
  'hr.compensation': '/compensation',
  'hr.compensation-detail': '/compensation/:staffUlid',
  'hr.compensation-setup': '/compensation/:staffUlid/setup',
  'hr.compensation-history': '/compensation/:staffUlid/history',
  'hr.payouts': '/payouts',
  'hr.audit': '/audit',
  'hr.account': '/account',
} as const;

describe('UI-11 Human Resource canonical routes', () => {
  it('registers exactly the fifteen live contract destinations at host-relative paths', () => {
    const router = createAppRouter('merchant_human_resource');
    for (const [name, path] of Object.entries(LIVE_HR_ROUTES)) {
      expect(router.hasRoute(name), name).toBe(true);
      const route = router.getRoutes().find((candidate) => candidate.name === name);
      expect(route?.path, name).toBe(path);
      expect(route?.meta.screenKey, name).toBeTruthy();
    }
  });

  it('keeps the four gated contract destinations unregistered', () => {
    const router = createAppRouter('merchant_human_resource');
    for (const name of ['hr.staff-detail-edit', 'hr.staff-detail-access', 'hr.reports', 'hr.notifications']) {
      expect(router.hasRoute(name), name).toBe(false);
    }
  });

  it('does not register the HR account tree on the Merchant Administrator host', () => {
    const router = createAppRouter('merchant_administrator');
    expect(router.hasRoute('hr.dashboard')).toBe(false);
    expect(router.hasRoute('merchant.hr-invitations')).toBe(true);
  });
});
