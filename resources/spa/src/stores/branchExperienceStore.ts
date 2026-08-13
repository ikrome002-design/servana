import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { Branch } from '@/types/models';

export interface BranchOverview {
  branch: Branch;
  business_date: string;
  day: {
    id: string | null;
    status: string;
    opened_at: string | null;
    closed_at: string | null;
    queue_is_open: boolean;
    close_blockers: string[];
    financial_close_blockers: string[];
  };
  services: { total: number; active: number; archived: number };
  staff: { active: number };
  queue: { total: number; active: number; by_status: Record<string, number> };
  appointments: { today: number; active_today: number; by_status: Record<string, number> };
  financial: {
    invoices_total: number;
    invoices_by_status: Record<string, number>;
    invoices_with_balance: number;
    pending_payment_validations: number;
    receipts_issued_today: number;
    validated_revenue_by_currency: Array<{ currency: string; amount_minor: number }>;
  };
  cash_up: null | {
    id: string;
    status: string;
    currency: string;
    expected_minor: number;
    counted_minor: number;
    variance_minor: number;
  };
  billing: {
    status: string;
    next_invoice: null | {
      id: string;
      invoice_number: string | null;
      status: string;
      balance_minor: number;
      currency: string;
      due_at: string | null;
    };
    payment_runtime: { available: false; reason: string };
  };
  reporting: { available: false; reason: string };
  notifications: { available: false; reason: string };
  get_started: {
    profile_complete: boolean;
    calendar_configured: boolean;
    service_catalogue_ready: boolean;
    staff_ready: boolean;
    day_opened: boolean;
    cash_up_prepared: boolean;
    reports: { available: false; reason: string };
  };
}

export interface BranchInvoiceVisibility {
  id: string;
  invoice_number: string | null;
  status: string;
  total_minor: number;
  validated_paid_minor: number;
  balance_minor: number;
  currency: string;
  finalized_at: string | null;
  created_at: string | null;
  can: Record<string, false>;
}

export interface BranchPaymentVisibility {
  id: string;
  invoice?: { id: string; invoice_number: string | null };
  status: string;
  total_amount_minor: number;
  currency: string;
  recorded_at: string | null;
  submitted_for_validation_at: string | null;
  validated_at: string | null;
  created_at: string | null;
  can: Record<string, false>;
}

export interface BranchAuditVisibility {
  id: string;
  action: string;
  severity: string;
  actor: string | null;
  subject_type: string | null;
  context: Record<string, unknown>;
  created_at: string | null;
}

interface PageMeta { current_page: number; last_page: number; per_page: number; total: number }

export const useBranchExperienceStore = defineStore('branchExperience', () => {
  const branchId = ref<string | null>(null);
  const overview = ref<BranchOverview | null>(null);
  const invoices = ref<BranchInvoiceVisibility[]>([]);
  const payments = ref<BranchPaymentVisibility[]>([]);
  const auditEvents = ref<BranchAuditVisibility[]>([]);
  const meta = ref<PageMeta | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function resolveBranch(): Promise<string> {
    if (branchId.value !== null) return branchId.value;
    const { data } = await apiClient.get<{ data: Branch[] }>('/branches', { params: { per_page: 100 } });
    const branch = data.data[0];
    if (!branch) throw new Error('no_assigned_branch');
    branchId.value = branch.id;
    return branch.id;
  }

  async function fetchOverview(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const id = await resolveBranch();
      const { data } = await apiClient.get<{ data: { overview: BranchOverview } }>(`/branches/${id}/dashboard`);
      overview.value = data.data.overview;
    } catch {
      error.value = 'We couldn’t load this branch workspace.';
    } finally {
      loading.value = false;
    }
  }

  async function updateProfile(payload: Partial<Branch>): Promise<void> {
    const id = await resolveBranch();
    const { data } = await apiClient.patch<{ data: Branch }>(`/branches/${id}`, payload);
    if (overview.value) overview.value.branch = data.data;
  }

  async function transitionDay(action: 'open' | 'close'): Promise<void> {
    const id = await resolveBranch();
    await apiClient.post(`/branches/${id}/day/${action}`, {});
    await fetchOverview();
  }

  async function fetchInvoices(params: Record<string, string | number> = {}): Promise<void> {
    await fetchCollection<BranchInvoiceVisibility>('invoices', params, (rows, page) => {
      invoices.value = rows;
      meta.value = page;
    });
  }

  async function fetchPayments(params: Record<string, string | number> = {}): Promise<void> {
    await fetchCollection<BranchPaymentVisibility>('payment-records', params, (rows, page) => {
      payments.value = rows;
      meta.value = page;
    });
  }

  async function fetchAudit(params: Record<string, string | number> = {}): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const id = await resolveBranch();
      const { data } = await apiClient.get<{ data: BranchAuditVisibility[]; meta: PageMeta }>(
        `/branches/${id}/audit-events`, { params },
      );
      auditEvents.value = data.data;
      meta.value = data.meta;
    } catch {
      error.value = 'We couldn’t load the branch audit timeline.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchCollection<T>(
    segment: string,
    params: Record<string, string | number>,
    apply: (rows: T[], page: PageMeta) => void,
  ): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const id = await resolveBranch();
      const { data } = await apiClient.get<{ data: T[]; meta: PageMeta }>(
        `/branches/${id}/financial-visibility/${segment}`, { params },
      );
      apply(data.data, data.meta);
    } catch {
      error.value = 'We couldn’t load this branch financial view.';
    } finally {
      loading.value = false;
    }
  }

  function $reset(): void {
    branchId.value = null;
    overview.value = null;
    invoices.value = [];
    payments.value = [];
    auditEvents.value = [];
    meta.value = null;
    loading.value = false;
    error.value = null;
  }

  return {
    branchId, overview, invoices, payments, auditEvents, meta, loading, error,
    resolveBranch, fetchOverview, updateProfile, transitionDay, fetchInvoices, fetchPayments, fetchAudit, $reset,
  };
});
