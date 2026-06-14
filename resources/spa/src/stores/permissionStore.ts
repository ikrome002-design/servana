import { defineStore } from 'pinia';
import { ref } from 'vue';

// Phase 4: typed structure only. Real permission registry lands in Phase 8.
export const usePermissionStore = defineStore('permission', () => {
  const permissions = ref<string[]>([]);

  function can(permission: string): boolean {
    return permissions.value.includes(permission);
  }

  function $reset(): void {
    permissions.value = [];
  }

  return { permissions, can, $reset };
});
