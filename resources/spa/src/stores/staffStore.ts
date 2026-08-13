import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { StaffInvitation, StaffProfile } from '@/types/models';

/**
 * Staff roster + invitations + lifecycle (Scope §3.4, Plan §27 Phase 7).
 * UX state only — the API enforces authority and branch scope.
 */
export const useStaffStore = defineStore('staff', () => {
  const staff = ref<StaffProfile[]>([]);
  const current = ref<StaffProfile | null>(null);
  const invitations = ref<StaffInvitation[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const meta = ref<{ current_page: number; last_page: number; per_page: number; total: number } | null>(null);

  function $reset(): void {
    staff.value = [];
    current.value = null;
    invitations.value = [];
    loading.value = false;
    error.value = null;
    meta.value = null;
  }

  async function fetchStaff(params: Record<string, string | number> = {}): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{
        data: StaffProfile[];
        meta?: { current_page: number; last_page: number; per_page: number; total: number };
      }>('/staff', { params });
      staff.value = data.data;
      meta.value = data.meta ?? null;
    } catch {
      error.value = 'We couldn’t load the staff roster.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchStaffMember(id: string): Promise<StaffProfile> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: StaffProfile }>(`/staff/${id}`);
      current.value = data.data;
      const index = staff.value.findIndex((member) => member.id === id);
      if (index === -1) staff.value = [data.data, ...staff.value];
      else staff.value[index] = data.data;
      return data.data;
    } catch (cause) {
      error.value = 'We couldn’t load this staff member.';
      throw cause;
    } finally {
      loading.value = false;
    }
  }

  async function fetchInvitations(): Promise<void> {
    const { data } = await apiClient.get<{ data: StaffInvitation[] }>('/staff-invitations');
    invitations.value = data.data;
  }

  async function invite(payload: {
    email: string;
    branch_id: string;
    role: string;
    role_title?: string;
  }): Promise<StaffInvitation> {
    const { data } = await apiClient.post<{ data: StaffInvitation }>('/staff-invitations', payload);
    invitations.value = [data.data, ...invitations.value];
    return data.data;
  }

  async function resendInvitation(id: string): Promise<void> {
    const { data } = await apiClient.post<{ data: StaffInvitation }>(
      `/staff-invitations/${id}/resend`,
    );
    invitations.value = invitations.value.map((i) => (i.id === id ? data.data : i));
  }

  async function revokeInvitation(id: string): Promise<void> {
    const { data } = await apiClient.post<{ data: StaffInvitation }>(
      `/staff-invitations/${id}/revoke`,
    );
    invitations.value = invitations.value.map((i) => (i.id === id ? data.data : i));
  }

  /** Public accept (no session). Returns the server confirmation message. */
  async function acceptInvitation(payload: {
    token: string;
    first_name: string;
    last_name: string;
    phone: string;
  }): Promise<string> {
    const { data } = await apiClient.post<{ message: string }>(
      '/staff-invitations/accept',
      payload,
    );
    return data.message;
  }

  async function suspendStaff(id: string, reason?: string): Promise<void> {
    const { data } = await apiClient.post<{ data: StaffProfile }>(`/staff/${id}/suspend`, { reason });
    staff.value = staff.value.map((s) => (s.id === id ? data.data : s));
    if (current.value?.id === id) current.value = data.data;
  }

  async function activateStaff(id: string): Promise<void> {
    const { data } = await apiClient.post<{ data: StaffProfile }>(`/staff/${id}/activate`);
    staff.value = staff.value.map((s) => (s.id === id ? data.data : s));
    if (current.value?.id === id) current.value = data.data;
  }

  async function deactivateStaff(id: string, reason?: string): Promise<void> {
    const { data } = await apiClient.post<{ data: StaffProfile }>(`/staff/${id}/deactivate`, { reason });
    staff.value = staff.value.map((s) => (s.id === id ? data.data : s));
    if (current.value?.id === id) current.value = data.data;
  }

  return {
    staff,
    current,
    invitations,
    loading,
    error,
    meta,
    fetchStaff,
    fetchStaffMember,
    fetchInvitations,
    invite,
    resendInvitation,
    revokeInvitation,
    acceptInvitation,
    suspendStaff,
    activateStaff,
    deactivateStaff,
    $reset,
  };
});
