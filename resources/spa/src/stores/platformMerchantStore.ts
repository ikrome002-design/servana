import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Platform merchant governance (Plan §22, §24.1; Phase 20B). UX state only — the API
 * (ResolvePlatformContext + MerchantPolicy governance abilities + mandatory MFA + a fresh
 * `merchant_governance` step-up) is the security boundary. Governance mutations change a merchant's
 * OPERATIONAL status ONLY (never the billing status, which is displayed separately); operational
 * reactivation is NOT a billing-recovery path. Every mutation requires a mandatory reason. There is
 * NO merchant-creation, first-admin, impersonation, manual-payment, or Wallet action here.
 */
export type PlatformMerchant = components['schemas']['PlatformMerchantResource'];
export type RegistrationMonitorRow = components['schemas']['MerchantRegistrationMonitorResource'];

/** The page metadata Laravel's resource collection returns. Absent on a non-paginated stub. */
export interface PlatformMerchantPageMeta {
  current_page: number;
  last_page: number;
  total: number;
}

/**
 * Why the dedicated detail page needs its own outcome (Phase UI-08, contract page §5.4.12): it is
 * reached by URL, so an unknown, foreign or unreadable ULID is a REACHABLE first render — not an
 * edge case behind a row click. Each outcome is distinct, and none of them confirms whether the
 * record exists: `not_found` and `forbidden` both render the non-enumerating state.
 */
export type MerchantDetailOutcome = 'idle' | 'loading' | 'loaded' | 'not_found' | 'forbidden' | 'error';

function readMeta(payload: unknown): PlatformMerchantPageMeta | null {
  if (typeof payload !== 'object' || payload === null) return null;
  const meta = (payload as { meta?: unknown }).meta;
  if (typeof meta !== 'object' || meta === null) return null;
  const { current_page: current, last_page: last, total } = meta as Record<string, unknown>;
  if (typeof current !== 'number' || typeof last !== 'number' || typeof total !== 'number') return null;
  return { current_page: current, last_page: last, total };
}

export const usePlatformMerchantStore = defineStore('platformMerchant', () => {
  const registrations = ref<RegistrationMonitorRow[]>([]);
  const merchants = ref<PlatformMerchant[]>([]);
  const selected = ref<PlatformMerchant | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  /**
   * Both platform reads are server-paginated. Discarding `meta` would show page one as if it were
   * the whole platform — on the screens used to govern it. Page is sent only when it is not the
   * first, so the default request stays exactly the one the shipped API contract documents.
   */
  const registrationStatus = ref<string>('');
  const registrationPage = ref(1);
  const registrationMeta = ref<PlatformMerchantPageMeta | null>(null);
  const merchantPage = ref(1);
  const merchantMeta = ref<PlatformMerchantPageMeta | null>(null);

  const detailOutcome = ref<MerchantDetailOutcome>('idle');

  function $reset(): void {
    registrations.value = [];
    merchants.value = [];
    selected.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
    registrationStatus.value = '';
    registrationPage.value = 1;
    registrationMeta.value = null;
    merchantPage.value = 1;
    merchantMeta.value = null;
    detailOutcome.value = 'idle';
  }

  async function fetchRegistrations(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string | number> = {};
      if (registrationStatus.value !== '') params.status = registrationStatus.value;
      if (registrationPage.value > 1) params.page = registrationPage.value;
      const { data } = Object.keys(params).length === 0
        ? await apiClient.get<{ data: RegistrationMonitorRow[] }>('/platform/registration-monitor')
        : await apiClient.get<{ data: RegistrationMonitorRow[] }>('/platform/registration-monitor', { params });
      registrations.value = data.data;
      registrationMeta.value = readMeta(data);
    } catch {
      error.value = 'Unable to load registration monitoring.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchMerchants(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string | number> = {};
      if (filterStatus.value !== '') params.status = filterStatus.value;
      if (merchantPage.value > 1) params.page = merchantPage.value;
      const { data } = await apiClient.get<{ data: PlatformMerchant[] }>('/platform/merchants', { params });
      merchants.value = data.data;
      merchantMeta.value = readMeta(data);
    } catch {
      error.value = 'Unable to load merchants.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchMerchant(id: string): Promise<PlatformMerchant> {
    const { data } = await apiClient.get<{ data: PlatformMerchant }>(`/platform/merchants/${id}`);
    selected.value = data.data;
    return data.data;
  }

  /**
   * Deep-link load for `/merchants/:merchantUlid`. Never throws: the caller is a route render, and
   * an unrouted rejection there is a blank governance screen. A 403 and a 404 collapse to their own
   * outcomes but to the SAME rendered message, so the URL cannot be used to probe which merchants
   * exist (Plan §11.5; the server's non-enumeration contract must not be undone by the UI).
   */
  async function loadMerchant(id: string): Promise<MerchantDetailOutcome> {
    selected.value = null;
    detailOutcome.value = 'loading';
    try {
      await fetchMerchant(id);
      detailOutcome.value = 'loaded';
    } catch (err: unknown) {
      const status = (err as { response?: { status?: number } } | null)?.response?.status;
      if (status === 404) detailOutcome.value = 'not_found';
      else if (status === 403 || status === 401) detailOutcome.value = 'forbidden';
      else detailOutcome.value = 'error';
    }
    return detailOutcome.value;
  }

  /** Governance mutations — mandatory reason; a missing fresh step-up returns 403 (surfaced). */
  async function suspend(id: string, reason: string): Promise<PlatformMerchant> {
    return governance(id, 'suspend', reason);
  }

  async function reactivate(id: string, reason: string): Promise<PlatformMerchant> {
    return governance(id, 'reactivate', reason);
  }

  async function deactivate(id: string, reason: string): Promise<PlatformMerchant> {
    return governance(id, 'deactivate', reason);
  }

  async function governance(id: string, action: 'suspend' | 'reactivate' | 'deactivate', reason: string): Promise<PlatformMerchant> {
    const { data } = await apiClient.post<{ data: PlatformMerchant }>(`/platform/merchants/${id}/${action}`, { reason });
    const idx = merchants.value.findIndex((m) => m.id === id);
    if (idx !== -1) merchants.value[idx] = data.data;
    if (selected.value?.id === id) selected.value = data.data;
    return data.data;
  }

  return {
    registrations,
    merchants,
    selected,
    loading,
    error,
    filterStatus,
    registrationStatus,
    registrationPage,
    registrationMeta,
    merchantPage,
    merchantMeta,
    detailOutcome,
    $reset,
    fetchRegistrations,
    fetchMerchants,
    fetchMerchant,
    loadMerchant,
    suspend,
    reactivate,
    deactivate,
  };
});
