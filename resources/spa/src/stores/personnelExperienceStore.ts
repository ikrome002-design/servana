import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

export interface PersonnelPageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface PersonnelWorkspace {
  observed_at: string;
  business_date: string;
  staff: {
    id: string;
    display_name: string;
    role_title: string | null;
    branch: { id: string; name: string; code: string } | null;
  };
  next_assignment: {
    kind: 'session' | 'queue' | 'appointment';
    id: string;
    status: string;
    title: string;
    client_name: string | null;
    phone_masked: string | null;
    at: string | null;
    route_name: string;
    position?: number;
    estimated_wait_minutes?: number;
  } | null;
  queue: { active: number; next_position: number | null; estimated_wait_minutes: number | null };
  appointments: { today: number; upcoming: number; next_at: string | null };
  sessions: { active: number; completed_today: number };
  preferred_requests: { active: number };
  clients: { served: number; can_compose_sms: boolean; recent_messages: number };
  availability: PersonnelAvailability;
  earnings: {
    tab_visibility: PersonnelTabVisibility;
    currencies: PersonnelEarningsCurrency[];
    terms: PersonnelCompensationTerms;
    latest_payout: {
      id: string;
      currency: string;
      gross_amount_minor: number;
      status: string;
      period_start: string | null;
      period_end: string | null;
    } | null;
    unresolved_queries: number;
  };
}

export interface PersonnelTabVisibility {
  model: 'commission_only' | 'salary_only' | 'salary_plus_commission' | null;
  has_current_plan: boolean;
  conflicting: boolean;
  salary_tab: boolean;
  commission_tab: boolean;
}

export interface PersonnelEarningsCurrency {
  currency: string;
  salary_unpaid_minor: number;
  salary_paid_minor: number;
  commission_unpaid_minor: number;
  commission_paid_minor: number;
  adjustment_unpaid_minor: number;
  adjustment_paid_minor: number;
  unpaid_minor: number;
  paid_minor: number;
  net_minor: number;
}

export interface PersonnelCompensationTerms {
  plan_id?: string;
  has_current_plan: boolean;
  conflicting: boolean;
  status?: string;
  compensation_model?: PersonnelTabVisibility['model'];
  salary_amount_minor?: number | null;
  salary_currency?: string | null;
  salary_period?: string | null;
  salary_payout_day?: number | null;
  effective_from?: string;
  effective_to?: string | null;
  suspension_salary_policy?: string;
  commission_rule?: {
    calculation_type: string;
    percentage_basis_points: number | null;
    fixed_amount_minor: number | null;
    currency: string | null;
    calculation_basis: string;
    applies_to: string;
    service_category: { id: string; name: string } | null;
    selected_services: Array<{ id: string; name: string }>;
    applies_to_preferred_personnel_fee: boolean;
    effective_from: string;
    effective_to: string | null;
  } | null;
}

export interface PersonnelHistoryRecord {
  id: string;
  status: string;
  started_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  duration_minutes: number | null;
  preferred_personnel_honored: boolean;
  commission: { id: string | null; status: string | null; explanation: string };
  service: { id: string; name: string } | null;
  client: { id: string; full_name: string; phone_masked: string } | null;
}

export interface PersonnelHistorySummary {
  records: number;
  completed: number;
  cancelled: number;
  clients_served: number;
  preferred_requests_honored: number;
}

export interface PersonnelPreferredRequest {
  source: 'queue' | 'appointment';
  id: string;
  status: string;
  requested_at: string;
  position: number | null;
  assigned_to_you: boolean;
  assignment_label: string;
  service: { id: string; name: string };
  client: { id: string; full_name: string; phone_masked: string };
}

export interface PersonnelServedClient {
  id: string;
  full_name: string;
  phone_masked: string;
  last_served_at: string | null;
  visit_count: number;
  services: Array<{ id: string; name: string }>;
  sms_consent: string;
  sms_eligible: boolean;
}

export interface PersonnelCommissionEntry {
  id: string;
  entry_type: string;
  status: string;
  amount_minor: number;
  currency: string;
  calculation_basis_minor: number;
  rate_basis_points: number | null;
  fixed_rate_minor: number | null;
  reversal_reason: string | null;
  earned_at: string | null;
  created_at: string | null;
  invoice: { id: string; number: string | null } | null;
  service: { id: string; name: string } | null;
  session_id: string | null;
  client: { id: string; full_name: string; phone_masked: string } | null;
}

export interface PersonnelSalaryEntry {
  id: string;
  entry_type: string;
  status: string;
  pay_period_start: string;
  pay_period_end: string;
  amount_minor: number;
  currency: string;
  created_at: string | null;
}

export interface PersonnelAvailability {
  staff: { id: string; display_name: string; employment_status: string; is_active: boolean };
  timezone: string;
  current_state: string;
  recurring: Array<{ weekday: number; start_time: string; end_time: string; available: boolean }>;
  exceptions: Array<{ date: string | null; start_time: string; end_time: string; available: boolean }>;
  eligible_services: Array<{ id: string; name: string }>;
  can: { update: boolean };
}

interface Paginated<T> {
  data: T[];
  meta: PersonnelPageMeta;
  summary?: PersonnelHistorySummary;
}

const EMPTY_META: PersonnelPageMeta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };

export const usePersonnelExperienceStore = defineStore('personnelExperience', () => {
  const workspace = ref<PersonnelWorkspace | null>(null);
  const history = ref<PersonnelHistoryRecord[]>([]);
  const historySummary = ref<PersonnelHistorySummary | null>(null);
  const preferredRequests = ref<PersonnelPreferredRequest[]>([]);
  const clients = ref<PersonnelServedClient[]>([]);
  const commissions = ref<PersonnelCommissionEntry[]>([]);
  const salary = ref<PersonnelSalaryEntry[]>([]);
  const availability = ref<PersonnelAvailability | null>(null);
  const meta = ref<Record<string, PersonnelPageMeta>>({});
  const loading = ref<Record<string, boolean>>({});
  const errors = ref<Record<string, string | null>>({});

  function $reset(): void {
    workspace.value = null;
    history.value = [];
    historySummary.value = null;
    preferredRequests.value = [];
    clients.value = [];
    commissions.value = [];
    salary.value = [];
    availability.value = null;
    meta.value = {};
    loading.value = {};
    errors.value = {};
  }

  async function load<T>(key: string, request: () => Promise<T>, apply: (value: T) => void, message: string): Promise<void> {
    loading.value[key] = true;
    errors.value[key] = null;
    try {
      apply(await request());
    } catch {
      errors.value[key] = message;
    } finally {
      loading.value[key] = false;
    }
  }

  async function fetchWorkspace(force = false): Promise<void> {
    if (workspace.value !== null && !force) return;
    await load('workspace', async () => {
      const payload = (await apiClient.get<{ data: PersonnelWorkspace }>('/personnel/me/workspace')).data?.data;
      // Reject a payload without the contract's shape so the page shows its retryable error state.
      if (!payload || typeof payload !== 'object' || !('staff' in payload) || !('earnings' in payload)) throw new Error('Malformed workspace payload');
      return payload;
    }, (data) => { workspace.value = data; }, 'Unable to load your workspace.');
  }

  async function fetchHistory(params: Record<string, string | number> = {}): Promise<void> {
    await load('history', async () => (await apiClient.get<Paginated<PersonnelHistoryRecord>>('/personnel/me/service-history', { params: { sort: '-completed_at', per_page: 20, ...params } })).data,
      (payload) => { history.value = payload.data; historySummary.value = payload.summary ?? null; meta.value.history = payload.meta ?? { ...EMPTY_META }; }, 'Unable to load your service history.');
  }

  async function fetchPreferredRequests(params: Record<string, string | number> = {}): Promise<void> {
    await load('preferred', async () => (await apiClient.get<Paginated<PersonnelPreferredRequest>>('/personnel/me/preferred-requests', { params: { per_page: 20, ...params } })).data,
      (payload) => { preferredRequests.value = payload.data; meta.value.preferred = payload.meta ?? { ...EMPTY_META }; }, 'Unable to load your preferred requests.');
  }

  async function fetchClients(params: Record<string, string | number> = {}): Promise<void> {
    await load('clients', async () => (await apiClient.get<Paginated<PersonnelServedClient>>('/personnel/me/served-clients', { params: { sort: 'full_name', per_page: 20, ...params } })).data,
      (payload) => { clients.value = payload.data; meta.value.clients = payload.meta ?? { ...EMPTY_META }; }, 'Unable to load your served clients.');
  }

  async function fetchCommissions(params: Record<string, string | number> = {}): Promise<void> {
    await load('commissions', async () => (await apiClient.get<Paginated<PersonnelCommissionEntry>>('/personnel/me/commissions', { params: { sort: '-earned_at', per_page: 20, ...params } })).data,
      (payload) => { commissions.value = payload.data; meta.value.commissions = payload.meta ?? { ...EMPTY_META }; }, 'Unable to load your commission entries.');
  }

  async function fetchSalary(params: Record<string, string | number> = {}): Promise<void> {
    await load('salary', async () => (await apiClient.get<Paginated<PersonnelSalaryEntry>>('/personnel/me/salary', { params: { sort: '-pay_period_start', per_page: 20, ...params } })).data,
      (payload) => { salary.value = payload.data; meta.value.salary = payload.meta ?? { ...EMPTY_META }; }, 'Unable to load your salary entries.');
  }

  async function fetchAvailability(): Promise<void> {
    await load('availability', async () => (await apiClient.get<{ data: PersonnelAvailability }>('/personnel/me/availability')).data.data,
      (data) => { availability.value = data; }, 'Unable to load your HR-managed availability.');
  }

  return {
    workspace, history, historySummary, preferredRequests, clients, commissions, salary, availability,
    meta, loading, errors, $reset, fetchWorkspace, fetchHistory, fetchPreferredRequests, fetchClients,
    fetchCommissions, fetchSalary, fetchAvailability,
  };
});
