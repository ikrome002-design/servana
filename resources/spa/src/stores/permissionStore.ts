import { defineStore } from 'pinia';
import { computed } from 'vue';
import { useAuthStore } from '@/stores/authStore';

/**
 * Resolved permission keys (Plan §10.3), sourced from the /me bootstrap held in
 * authStore — a single source of truth. These checks are UX ONLY; the backend
 * (EnsurePermission + policies) is the security boundary.
 */
export const usePermissionStore = defineStore('permission', () => {
  const auth = useAuthStore();

  const permissions = computed<string[]>(() => auth.permissions);

  /** True when the current user holds the given permission key. */
  function can(permission: string): boolean {
    return permissions.value.includes(permission);
  }

  /** True when the user holds at least one of the given keys. */
  function canAny(keys: string[]): boolean {
    return keys.some((key) => permissions.value.includes(key));
  }

  /** True when the user holds every one of the given keys. */
  function canAll(keys: string[]): boolean {
    return keys.every((key) => permissions.value.includes(key));
  }

  return { permissions, can, canAny, canAll };
});
