import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { Branch, BranchOperatingHour } from '@/types/models';

/**
 * Branch directory + CRUD + operating hours (Scope §3.3, Plan §27 Phase 7).
 * UX state only — the API (EnsureBranchScope + admin authority) is the boundary.
 */
export const useBranchStore = defineStore('branch', () => {
  const branches = ref<Branch[]>([]);
  const activeBranch = ref<Branch | null>(null);
  const operatingHours = ref<BranchOperatingHour[]>([]);
  const loading = ref(false);

  function $reset(): void {
    branches.value = [];
    activeBranch.value = null;
    operatingHours.value = [];
    loading.value = false;
  }

  async function fetchBranches(): Promise<void> {
    loading.value = true;
    try {
      const { data } = await apiClient.get<{ data: Branch[] }>('/branches');
      branches.value = data.data;
    } finally {
      loading.value = false;
    }
  }

  async function createBranch(payload: Partial<Branch>): Promise<Branch> {
    const { data } = await apiClient.post<{ data: Branch }>('/branches', payload);
    branches.value = [...branches.value, data.data];
    return data.data;
  }

  async function fetchBranch(id: string): Promise<Branch> {
    const { data } = await apiClient.get<{ data: Branch }>(`/branches/${id}`);
    activeBranch.value = data.data;
    return data.data;
  }

  async function fetchOperatingHours(id: string): Promise<void> {
    const { data } = await apiClient.get<{ data: BranchOperatingHour[] }>(
      `/branches/${id}/operating-hours`,
    );
    operatingHours.value = data.data;
  }

  async function saveOperatingHours(id: string, hours: BranchOperatingHour[]): Promise<void> {
    const { data } = await apiClient.put<{ data: BranchOperatingHour[] }>(
      `/branches/${id}/operating-hours`,
      { hours },
    );
    operatingHours.value = data.data;
  }

  return {
    branches,
    activeBranch,
    operatingHours,
    loading,
    fetchBranches,
    createBranch,
    fetchBranch,
    fetchOperatingHours,
    saveOperatingHours,
    $reset,
  };
});
