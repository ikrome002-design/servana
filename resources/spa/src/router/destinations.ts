import { useAuthStore } from '@/stores/authStore';
import { ROLE_ENTRY, resolveRoleIdentity, type RoleIdentity } from '@/types/roles';

/**
 * Role-aware post-login destinations (Phase 11). UX routing only — the API is
 * the security boundary. The MFA gate (router/index.ts) and the
 * pending-setup/active-merchant guards (router/guards.ts) still run first; this
 * only decides which role landing an already-eligible user lands on.
 */

/** The active role identity from bootstrap, or null when unresolved/unsupported. */
export function activeRoleIdentity(): RoleIdentity | null {
  const auth = useAuthStore();
  return resolveRoleIdentity({
    isPlatformStaff: auth.user?.is_platform_staff === true,
    membershipRole: auth.membership?.role ?? null,
  });
}

/** The active user ULID + role identity, for get-started persistence scoping. */
export function activeRoleContext(): { userId: string; identity: RoleIdentity } | null {
  const auth = useAuthStore();
  const userId = auth.user?.id;
  const identity = activeRoleIdentity();
  if (!userId || !identity) return null;
  return { userId, identity };
}

/**
 * The landing route name for the current user. Falls back to the login route
 * when no role can be resolved (unauthenticated or unsupported role).
 */
export function landingRouteName(): string {
  const identity = activeRoleIdentity();
  if (!identity) return 'auth.login';
  return ROLE_ENTRY[identity].landingRouteName;
}
