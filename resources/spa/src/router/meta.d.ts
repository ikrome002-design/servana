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
  }
}
