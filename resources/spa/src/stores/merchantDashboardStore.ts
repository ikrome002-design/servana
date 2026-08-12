import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

export interface MerchantDashboardOverview {
  subscription: null | {
    status: string;
    billing_status: string;
    billing_read_only: boolean;
    plan_name: string;
    billing_interval: string;
    amount_minor: number;
    currency: string;
    trial_ends_at: string;
    current_period_end: string;
    scheduled_change: boolean;
  };
  billing: {
    next_invoice: null | { id: string; invoice_number: string | null; status: string; balance_minor: number; currency: string; due_at: string | null };
    outstanding_by_currency: Array<{ currency: string; amount_minor: number }>;
    payment_runtime: { available: false; reason: string };
  };
  branches: { total: number; active: number; suspended: number; archived: number; limit: number | null; remaining_capacity: number | null };
  staff: { active: number; invited: number; suspended: number; deactivated: number; pending_owner_invitations: number };
  get_started: {
    setup_complete: boolean;
    subscription_selected: boolean;
    profile_complete: boolean;
    logo_uploaded: boolean;
    billing_phone_confirmed: boolean;
    first_branch_created: boolean;
    initial_team_invited: boolean;
    initial_team_active: boolean;
    operational_roles_active: boolean;
    daily_reports: { available: false; reason: string };
  };
  compensation: null | { pending_high_value_approvals: number; payout_runs_by_status: Record<string, number>; outstanding_liability_by_currency: Array<{ currency: string; combined_net_liability_minor: number }> };
  reporting: { available: false; reason: string; omitted_metrics: string[] };
}

export const useMerchantDashboardStore = defineStore('merchantDashboard', () => {
  const overview = ref<MerchantDashboardOverview | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function fetchOverview(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: { overview: MerchantDashboardOverview } }>('/merchant/dashboard');
      if (!isMerchantDashboardOverview(data?.data?.overview)) throw new Error('invalid_dashboard_shape');
      overview.value = data.data.overview;
    } catch {
      error.value = 'We couldn’t load the ownership overview.';
    } finally {
      loading.value = false;
    }
  }

  function $reset(): void {
    overview.value = null;
    loading.value = false;
    error.value = null;
  }

  return { overview, loading, error, fetchOverview, $reset };
});

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isMerchantDashboardOverview(value: unknown): value is MerchantDashboardOverview {
  if (!isRecord(value) || !isRecord(value.billing) || !isRecord(value.branches)
    || !isRecord(value.staff) || !isRecord(value.reporting) || !isRecord(value.get_started)) return false;
  if (!Array.isArray(value.billing.outstanding_by_currency)
    || !isRecord(value.billing.payment_runtime)
    || typeof value.billing.payment_runtime.reason !== 'string') return false;
  if (typeof value.branches.total !== 'number' || typeof value.branches.active !== 'number'
    || typeof value.staff.active !== 'number' || typeof value.staff.pending_owner_invitations !== 'number') return false;
  if (typeof value.reporting.available !== 'boolean' || typeof value.reporting.reason !== 'string'
    || !Array.isArray(value.reporting.omitted_metrics)) return false;
  return typeof value.get_started.setup_complete === 'boolean'
    && typeof value.get_started.daily_reports === 'object';
}
