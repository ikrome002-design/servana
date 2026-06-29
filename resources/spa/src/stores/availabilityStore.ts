import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Personnel availability (Plan §13.7, §80 Phase 15B). UX state only — the API
 * (`personnel.availability.manage` + EnsureBranchScope + PersonnelAvailabilityPolicy)
 * is the authorization and isolation boundary. HR replaces a staff member's whole
 * schedule atomically; the Branch Manager reads it (can.update = false).
 */
export interface AvailabilityRecurring {
  weekday: number;
  start_time: string;
  end_time: string;
  available: boolean;
}

export interface AvailabilityException {
  date: string;
  start_time: string;
  end_time: string;
  available: boolean;
}

export interface AvailabilitySchedule {
  staff: { id: string; display_name: string; employment_status: string; is_active: boolean };
  timezone: string;
  current_state: 'suspended' | 'available' | 'on_break' | 'unavailable' | 'offline';
  recurring: AvailabilityRecurring[];
  exceptions: AvailabilityException[];
  eligible_services: { id: string; name: string }[];
  can: { update: boolean };
}

export interface ReplacePayload {
  recurring: AvailabilityRecurring[];
  exceptions: AvailabilityException[];
  change_reason: string;
}

export interface EmergencyPayload {
  date: string;
  start_time: string;
  end_time: string;
  change_reason: string;
}

export const useAvailabilityStore = defineStore('availability', () => {
  const schedule = ref<AvailabilitySchedule | null>(null);
  const loading = ref(false);
  const saving = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    schedule.value = null;
    loading.value = false;
    saving.value = false;
    error.value = null;
  }

  async function fetch(staffId: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: AvailabilitySchedule }>(`/staff/${staffId}/availability`);
      schedule.value = data.data;
    } catch {
      error.value = 'Unable to load availability.';
    } finally {
      loading.value = false;
    }
  }

  async function replace(staffId: string, payload: ReplacePayload): Promise<AvailabilitySchedule> {
    saving.value = true;
    try {
      const { data } = await apiClient.put<{ data: AvailabilitySchedule }>(`/staff/${staffId}/availability`, payload);
      schedule.value = data.data;
      return data.data;
    } finally {
      saving.value = false;
    }
  }

  async function emergencyUnavailable(staffId: string, payload: EmergencyPayload): Promise<AvailabilitySchedule> {
    saving.value = true;
    try {
      const { data } = await apiClient.post<{ data: AvailabilitySchedule }>(
        `/staff/${staffId}/availability/emergency-unavailable`,
        payload,
      );
      schedule.value = data.data;
      return data.data;
    } finally {
      saving.value = false;
    }
  }

  return { schedule, loading, saving, error, $reset, fetch, replace, emergencyUnavailable };
});
