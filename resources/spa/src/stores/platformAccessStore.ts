import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components, paths } from '@/types/generated/api';

/**
 * Internal platform access — Citrus Labs staff identity lifecycle (COR-UI08-001 §11; Phase UI-08,
 * contract page §5.4.19).
 *
 * UX state only. `PlatformAccessPolicy` + `EnsurePermission:platform.internal_access.view` /
 * `.manage` + MFA + a fresh `platform_access_administration` step-up are the security boundary.
 *
 * Two invariants this store must never appear to soften:
 *
 *  - Permission overrides are **deny-only**. `denied_permissions` subtracts from the role's
 *    grants; nothing here can add a permission a role does not already carry, and no merchant
 *    role or merchant permission is addressable at all.
 *  - Quorum and self-change protection live in the DATABASE and the action, not here. The store
 *    surfaces the server's refusal (`sole_admin_lockout`, self-escalation) rather than
 *    pre-empting it, because a client-side guess would eventually disagree with the server.
 *
 * `sessions_revoked` is a count of SESSIONS, not families — the name was corrected during
 * Increment 5 precisely so a governance screen cannot show a quietly wrong number.
 */
export type PlatformAccessMembership = components['schemas']['PlatformAccessMembershipResource'];
export type PlatformAccessInvitation = components['schemas']['PlatformAccessInvitationResource'];

type UsersResponse = paths['/api/v1/platform/internal-access/users']['get']['responses'][200]['content']['application/json'];
type InvitationsResponse = paths['/api/v1/platform/internal-access/invitations']['get']['responses'][200]['content']['application/json'];

export interface InvitePayload {
  email: string;
  role_key: string;
  reason: string;
}

export interface SessionRevocationResult {
  sessions_revoked: number;
}

export type LifecycleAction = 'suspend' | 'reactivate' | 'deactivate';

export const usePlatformAccessStore = defineStore('platformAccess', () => {
  const users = ref<PlatformAccessMembership[]>([]);
  const invitations = ref<PlatformAccessInvitation[]>([]);
  const selected = ref<PlatformAccessMembership | null>(null);

  const loading = ref(false);
  const error = ref<string | null>(null);
  const lastRefreshed = ref<string | null>(null);
  const statusFilter = ref<string>('');
  const page = ref(1);

  let sequence = 0;
  const isCurrent = (token: number): boolean => token === sequence;

  function $reset(): void {
    users.value = [];
    invitations.value = [];
    selected.value = null;
    loading.value = false;
    error.value = null;
    lastRefreshed.value = null;
    statusFilter.value = '';
    page.value = 1;
    sequence += 1;
  }

  async function load(): Promise<void> {
    const token = ++sequence;
    loading.value = true;
    error.value = null;

    try {
      const params: Record<string, string | number> = { page: page.value };
      if (statusFilter.value !== '') params.status = statusFilter.value;

      const [usersResponse, invitationsResponse] = await Promise.all([
        apiClient.get<UsersResponse>('/platform/internal-access/users', { params }),
        apiClient.get<InvitationsResponse>('/platform/internal-access/invitations'),
      ]);

      if (!isCurrent(token)) return;

      users.value = usersResponse.data.data;
      invitations.value = invitationsResponse.data.data;
      lastRefreshed.value = new Date().toISOString();
    } catch {
      if (isCurrent(token)) error.value = 'Unable to load internal platform access.';
    } finally {
      if (isCurrent(token)) loading.value = false;
    }
  }

  async function openUser(id: string): Promise<PlatformAccessMembership> {
    const { data } = await apiClient.get<{ data: PlatformAccessMembership }>(`/platform/internal-access/users/${id}`);
    selected.value = data.data;
    return data.data;
  }

  /** Magic Link invitation. The token is hashed at rest and never returned to any client. */
  async function invite(payload: InvitePayload): Promise<PlatformAccessInvitation> {
    const { data } = await apiClient.post<{ data: PlatformAccessInvitation }>(
      '/platform/internal-access/invitations',
      payload,
      { headers: { 'Idempotency-Key': crypto.randomUUID() } },
    );
    await load();
    return data.data;
  }

  async function resendInvitation(id: string): Promise<PlatformAccessInvitation> {
    const { data } = await apiClient.post<{ data: PlatformAccessInvitation }>(
      `/platform/internal-access/invitations/${id}/resend`,
      {},
    );
    await load();
    return data.data;
  }

  async function revokeInvitation(id: string, reason: string): Promise<PlatformAccessInvitation> {
    const { data } = await apiClient.post<{ data: PlatformAccessInvitation }>(
      `/platform/internal-access/invitations/${id}/revoke`,
      { reason },
    );
    await load();
    return data.data;
  }

  /**
   * Deny-only permission overrides. The payload names the permissions to DENY; there is no grant
   * direction on this endpoint, which is what makes self-escalation structurally impossible
   * rather than merely refused.
   */
  async function updateDeniedPermissions(
    id: string,
    deniedPermissions: string[],
    reason: string,
  ): Promise<PlatformAccessMembership> {
    const { data } = await apiClient.patch<{ data: PlatformAccessMembership }>(
      `/platform/internal-access/users/${id}/permissions`,
      { denied_permissions: deniedPermissions, reason },
    );
    if (selected.value?.id === id) selected.value = data.data;
    await load();
    return data.data;
  }

  async function lifecycle(id: string, action: LifecycleAction, reason: string): Promise<PlatformAccessMembership> {
    const { data } = await apiClient.post<{ data: PlatformAccessMembership }>(
      `/platform/internal-access/users/${id}/${action}`,
      { reason },
    );
    if (selected.value?.id === id) selected.value = data.data;
    await load();
    return data.data;
  }

  async function revokeSessions(id: string, reason: string): Promise<SessionRevocationResult> {
    const { data } = await apiClient.post<{ data: SessionRevocationResult }>(
      `/platform/internal-access/users/${id}/sessions/revoke`,
      { reason },
    );
    await load();
    return data.data;
  }

  return {
    users,
    invitations,
    selected,
    loading,
    error,
    lastRefreshed,
    statusFilter,
    page,
    $reset,
    load,
    openUser,
    invite,
    resendInvitation,
    revokeInvitation,
    updateDeniedPermissions,
    lifecycle,
    revokeSessions,
  };
});
