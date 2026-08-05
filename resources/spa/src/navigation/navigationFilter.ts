import type { RoleIdentity } from '@/types/roles';
import {
  NAVIGATION_CONTRACT,
  type NavigationContractEntry,
  type NavigationIconKey,
} from './navigationRegistry.generated';

/**
 * Deterministic runtime navigation filtering (Phase UI-07; UI/UX plan §7, §19.2, ADR-017).
 *
 * ONE filtered result feeds desktop navigation, the tablet rail and the mobile drawer, so the
 * three can never disagree about what a user may see. The Super Administrator renders that result
 * in the header and every other account renders it in the sidebar (ADR-018) — placement differs,
 * the result does not.
 *
 * ## This is discoverability, never authorization
 *
 * Hiding a link hides a link. The backend `auth:sanctum` + Form Request + Policy +
 * `EnsurePermission` chain, the tenant/branch/own-scope query scopes and the account host
 * resolution remain the security boundary. A user who types a URL is refused by the server, not
 * by this file.
 *
 * ## Filter order (fixed, and asserted by `navigationFilter.spec.ts`)
 *
 *  1. account ownership          — the entry belongs to this account
 *  2. removed_by_authority       — never rendered anywhere
 *  3. implementation status      — `planned` is never rendered: no dead links, no fake pages
 *  4. external gate              — `disabled_by_gate` renders DISABLED and names its gate
 *  5. feature flag               — an unset flag hides the entry
 *  6. forbidden account          — an explicit authority-boundary exclusion
 *  7. permissions                — `permission_all` AND `permission_any`, fail-closed
 *  8. navigation visibility      — contextual children and detail routes are not primary links
 *  9. parent pruning             — a group with nothing left to show disappears
 * 10. stable ordering            — by the contract's own integer order, never by display text
 */

export interface NavigationContext {
  /** Permission keys the user actually holds, as reported by the server bootstrap. */
  readonly permissions: ReadonlySet<string> | readonly string[];
  /** Feature flags currently enabled. An entry naming an absent flag stays hidden. */
  readonly featureFlags?: ReadonlySet<string> | readonly string[];
  /** External gates that are open. A closed gate leaves its entries disabled. */
  readonly openGates?: ReadonlySet<string> | readonly string[];
}

export interface NavigationNode {
  readonly key: string;
  readonly label: string;
  readonly description: string;
  readonly group: string;
  readonly order: number;
  readonly icon: NavigationIconKey;
  /** The route to navigate to. `null` whenever the entry must not be clickable. */
  readonly routeName: string | null;
  /** True for a gate-blocked entry: visible, named, and deliberately inert. */
  readonly disabled: boolean;
  /** The exact gate to show the user. Never a vague "coming soon". */
  readonly disabledReason: string | null;
  readonly children: readonly NavigationNode[];
}

const asSet = (value: ReadonlySet<string> | readonly string[] | undefined): ReadonlySet<string> =>
  value === undefined ? new Set<string>() : value instanceof Set ? value : new Set(value);

const GATE_LABELS: Readonly<Record<string, string>> = {
  external_gate_w: 'External Gate W — Wallet by Citrus collections readiness',
};

function permitted(entry: NavigationContractEntry, held: ReadonlySet<string>): boolean {
  // Fail-closed on both groups. `permission_all` requires every key; `permission_any` requires at
  // least one. When both are present both must pass — they are never merged into one loose
  // "holds some permission" check.
  if (entry.permissionAll.length > 0 && !entry.permissionAll.every((key) => held.has(key))) {
    return false;
  }
  if (entry.permissionAny.length > 0 && !entry.permissionAny.some((key) => held.has(key))) {
    return false;
  }
  return true;
}

function eligible(
  entry: NavigationContractEntry,
  account: RoleIdentity,
  context: NavigationContext,
): boolean {
  if (entry.accountType !== account) return false;
  if (entry.forbiddenFor.includes(account)) return false;
  if (entry.implementationStatus === 'removed_by_authority') return false;
  if (entry.implementationStatus === 'planned') return false;
  if (entry.featureFlag !== null && !asSet(context.featureFlags).has(entry.featureFlag)) return false;
  return permitted(entry, asSet(context.permissions));
}

function toNode(entry: NavigationContractEntry, children: readonly NavigationNode[]): NavigationNode {
  const gateClosed =
    entry.implementationStatus === 'disabled_by_gate' && entry.gate !== null;

  return {
    key: entry.key,
    label: entry.label,
    description: entry.description,
    group: entry.navigationGroup,
    order: entry.order,
    icon: entry.icon,
    // A gate-blocked entry must not navigate anywhere. It is visible because the authoritative
    // map lists it, and it says exactly why it cannot be used.
    routeName: gateClosed ? null : entry.runtimeRouteName,
    disabled: gateClosed,
    disabledReason: gateClosed ? (GATE_LABELS[entry.gate!] ?? entry.gate) : null,
    children,
  };
}

/**
 * The navigation tree for one account, already filtered. Desktop, tablet and mobile all render
 * this same value.
 */
export function navigationTree(
  account: RoleIdentity,
  context: NavigationContext,
  contract: readonly NavigationContractEntry[] = NAVIGATION_CONTRACT,
): readonly NavigationNode[] {
  const allowed = contract.filter((entry) => eligible(entry, account, context));

  const childrenOf = (parentKey: string): readonly NavigationNode[] =>
    allowed
      .filter((entry) => entry.parentKey === parentKey)
      .sort((a, b) => a.order - b.order)
      .map((entry) => toNode(entry, childrenOf(entry.key)));

  return allowed
    .filter((entry) => {
      // A contextual child whose parent was filtered out must not surface as an orphan at the
      // root; it stays reachable from its parent screen or not at all.
      if (entry.parentKey !== null) return false;
      // Detail and contextual routes are reached from their parent screen, never as a primary
      // link — the contract records the reason on the entry itself.
      return entry.navigationVisibility === 'primary';
    })
    .sort((a, b) => a.order - b.order)
    .map((entry) => toNode(entry, childrenOf(entry.key)))
    // A group that ended up with no destination and no visible child is not a navigation item.
    .filter((node) => node.routeName !== null || node.disabled || node.children.length > 0);
}

/** Flattened, in render order — what a parity test compares against the rendered DOM. */
export function flattenNavigation(nodes: readonly NavigationNode[]): readonly NavigationNode[] {
  return nodes.flatMap((node) => [node, ...flattenNavigation(node.children)]);
}

/** Orphan check used by the contract tests: no allowed child may lose its parent silently. */
export function orphanedChildren(
  account: RoleIdentity,
  context: NavigationContext,
  contract: readonly NavigationContractEntry[] = NAVIGATION_CONTRACT,
): readonly string[] {
  const allowed = contract.filter((entry) => eligible(entry, account, context));
  const allowedKeys = new Set(allowed.map((entry) => entry.key));
  return allowed
    .filter((entry) => entry.parentKey !== null && !allowedKeys.has(entry.parentKey))
    .map((entry) => entry.key);
}
