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
  const invitations = ref<StaffInvitation[]>([]);
  const loading = ref(false);

  function $reset(): void {
    staff.value = [];
    invitations.value = [];
    loading.value = false;
  }

  async function fetchStaff(): Promise<void> {
    loading.value = true;
    try {
      const { data } = await apiClient.get<{ data: StaffProfile[] }>('/staff');
      staff.value = data.data;
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
  }

  async function activateStaff(id: string): Promise<void> {
    const { data } = await apiClient.post<{ data: StaffProfile }>(`/staff/${id}/activate`);
    staff.value = staff.value.map((s) => (s.id === id ? data.data : s));
  }

  async function deactivateStaff(id: string, reason?: string): Promise<void> {
    const { data } = await apiClient.post<{ data: StaffProfile }>(`/staff/${id}/deactivate`, { reason });
    staff.value = staff.value.map((s) => (s.id === id ? data.data : s));
  }

  return {
    staff,
    invitations,
    loading,
    fetchStaff,
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
