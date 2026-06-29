import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { Appointment, PersonnelAppointment } from '@/types/models';

/**
 * Appointments (Plan §36; Phase 16A). UX state only — the API (AppointmentPolicy
 * + EnsureBranchScope + the scheduling/branch-calendar gates) is the boundary.
 * Client contact is ALWAYS masked by the server; this store never holds a full
 * phone or email (guardrail §6.4). Front Office owns mutations; Branch Manager and
 * Personnel use the read endpoints only.
 */
export const useAppointmentStore = defineStore('appointment', () => {
  const appointments = ref<Appointment[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterDate = ref<string>('');
  const filterStatus = ref<string>('');

  function $reset(): void {
    appointments.value = [];
    loading.value = false;
    error.value = null;
    filterDate.value = '';
    filterStatus.value = '';
  }

  async function fetchAppointments(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (filterDate.value !== '') params.date = filterDate.value;
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: Appointment[] }>('/appointments', { params });
      appointments.value = data.data;
    } catch {
      error.value = 'Unable to load appointments.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchAppointment(id: string): Promise<Appointment> {
    const { data } = await apiClient.get<{ data: Appointment }>(`/appointments/${id}`);
    return data.data;
  }

  async function createAppointment(payload: {
    client: string;
    service: string;
    starts_at: string;
    assigned_personnel?: string;
    preferred_personnel?: string;
  }): Promise<Appointment> {
    const { data } = await apiClient.post<{ data: Appointment }>('/appointments', payload);
    return data.data;
  }

  async function assign(id: string, personnel: string): Promise<Appointment> {
    const { data } = await apiClient.post<{ data: Appointment }>(`/appointments/${id}/assign`, { personnel });
    return data.data;
  }

  async function transfer(id: string, personnel: string, reason?: string): Promise<Appointment> {
    const { data } = await apiClient.post<{ data: Appointment }>(`/appointments/${id}/transfer`, { personnel, reason });
    return data.data;
  }

  async function reschedule(id: string, startsAt: string): Promise<Appointment> {
    const { data } = await apiClient.post<{ data: Appointment }>(`/appointments/${id}/reschedule`, { starts_at: startsAt });
    return data.data;
  }

  async function cancel(id: string, reason?: string): Promise<Appointment> {
    const { data } = await apiClient.post<{ data: Appointment }>(`/appointments/${id}/cancel`, { reason });
    return data.data;
  }

  async function checkIn(id: string): Promise<Appointment> {
    const { data } = await apiClient.post<{ data: Appointment }>(`/appointments/${id}/check-in`);
    return data.data;
  }

  async function markNoShow(id: string): Promise<Appointment> {
    const { data } = await apiClient.post<{ data: Appointment }>(`/appointments/${id}/no-show`);
    return data.data;
  }

  return {
    appointments,
    loading,
    error,
    filterDate,
    filterStatus,
    fetchAppointments,
    fetchAppointment,
    createAppointment,
    assign,
    transfer,
    reschedule,
    cancel,
    checkIn,
    markNoShow,
    $reset,
  };
});

/** Personnel own-scope appointments (read-only). */
export const usePersonnelAppointmentStore = defineStore('personnelAppointment', () => {
  const appointments = ref<PersonnelAppointment[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    appointments.value = [];
    loading.value = false;
    error.value = null;
  }

  async function fetchMine(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: PersonnelAppointment[] }>('/personnel/me/appointments');
      appointments.value = data.data;
    } catch {
      error.value = 'Unable to load your appointments.';
    } finally {
      loading.value = false;
    }
  }

  return { appointments, loading, error, fetchMine, $reset };
});
