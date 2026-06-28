import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import { router } from '@/router';
import { activeRoleIdentity, landingRouteName } from '@/router/destinations';
import { navigationFor } from '@/navigation/roleNavigation';
import { useAuthStore } from '@/stores/authStore';
import { ROLE_ENTRY, ROLE_IDENTITIES, resolveRoleIdentity } from '@/types/roles';
import type { MerchantRole } from '@/types/enums';

function routeNames(): Set<string> {
  return new Set(
    router
      .getRoutes()
      .map((r) => r.name)
      .filter((n): n is string => typeof n === 'string'),
  );
}

describe('role entry routes', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('registers a real landing + get-started route for every role', () => {
    const names = routeNames();
    for (const identity of ROLE_IDENTITIES) {
      expect(names.has(ROLE_ENTRY[identity].landingRouteName)).toBe(true);
      expect(names.has(ROLE_ENTRY[identity].getStartedRouteName)).toBe(true);
    }
  });

  it('every live navigation item resolves to a registered route', () => {
    const names = routeNames();
    for (const identity of ROLE_IDENTITIES) {
      for (const item of navigationFor(identity)) {
        if (item.availability === 'live' && item.routeName) {
          expect(names.has(item.routeName), `${item.key} → ${item.routeName}`).toBe(true);
        }
      }
    }
  });

  it('registers the rendered legal-document route', () => {
    expect(routeNames().has('legal.document')).toBe(true);
  });

  it('maps each backend role to its content identity (no aliases)', () => {
    const cases: Array<[boolean, MerchantRole | null, string | null]> = [
      [true, null, 'super_administrator'],
      [false, 'merchant_admin', 'merchant_administrator'],
      [false, 'branch_manager', 'merchant_branch'],
      [false, 'hr', 'merchant_human_resource'],
      [false, 'finance', 'merchant_finance'],
      [false, 'front_office', 'merchant_front_office'],
      [false, 'personnel', 'merchant_personnel'],
      [false, 'audit', 'merchant_audit'],
      [false, null, null],
    ];
    for (const [isPlatformStaff, membershipRole, expected] of cases) {
      expect(resolveRoleIdentity({ isPlatformStaff, membershipRole })).toBe(expected);
    }
  });

  it('resolves the active landing route from bootstrap state', () => {
    const auth = useAuthStore();
    auth.applyBootstrap({
      user: { id: 'u1', email: 'a@b.co', name: 'A', status: 'active', email_verified_at: null, is_platform_staff: false },
      merchant: { id: 'm1', name: 'Glow', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
      membership: { id: 'mm1', role: 'finance', status: 'active' },
      memberships: [{ id: 'mm1', role: 'finance', status: 'active' }],
      permissions: [],
      setup: { required: false, current_step: null, completed_at: null },
      branch_ids: ['b1'],
      mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
    });
    expect(activeRoleIdentity()).toBe('merchant_finance');
    expect(landingRouteName()).toBe('finance.landing');
  });

  it('routes a platform-staff user to the platform landing', () => {
    const auth = useAuthStore();
    auth.applyBootstrap({
      user: { id: 'sa1', email: 's@b.co', name: 'S', status: 'active', email_verified_at: null, is_platform_staff: true },
      merchant: null,
      membership: null,
      memberships: [],
      permissions: [],
      setup: { required: false, current_step: null, completed_at: null },
      branch_ids: [],
      mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
    });
    expect(landingRouteName()).toBe('platform.landing');
  });

  it('falls back to login when no role can be resolved', () => {
    expect(landingRouteName()).toBe('auth.login');
  });
});
