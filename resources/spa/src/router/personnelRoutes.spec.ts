import { describe, expect, it } from 'vitest';
import { createAppRouter } from '@/router';
import { NAVIGATION_CONTRACT } from '@/navigation/navigationRegistry.generated';

const router = createAppRouter('merchant_personnel');
const expected = new Map([
  ['personnel.dashboard', '/dashboard'],
  ['personnel.get-started', '/get-started'],
  ['personnel.work-queue', '/work/queue'],
  ['personnel.work-appointments', '/work/appointments'],
  ['personnel.work-sessions', '/work/sessions'],
  ['personnel.work-history', '/work/history'],
  ['personnel.work-preferred-requests', '/work/preferred-requests'],
  ['personnel.clients', '/clients'],
  ['personnel.messages-compose', '/messages/compose'],
  ['personnel.messages', '/messages'],
  ['personnel.earnings', '/earnings'],
  ['personnel.earnings-commission', '/earnings/commission'],
  ['personnel.earnings-salary', '/earnings/salary'],
  ['personnel.earnings-payouts', '/earnings/payouts'],
  ['personnel.earnings-terms', '/earnings/terms'],
  ['personnel.earnings-statements', '/earnings/statements'],
  ['personnel.earnings-queries', '/earnings/queries'],
  ['personnel.availability', '/availability'],
  ['personnel.account', '/account'],
]);

describe('Phase UI-14 Personnel route contract', () => {
  it('registers exactly the nineteen implemented canonical destinations', () => {
    const personnel = router.getRoutes().filter((route) => String(route.name).startsWith('personnel.'));
    expect(personnel).toHaveLength(19);
    for (const [name, path] of expected) {
      expect(router.resolve(path).name, path).toBe(name);
    }
  });

  it('keeps Notifications disabled behind Phase 21N and Gate W with no runtime route', () => {
    const notifications = NAVIGATION_CONTRACT.find((entry) => entry.key === 'merchant_personnel.notifications');
    expect(notifications?.implementationStatus).toBe('disabled_by_gate');
    expect(notifications?.gate).toBe('phase_21n_blocked_by_external_gate_w');
    expect(notifications?.runtimeRouteName).toBeNull();
    expect(router.getRoutes().some((route) => route.name === 'personnel.notifications')).toBe(false);
  });

  it('keeps legacy paths as redirects rather than duplicate pages', () => {
    for (const path of ['/personnel', '/personnel/queue', '/personnel/appointments', '/personnel/sessions', '/personnel/earnings', '/personnel/sms']) {
      const resolved = router.resolve(path);
      expect(resolved.matched.some((record) => record.redirect !== undefined), path).toBe(true);
    }
  });
});
