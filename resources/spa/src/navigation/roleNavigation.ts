import type { RoleIdentity } from '@/types/roles';
import { ROLE_IDENTITIES } from '@/types/roles';
import { NAVIGATION_CONTRACT, type NavigationContractEntry } from './navigationRegistry.generated';

/**
 * Role navigation, DERIVED from the canonical authenticated page contract (Phase UI-07).
 *
 * Before UI-07 this file held eight hand-written arrays. They were a second, independent
 * statement of the same page metadata the navigation map already owned, and they drifted: labels
 * pointed at consolidated screens (`UI01-NAV-001`), planned entries rendered as inert disabled
 * links with no gate named (`UI01-NAV-003`), and twenty routes sat outside navigation with no
 * recorded reason (`UI01-ROUTE-005`). One authority now answers all of it:
 *
 *   docs/frontend/navigation/servana-user-account-navigation-map.yaml
 *     -> resources/spa/src/navigation/navigationRegistry.generated.ts
 *     -> this module
 *     -> docs/frontend/navigation/role-navigation.yaml (fixture, regenerated)
 *
 * Rules (binding, and unchanged in substance from Phase 11):
 *  - a `live` item points only at a real, registered route (`routeName` set);
 *  - a `planned` item is NOT rendered at all — UI/UX plan §7.2 forbids dead and fake links;
 *  - a `gated` item IS rendered, disabled, naming the exact external gate;
 *  - `permission` drives `PermissionGate` visibility only — the backend is the security
 *    boundary (Plan §9 rule 2, ADR-017);
 *  - forbidden capabilities are never listed for any role: Super-Admin merchant
 *    creation/operations, Merchant-Admin service/pricing/commission config, Branch-Manager
 *    payment validation/refunds/locks, HR cross-branch/finance, Front-Office payment
 *    validation/receipt issuance, Personnel contact export, Audit operational mutation.
 *    The contract records those exclusions in `forbiddenFor`.
 */
export type NavAvailability = 'live' | 'planned' | 'gated';

export interface NavItem {
  /** Stable contract key (used by tests and the YAML fixture). */
  key: string;
  /** Verbatim navigation label from the authoritative navigation map. */
  label: string;
  /** Resolved route name — present only for `live` items. */
  routeName?: string;
  /** Permission key gating UX visibility only — optional. */
  permission?: string;
  /** Owning phase that delivers (or delivered) this surface. */
  phase: string;
  availability: NavAvailability;
  /** The exact gate blocking a `gated` item. Never a vague "coming soon". */
  gate?: string;
}

function toNavItem(entry: NavigationContractEntry): NavItem {
  const gated = entry.implementationStatus === 'disabled_by_gate';
  const permission = entry.permissionAll[0] ?? entry.permissionAny[0];

  return {
    key: entry.key,
    label: entry.label,
    ...(gated ? {} : { routeName: entry.runtimeRouteName ?? undefined }),
    ...(permission === undefined ? {} : { permission }),
    phase: entry.backendOwnerPhase ?? entry.ownerPhase,
    availability: gated ? 'gated' : 'live',
    ...(gated && entry.gate !== null ? { gate: entry.gate } : {}),
  };
}

/**
 * Primary navigation for one account, in contract order.
 *
 * `planned` entries are absent: they have no runtime route, so rendering them would create the
 * dead link the plan forbids. Contextual children and record detail routes are absent too —
 * they are reached from their parent screen, and each carries a recorded `nonNavigationReason`.
 */
function primaryNavigationFor(identity: RoleIdentity): NavItem[] {
  return NAVIGATION_CONTRACT.filter(
    (entry) =>
      entry.accountType === identity &&
      entry.navigationVisibility === 'primary' &&
      entry.parentKey === null &&
      (entry.implementationStatus === 'implemented' || entry.implementationStatus === 'disabled_by_gate'),
  )
    .slice()
    .sort((a, b) => a.order - b.order)
    .map(toNavItem);
}

/** The canonical per-role navigation registry, derived from the contract. */
export const ROLE_NAVIGATION: Record<RoleIdentity, NavItem[]> = Object.fromEntries(
  ROLE_IDENTITIES.map((identity) => [identity, primaryNavigationFor(identity)]),
) as Record<RoleIdentity, NavItem[]>;

/** Navigation items for a role identity (empty array for an unknown identity). */
export function navigationFor(identity: RoleIdentity): NavItem[] {
  return ROLE_NAVIGATION[identity] ?? [];
}
