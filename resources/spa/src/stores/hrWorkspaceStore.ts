import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

export interface HrWorkspaceOverview {
  branch: { id: string; name: string; code: string; town: string | null };
  staff: {
    total: number;
    active: number;
    by_access_status: Record<string, number>;
    pending_invitations: number;
  };
  readiness: {
    eligible_staff: number;
    without_eligibility: number;
    available_staff: number;
    without_availability: number;
    configured_compensation: number;
    without_compensation: number;
  };
  compensation: { by_status: Record<string, number>; drafts_requiring_action: number };
  payouts: { by_status: Record<string, number>; awaiting_finance: number };
  tasks: Array<{ key: string; label: string; count: number; route_name: string }>;
  get_started: {
    staff_invited: boolean;
    eligibility_configured: boolean;
    availability_configured: boolean;
    compensation_configured: boolean;
    missing_compensation_reviewed: boolean;
  };
  reports: { available: false; reason: string };
  notifications: { available: false; reason: string };
}

export interface HrAuditActivity {
  id: string;
  action: string;
  severity: string;
  actor: string | null;
  branch?: string | null;
  subject_type: string | null;
  context: Record<string, unknown>;
  correlation_id?: string | null;
  created_at: string | null;
}

interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export const useHrWorkspaceStore = defineStore('hrWorkspace', () => {
  const overview = ref<HrWorkspaceOverview | null>(null);
  const auditEvents = ref<HrAuditActivity[]>([]);
  const auditMeta = ref<PageMeta | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function fetchOverview(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: { overview: HrWorkspaceOverview } }>('/hr/workspace');
      overview.value = data.data.overview;
    } catch {
      error.value = 'We couldn’t load the Human Resource workspace.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchAudit(params: Record<string, string | number> = {}): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: HrAuditActivity[]; meta: PageMeta }>(
        '/hr/audit-activity',
        { params },
      );
      auditEvents.value = data.data;
      auditMeta.value = data.meta;
    } catch {
      error.value = 'We couldn’t load HR audit activity.';
    } finally {
      loading.value = false;
    }
  }

  function $reset(): void {
    overview.value = null;
    auditEvents.value = [];
    auditMeta.value = null;
    loading.value = false;
    error.value = null;
  }

  return { overview, auditEvents, auditMeta, loading, error, fetchOverview, fetchAudit, $reset };
});
