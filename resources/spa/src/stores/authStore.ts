import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { Membership, User } from '@/types/models';

// Phase 4: typed structure only. Real /me bootstrap lands in Phase 5.
export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null);
  const memberships = ref<Membership[]>([]);
  const activeMembership = ref<Membership | null>(null);
  const bootstrapped = ref(false);

  function $reset(): void {
    user.value = null;
    memberships.value = [];
    activeMembership.value = null;
    bootstrapped.value = false;
  }

  const isAuthenticated = (): boolean => user.value !== null;

  return { user, memberships, activeMembership, bootstrapped, isAuthenticated, $reset };
});
