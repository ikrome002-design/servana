import type { NavigationGuardNext, RouteLocationNormalized } from 'vue-router';
import { currentAccountContext } from '@/host/accountHostContext';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';
import { usePermissionStore } from '@/stores/permissionStore';
import type { RoleIdentity } from '@/types/roles';

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

/**
 * Account-entry guard (Phase UI-03; closes `UI01-ROLE-001`).
 *
 * THE DEFECT THIS CLOSES. The `/platform` route tree was guarded by `requiresAuth` alone, so every
 * authenticated user — Personnel included — rendered the Super Administrator shell. The backend
 * refused the data, but the surface itself was disclosed, and a wrong-account deep link silently
 * showed a broader account's chrome while the API calls failed behind it.
 *
 * The guard requires THREE things to agree before an account surface renders:
 *
 *   1. the route's own account (`meta.accountKey`);
 *   2. the account the SERVER resolved for this host (never `window.location`, never a guess);
 *   3. an account context the user actually holds, as reported by `/me` — i.e. their current
 *      membership, read from the database on that request.
 *
 * A mismatch renders the role-safe denial state. It NEVER redirects to another account: bouncing a
 * user "up" to a broader surface is the exact behaviour UI/UX plan §5.4 prohibits, and bouncing
 * them "down" would still confirm which account they do hold.
 *
 * This is UX, and it says so: the backend policies remain the security boundary, and changing the
 * Host header alone still grants nothing (proven server-side by AccountHostDoesNotAuthorizeTest).
 */
export function requiresAccount(...accountKeys: RoleIdentity[]) {
  return (
    _to: RouteLocationNormalized,
    _from: RouteLocationNormalized,
    next: NavigationGuardNext,
  ): void => {
    const auth = useAuthStore();

    if (!auth.isAuthenticated()) {
      next({ name: 'auth.login' });
      return;
    }

    const host = currentAccountContext();

    // The host must be an account this route belongs to. If the server could not establish a host
    // context at all, fail closed rather than guessing.
    //
    // Most routes name ONE account. A few screens are genuinely owned by two — branch creation and
    // the branch record screens belong to the Merchant Administrator (Plan §10.2: "create branches
    // — Merchant Administrator owns within entitlement; Branch Manager: no") while the branch's own
    // manager reads the same records. `docs/frontend/screens/inventory.json` records both owners.
    // Naming only the path prefix's account would deny the owner the Plan assigns the capability
    // to, which is why the list is explicit rather than inferred from the URL.
    if (host === null || !accountKeys.includes(host.accountKey)) {
      next({ name: 'access-denied' });
      return;
    }

    // ...and the user must hold THAT account — the one the server resolved for this host, never
    // merely one of the accounts the route allows. Host and held account must still agree.
    if (!auth.holdsAccount(host.accountKey)) {
      next({ name: 'access-denied' });
      return;
    }

    next();
  };
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

/**
 * Admit a route when the server bootstrap grants at least one of its independent capabilities.
 *
 * This is intentionally an OR check. Some read surfaces expose separately authorized actions
 * (for example, an existing Finance export can be downloaded without granting permission to
 * request or revoke exports). The API remains the security boundary for every request.
 */
export function requiresAnyPermission(...permissions: string[]) {
  return (
    _to: RouteLocationNormalized,
    _from: RouteLocationNormalized,
    next: NavigationGuardNext,
  ): void => {
    const perms = usePermissionStore();
    if (!perms.canAny(permissions)) {
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
    next({ name: 'merchant.setup' });
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
