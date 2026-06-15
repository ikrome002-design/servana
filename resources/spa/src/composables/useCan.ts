import { computed, type ComputedRef } from 'vue';
import { usePermissionStore } from '@/stores/permissionStore';

/**
 * Permission helpers for components (Plan §10.3). UX only — never a security
 * boundary; the API enforces authorization server-side.
 *
 * Usage:
 *   const { can } = useCan();
 *   <SvButton v-if="can('branches.create')" … />
 */
export function useCan(): {
  can: (permission: string) => boolean;
  canAny: (keys: string[]) => boolean;
  canAll: (keys: string[]) => boolean;
  permissions: ComputedRef<string[]>;
} {
  const store = usePermissionStore();

  return {
    can: (permission: string): boolean => store.can(permission),
    canAny: (keys: string[]): boolean => store.canAny(keys),
    canAll: (keys: string[]): boolean => store.canAll(keys),
    permissions: computed(() => store.permissions),
  };
}
