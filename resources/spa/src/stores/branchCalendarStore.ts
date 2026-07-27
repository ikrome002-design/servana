import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { BranchCalendarException, BranchCalendarExceptionInput } from '@/types/models';

/**
 * Branch calendar exceptions (REM-SCR-002B; Plan §7.2, §27.3 Branch Manager "branch
 * profile/calendar").
 *
 * UX state only — `branch.calendar.manage` + EnsureBranchScope + EnsureBillingMutable +
 * BranchCalendarExceptionPolicy are the boundary. `(branch, date)` is the row's public identity:
 * exactly one exception per date.
 */
export const useBranchCalendarStore = defineStore('branchCalendar', () => {
  const exceptions = ref<BranchCalendarException[]>([]);
  const range = ref<{ from: string; to: string } | null>(null);
  const loading = ref(false);
  const saving = ref(false);
  const fieldErrors = ref<Record<string, string[]>>({});
  /** Non-field API error code (e.g. `calendar_exception_exists`, `billing_read_only`). */
  const errorCode = ref<string | null>(null);

  function $reset(): void {
    exceptions.value = [];
    range.value = null;
    loading.value = false;
    saving.value = false;
    fieldErrors.value = {};
    errorCode.value = null;
  }

  async function fetchExceptions(branchId: string, from?: string, to?: string): Promise<void> {
    loading.value = true;
    try {
      const params = new URLSearchParams();
      if (from) params.set('from', from);
      if (to) params.set('to', to);
      const query = params.toString();

      const { data } = await apiClient.get<{
        data: BranchCalendarException[];
        meta?: { from: string; to: string };
      }>(`/branches/${branchId}/calendar-exceptions${query ? `?${query}` : ''}`);

      exceptions.value = data.data;
      range.value = data.meta ?? null;
    } finally {
      loading.value = false;
    }
  }

  async function createException(
    branchId: string,
    payload: BranchCalendarExceptionInput,
  ): Promise<void> {
    await mutate(branchId, () =>
      apiClient.post<{ data: BranchCalendarException }>(
        `/branches/${branchId}/calendar-exceptions`,
        payload,
      ),
    );
  }

  async function updateException(
    branchId: string,
    date: string,
    payload: Partial<Pick<BranchCalendarExceptionInput, 'opens_at' | 'closes_at' | 'reason'>>,
  ): Promise<void> {
    await mutate(branchId, () =>
      apiClient.patch<{ data: BranchCalendarException }>(
        `/branches/${branchId}/calendar-exceptions/${date}`,
        payload,
      ),
    );
  }

  async function removeException(branchId: string, date: string): Promise<void> {
    await mutate(branchId, () =>
      apiClient.delete(`/branches/${branchId}/calendar-exceptions/${date}`),
    );
  }

  /** Run a mutation, reset error state, and refetch so the list always reflects the server. */
  async function mutate(branchId: string, request: () => Promise<unknown>): Promise<void> {
    saving.value = true;
    fieldErrors.value = {};
    errorCode.value = null;
    try {
      await request();
      await fetchExceptions(branchId, range.value?.from, range.value?.to);
    } catch (error: unknown) {
      const envelope = (
        error as { response?: { data?: { error?: { code?: string; fields?: unknown } } } }
      )?.response?.data?.error;

      errorCode.value = typeof envelope?.code === 'string' ? envelope.code : null;

      const fields = envelope?.fields;
      if (fields && typeof fields === 'object' && !Array.isArray(fields)) {
        const out: Record<string, string[]> = {};
        for (const [key, value] of Object.entries(fields as Record<string, unknown>)) {
          if (Array.isArray(value)) out[key] = value.map(String);
          else if (typeof value === 'string') out[key] = [value];
        }
        fieldErrors.value = out;
      }
      throw error;
    } finally {
      saving.value = false;
    }
  }

  return {
    exceptions,
    range,
    loading,
    saving,
    fieldErrors,
    errorCode,
    fetchExceptions,
    createException,
    updateException,
    removeException,
    $reset,
  };
});
