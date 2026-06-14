import type { NavigationGuardNext, RouteLocationNormalized } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';
import { usePermissionStore } from '@/stores/permissionStore';

// Guards are UX-only stubs (Plan §6.2, §3 rule 2). The API is the security
// boundary. Phase 5 wires real auth state; Phase 8 wires real permissions.

export function requiresAuth(
  _to: RouteLocationNormalized,
  _from: RouteLocationNormalized,
  next: NavigationGuardNext,
): void {
  const auth = useAuthStore();
  if (!auth.isAuthenticated()) {
    next({ name: 'auth.login' });
    return;
  }
  next();
}

export function requiresRole(role: string) {
  return (
    _to: RouteLocationNormalized,
    _from: RouteLocationNormalized,
    next: NavigationGuardNext,
  ): void => {
    const auth = useAuthStore();
    if (!auth.activeMembership || auth.activeMembership.role !== role) {
      next({ name: 'home' });
      return;
    }
    next();
  };
}

export function requiresPermission(permission: string) {
  return (
    _to: RouteLocationNormalized,
    _from: RouteLocationNormalized,
    next: NavigationGuardNext,
  ): void => {
    const perms = usePermissionStore();
    if (!perms.can(permission)) {
      next({ name: 'home' });
      return;
    }
    next();
  };
}

export function requiresActiveMerchant(
  _to: RouteLocationNormalized,
  _from: RouteLocationNormalized,
  next: NavigationGuardNext,
): void {
  const merchant = useMerchantStore();
  const auth = useAuthStore();

  // A pending_setup owner must finish first-time setup before the dashboard.
  if (auth.setupRequired() || merchant.isPendingSetup()) {
    next({ name: 'onboarding.first-time-setup' });
    return;
  }

  if (!merchant.isActive()) {
    next({ name: 'home' });
    return;
  }
  next();
}

/**
 * Guards the first-time setup wizard: only a signed-in owner whose setup is
 * still required may enter; an owner who has finished is bounced to the
 * dashboard (UX only — the API enforces both directions).
 */
export function requiresPendingSetup(
  _to: RouteLocationNormalized,
  _from: RouteLocationNormalized,
  next: NavigationGuardNext,
): void {
  const auth = useAuthStore();
  const merchant = useMerchantStore();

  if (!auth.isAuthenticated()) {
    next({ name: 'auth.login' });
    return;
  }

  if (!auth.setupRequired() && !merchant.isPendingSetup()) {
    next({ name: 'merchant.dashboard' });
    return;
  }

  next();
}
