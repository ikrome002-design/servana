import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

export interface MerchantStaffOverviewRow {
  id: string;
  staff_profile_id: string | null;
  display_name: string;
  email: string;
  role: string;
  status: string;
  account_status: string;
  activated_at: string | null;
  last_login_at: string | null;
  branches: Array<{ id: string; name: string; code: string }>;
  active_session_count: number;
  assignment_history: Array<{ branch: string; status: string; assigned_at: string | null; revoked_at: string | null }>;
  status_history: Array<{ field: string; from: unknown; to: unknown; changed_at: string | null }>;
  can: { manage_lifecycle: boolean };
}

export const useMerchantStaffOverviewStore = defineStore('merchantStaffOverview', () => {
  const rows = ref<MerchantStaffOverviewRow[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const total = ref(0);
  const page = ref(1);
  const lastPage = ref(1);
  const mutating = ref<string | null>(null);

  async function fetchRows(filters: Record<string, string | number | undefined> = {}): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const query = new URLSearchParams();
      for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== '') query.set(key, String(value));
      }
      const suffix = query.size > 0 ? `?${query.toString()}` : '';
      const { data } = await apiClient.get<{ data: MerchantStaffOverviewRow[]; meta: { total: number; current_page: number; last_page: number } }>(`/merchant/staff-overview${suffix}`);
      if (!isMerchantStaffResponse(data)) throw new Error('invalid_staff_overview_shape');
      rows.value = data.data;
      total.value = data.meta.total;
      page.value = data.meta.current_page;
      lastPage.value = data.meta.last_page;
    } catch {
      error.value = 'We couldn’t load the staff lifecycle directory.';
    } finally {
      loading.value = false;
    }
  }

  async function lifecycle(row: MerchantStaffOverviewRow, action: 'suspend' | 'activate' | 'deactivate', reason?: string): Promise<void> {
    if (row.staff_profile_id === null) return;
    mutating.value = row.id;
    try {
      await apiClient.post(`/staff/${row.staff_profile_id}/${action}`, reason ? { reason } : {});
    } finally {
      mutating.value = null;
    }
  }

  return { rows, loading, error, total, page, lastPage, mutating, fetchRows, lifecycle };
});

function isMerchantStaffResponse(value: unknown): value is {
  data: MerchantStaffOverviewRow[];
  meta: { total: number; current_page: number; last_page: number };
} {
  if (value === null || typeof value !== 'object') return false;
  const candidate = value as { data?: unknown; meta?: unknown };
  if (!Array.isArray(candidate.data) || candidate.meta === null || typeof candidate.meta !== 'object') return false;
  const meta = candidate.meta as Record<string, unknown>;
  if (![meta.total, meta.current_page, meta.last_page].every((part) => typeof part === 'number')) return false;
  return candidate.data.every((row: unknown) => {
    if (row === null || typeof row !== 'object') return false;
    const item = row as Record<string, unknown>;
    return typeof item.id === 'string' && typeof item.display_name === 'string'
      && typeof item.email === 'string' && typeof item.role === 'string'
      && typeof item.status === 'string' && typeof item.active_session_count === 'number'
      && Array.isArray(item.branches) && Array.isArray(item.assignment_history)
      && Array.isArray(item.status_history) && item.can !== null && typeof item.can === 'object';
  });
}
