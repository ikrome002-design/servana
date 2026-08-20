import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

export interface FrontOfficeBranchContext {
  id: string;
  name: string;
  code: string;
  town: string | null;
}

export interface FrontOfficeWorkspaceOverview {
  observed_at: string;
  business_date: string;
  branch: FrontOfficeBranchContext;
  appointments: { today: number; by_status: Record<string, number>; arrivals: number };
  queue: {
    active: number;
    waiting: number;
    in_service: number;
    by_status: Record<string, number>;
    longest_estimated_wait_minutes: number;
  };
  sessions: { today: number; in_progress: number; completed: number; by_status: Record<string, number> };
  invoices: { drafts: number; awaiting_payment: number; by_status: Record<string, number> };
  payments: { pending_validation: number; by_status: Record<string, number>; receipts_ready_today: number };
  tasks: Array<{ key: string; label: string; count: number; route_name: string }>;
  get_started: Record<string, boolean>;
  subscription: { available: false; reason: string };
  notifications: { available: false; reason: string };
}

export interface FrontOfficeActivity {
  id: string;
  domain: 'clients' | 'appointments' | 'queue' | 'sessions' | 'invoices' | 'billing';
  action: string;
  label: string;
  occurred_at: string | null;
}

export interface FrontOfficePaymentStatus {
  id: string;
  status: string;
  total: { amount: number; currency: string; formatted: string };
  recorded_at: string | null;
  submitted_for_validation_at: string | null;
  invoice: { id: string; number: string | null; status: string };
  receipt: { ready: boolean; id: string | null; number: number | null };
}

export interface FrontOfficePageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

type ActivityFilters = { domain?: string; page?: number };
type PaymentFilters = { status?: string; page?: number };

let overviewRequest: Promise<void> | null = null;

export const useFrontOfficeWorkspaceStore = defineStore('frontOfficeWorkspace', () => {
  const overview = ref<FrontOfficeWorkspaceOverview | null>(null);
  const activity = ref<FrontOfficeActivity[]>([]);
  const paymentStatuses = ref<FrontOfficePaymentStatus[]>([]);
  const activityMeta = ref<FrontOfficePageMeta | null>(null);
  const paymentMeta = ref<FrontOfficePageMeta | null>(null);
  const overviewLoading = ref(false);
  const activityLoading = ref(false);
  const paymentLoading = ref(false);
  const overviewError = ref<string | null>(null);
  const activityError = ref<string | null>(null);
  const paymentError = ref<string | null>(null);

  function $reset(): void {
    overview.value = null;
    activity.value = [];
    paymentStatuses.value = [];
    activityMeta.value = null;
    paymentMeta.value = null;
    overviewLoading.value = false;
    activityLoading.value = false;
    paymentLoading.value = false;
    overviewError.value = null;
    activityError.value = null;
    paymentError.value = null;
    overviewRequest = null;
  }

  async function fetchOverview(force = false): Promise<void> {
    if (overviewRequest !== null && !force) return overviewRequest;
    overviewLoading.value = true;
    overviewError.value = null;
    overviewRequest = apiClient
      .get<{ data: { overview: FrontOfficeWorkspaceOverview } }>('/front-office/workspace')
      .then(({ data }) => { overview.value = data.data.overview; })
      .catch(() => { overviewError.value = 'Unable to load the branch workspace.'; })
      .finally(() => {
        overviewLoading.value = false;
        overviewRequest = null;
      });
    return overviewRequest;
  }

  async function fetchActivity(filters: ActivityFilters = {}): Promise<void> {
    activityLoading.value = true;
    activityError.value = null;
    const params: Record<string, string | number> = { sort: '-created_at', per_page: 20 };
    if (filters.domain) params.domain = filters.domain;
    if (filters.page) params.page = filters.page;
    try {
      const { data } = await apiClient.get<{ data: FrontOfficeActivity[]; meta: FrontOfficePageMeta }>(
        '/front-office/activity',
        { params },
      );
      activity.value = data.data;
      activityMeta.value = data.meta;
    } catch {
      activityError.value = 'Unable to load today’s activity.';
    } finally {
      activityLoading.value = false;
    }
  }

  async function fetchPaymentStatus(filters: PaymentFilters = {}): Promise<void> {
    paymentLoading.value = true;
    paymentError.value = null;
    const params: Record<string, string | number> = { sort: '-recorded_at', per_page: 20 };
    if (filters.status) params.status = filters.status;
    if (filters.page) params.page = filters.page;
    try {
      const { data } = await apiClient.get<{ data: FrontOfficePaymentStatus[]; meta: FrontOfficePageMeta }>(
        '/front-office/payment-status',
        { params },
      );
      paymentStatuses.value = data.data;
      paymentMeta.value = data.meta;
    } catch {
      paymentError.value = 'Unable to load payment and receipt status.';
    } finally {
      paymentLoading.value = false;
    }
  }

  return {
    overview,
    activity,
    paymentStatuses,
    activityMeta,
    paymentMeta,
    overviewLoading,
    activityLoading,
    paymentLoading,
    overviewError,
    activityError,
    paymentError,
    fetchOverview,
    fetchActivity,
    fetchPaymentStatus,
    $reset,
  };
});
