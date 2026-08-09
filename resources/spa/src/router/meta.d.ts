import 'vue-router';
import type { RoleIdentity } from '@/types/roles';

// Role-area routes carry their content/layout identity so the shared landing and
// get-started pages render the correct role's verbatim content (Phase 11).
//
// Phase UI-07 adds `accountKey`: the account a route tree belongs to, declared on the tree root
// and inherited by its children. It exists so the account requirement is READABLE FROM THE
// ROUTER. `requiresAccount(...)` returns an anonymous closure, so a coverage test that inspected
// `beforeEnter` could only see an unnamed function and would pass vacuously on an unguarded
// tree. The requirement is never INFERRED from a URL prefix, a host string, a navigation label
// or a role name in local storage — and it is never authorization: the backend policy,
// permission middleware and tenant/branch/own scopes remain the boundary (ADR-017).
declare module 'vue-router' {
  interface RouteMeta {
    roleIdentity?: RoleIdentity;
    accountKey?: RoleIdentity;
    /**
     * Phase UI-08 Increment 7B: the contract screen this runtime route renders, so the UI-07
     * generator can pin route → contract page from the router itself.
     *
     * Only the screen key lives here. Navigation group, title, permissions, MFA and step-up are
     * NOT copied into route meta — they belong to the canonical navigation map and reach the
     * runtime through the generated registry. A second copy in the router would be a second
     * authority that could silently disagree with the contract.
     *
     * `null` marks a real route that is deliberately not a contract page (the role landing).
     */
    screenKey?: string | null;
  }
}
