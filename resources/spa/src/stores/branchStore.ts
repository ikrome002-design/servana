import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { Branch } from '@/types/models';

// Phase 4: typed structure only. Branch switching lands in Phase 7.
export const useBranchStore = defineStore('branch', () => {
  const activeBranch = ref<Branch | null>(null);
  const branches = ref<Branch[]>([]);

  function $reset(): void {
    activeBranch.value = null;
    branches.value = [];
  }

  return { activeBranch, branches, $reset };
});
