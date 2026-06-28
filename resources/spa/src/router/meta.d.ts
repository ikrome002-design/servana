import 'vue-router';
import type { RoleIdentity } from '@/types/roles';

// Role-area routes carry their content/layout identity so the shared landing and
// get-started pages render the correct role's verbatim content (Phase 11).
declare module 'vue-router' {
  interface RouteMeta {
    roleIdentity?: RoleIdentity;
  }
}
