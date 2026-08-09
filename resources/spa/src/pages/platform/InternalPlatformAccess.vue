<script setup lang="ts">
/**
 * Internal Platform Access — Super Administrator contract page §5.4.19 (Phase UI-08).
 *
 * Manages Citrus Labs internal platform users: roster, invitation lifecycle, deny-only permission
 * overrides, suspension/reactivation/deactivation and session revocation (COR-UI08-001 §11).
 *
 * BOUNDARIES this page must never blur:
 *
 *  - No merchant role is offered and no merchant permission is addressable. A platform user cannot
 *    be inserted into merchant membership, branch assignment or a staff profile — there is no
 *    control here that could express it.
 *  - Permission overrides are DENY-ONLY. The control subtracts from what the role already grants;
 *    it cannot add. That is why self-escalation is structurally impossible rather than merely
 *    refused.
 *  - Sole-admin lockout and self-change are refused by the SERVER (a quorum check under a row
 *    lock). This page warns before submitting and shows the server's refusal when it comes; it
 *    never pre-empts the decision, because a client-side guess would eventually disagree.
 *
 * `sessions_revoked` counts SESSIONS, not families — the field was renamed during Increment 5
 * precisely so a governance screen cannot display a quietly wrong number.
 */
import { computed, onMounted, ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDataTable from '@/components/ui/SvDataTable.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvResponsiveRecordList from '@/components/ui/SvResponsiveRecordList.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import type { SvColumn, SvDataState } from '@/components/ui/dataContract';
import { useCan } from '@/composables/useCan';
import { useAuthStore } from '@/stores/authStore';
import {
  usePlatformAccessStore,
  type LifecycleAction,
  type PlatformAccessInvitation,
  type PlatformAccessMembership,
} from '@/stores/platformAccessStore';

const store = usePlatformAccessStore();
const auth = useAuthStore();
const { can } = useCan();

const canView = computed(() => can('platform.internal_access.view'));
const canManage = computed(() => can('platform.internal_access.manage'));

const dataState = computed<SvDataState>(() => {
  if (!canView.value) return 'forbidden';
  if (store.loading) return 'loading';
  if (store.error !== null) return 'error';
  return store.users.length === 0 ? 'empty' : 'idle';
});

const invitationState = computed<SvDataState>(() => {
  if (store.loading) return 'loading';
  if (store.error !== null) return 'error';
  return store.invitations.length === 0 ? 'empty' : 'idle';
});

/**
 * How many memberships currently grant access. When exactly one does, every action that would
 * remove it is a sole-admin lockout — the warning is shown BEFORE submitting, and the server
 * refuses independently.
 */
const grantingCount = computed(() => store.users.filter((u) => u.grants_access).length);

const isSelf = (membership: PlatformAccessMembership): boolean =>
  auth.user !== null && membership.user.id === auth.user.id;

const wouldLockOut = (membership: PlatformAccessMembership): boolean =>
  membership.grants_access && grantingCount.value <= 1;

onMounted(() => {
  if (canView.value) void store.load();
});

function resolveMessage(error: unknown, fallback: string): string {
  return (error as { apiError?: { message?: string } }).apiError?.message ?? fallback;
}

// ---------------------------------------------------------------------------------------------
// Invite
// ---------------------------------------------------------------------------------------------

const inviteOpen = ref(false);
const inviteEmail = ref('');
const inviteRole = ref('super_admin');
const inviteReason = ref('');
const inviteSubmitting = ref(false);
const inviteError = ref<string | null>(null);

function openInvite(): void {
  inviteEmail.value = '';
  inviteRole.value = 'super_admin';
  inviteReason.value = '';
  inviteError.value = null;
  inviteOpen.value = true;
}

async function submitInvite(): Promise<void> {
  if (inviteSubmitting.value) return;
  inviteSubmitting.value = true;
  inviteError.value = null;

  try {
    await store.invite({ email: inviteEmail.value, role_key: inviteRole.value, reason: inviteReason.value });
    inviteOpen.value = false;
  } catch (error) {
    inviteError.value = resolveMessage(error, 'Unable to send this invitation.');
  } finally {
    inviteSubmitting.value = false;
  }
}

// ---------------------------------------------------------------------------------------------
// Lifecycle + session revocation
// ---------------------------------------------------------------------------------------------

type PendingAction =
  | { kind: 'lifecycle'; action: LifecycleAction; membership: PlatformAccessMembership }
  | { kind: 'sessions'; membership: PlatformAccessMembership }
  | { kind: 'revoke-invitation'; invitation: PlatformAccessInvitation };

const pending = ref<PendingAction | null>(null);
const actionReason = ref('');
const actionSubmitting = ref(false);
const actionError = ref<string | null>(null);
const sessionsRevoked = ref<number | null>(null);

function startAction(next: PendingAction): void {
  pending.value = next;
  actionReason.value = '';
  actionError.value = null;
  sessionsRevoked.value = null;
}

const actionTitle = computed(() => {
  const current = pending.value;
  if (current === null) return '';
  if (current.kind === 'sessions') return 'Revoke all active sessions?';
  if (current.kind === 'revoke-invitation') return 'Revoke this invitation?';
  if (current.action === 'suspend') return 'Suspend platform access?';
  if (current.action === 'reactivate') return 'Reactivate platform access?';
  return 'Deactivate platform access?';
});

const actionConsequence = computed(() => {
  const current = pending.value;
  if (current === null) return '';
  if (current.kind === 'sessions') {
    return 'Every active session for this user ends immediately. They must sign in again with a new Magic Link.';
  }
  if (current.kind === 'revoke-invitation') {
    return 'The invitation link stops working immediately. It cannot be un-revoked; send a new invitation instead.';
  }
  if (current.action === 'suspend') {
    return 'Access is withdrawn immediately and active sessions end. The record and its history are preserved.';
  }
  if (current.action === 'reactivate') {
    return 'Access is restored. The user must still complete multi-factor authentication.';
  }
  return 'Access ends permanently. The record and its history are preserved; a new invitation is required to restore access.';
});

async function confirmAction(): Promise<void> {
  const current = pending.value;
  if (actionSubmitting.value || current === null) return;
  actionSubmitting.value = true;
  actionError.value = null;

  try {
    if (current.kind === 'lifecycle') {
      await store.lifecycle(current.membership.id, current.action, actionReason.value);
      pending.value = null;
    } else if (current.kind === 'sessions') {
      const result = await store.revokeSessions(current.membership.id, actionReason.value);
      sessionsRevoked.value = result.sessions_revoked;
    } else {
      await store.revokeInvitation(current.invitation.id, actionReason.value);
      pending.value = null;
    }
  } catch (error) {
    actionError.value = resolveMessage(error, 'The server refused this change.');
  } finally {
    actionSubmitting.value = false;
  }
}

// ---------------------------------------------------------------------------------------------
// Deny-only permission overrides
// ---------------------------------------------------------------------------------------------

const overrideTarget = ref<PlatformAccessMembership | null>(null);
const overrideInput = ref('');
const overrideReason = ref('');
const overrideSubmitting = ref(false);
const overrideError = ref<string | null>(null);

function openOverride(membership: PlatformAccessMembership): void {
  overrideTarget.value = membership;
  overrideInput.value = membership.denied_permissions.join('\n');
  overrideReason.value = '';
  overrideError.value = null;
}

const overrideList = computed(() =>
  overrideInput.value
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== ''),
);

async function submitOverride(): Promise<void> {
  if (overrideSubmitting.value || overrideTarget.value === null) return;
  overrideSubmitting.value = true;
  overrideError.value = null;

  try {
    await store.updateDeniedPermissions(overrideTarget.value.id, overrideList.value, overrideReason.value);
    overrideTarget.value = null;
  } catch (error) {
    overrideError.value = resolveMessage(error, 'The server refused this permission change.');
  } finally {
    overrideSubmitting.value = false;
  }
}

// ---------------------------------------------------------------------------------------------
// Columns
// ---------------------------------------------------------------------------------------------

const userColumns: SvColumn<PlatformAccessMembership>[] = [
  { key: 'name', label: 'Name', priority: 'primary', value: (r) => r.user.name },
  { key: 'email', label: 'Email', priority: 'primary', value: (r) => r.user.email },
  { key: 'status', label: 'Status', priority: 'primary' },
  { key: 'role_key', label: 'Platform role', priority: 'secondary', value: (r) => r.role_key },
  // `mfa_enrolled` arrives as a boolean but the generated contract widens it to `string`, so the
  // comparison is explicit: a bare truthiness test would report the string "false" as enrolled.
  { key: 'mfa', label: 'MFA', priority: 'secondary', value: (r) => (String(r.user.mfa_enrolled) === 'true' ? 'Enrolled' : 'Not enrolled') },
  { key: 'active_session_count', label: 'Active sessions', priority: 'secondary', align: 'numeric', value: (r) => String(r.active_session_count) },
  { key: 'last_login_at', label: 'Last sign-in', priority: 'detail' },
  { key: 'denied_permissions', label: 'Denied permissions', priority: 'detail', value: (r) => (r.denied_permissions.length === 0 ? 'None' : r.denied_permissions.join(', ')) },
  { key: 'last_action', label: 'Last change', priority: 'detail', value: (r) => r.last_action ?? '—' },
];

const invitationColumns: SvColumn<PlatformAccessInvitation>[] = [
  { key: 'email', label: 'Email', priority: 'primary', value: (r) => r.email },
  { key: 'status', label: 'Status', priority: 'primary' },
  { key: 'role_key', label: 'Platform role', priority: 'secondary', value: (r) => r.role_key },
  { key: 'expires_at', label: 'Expires', priority: 'secondary' },
  { key: 'resend_count', label: 'Resends', priority: 'detail', align: 'numeric', value: (r) => String(r.resend_count) },
  { key: 'revocation_reason', label: 'Revocation reason', priority: 'detail', value: (r) => r.revocation_reason ?? '—' },
];

const statusTone = (status: string): 'success' | 'warning' | 'error' | 'neutral' | 'info' => {
  if (status === 'active' || status === 'accepted') return 'success';
  if (status === 'pending') return 'info';
  if (status === 'suspended') return 'warning';
  if (status === 'deactivated' || status === 'revoked' || status === 'expired') return 'error';
  return 'neutral';
};
</script>

<template>
  <div
    class="mx-auto w-full max-w-6xl"
    data-testid="platform-access-screen"
  >
    <SvPageHeader
      title="Internal platform access"
      eyebrow="Platform administration"
      description="Citrus Labs internal platform users: who holds access, their multi-factor state, active sessions, deny-only permission overrides and the invitation lifecycle."
    >
      <template #actions>
        <SvButton
          v-if="canManage"
          variant="primary"
          data-testid="access-invite-open"
          @click="openInvite"
        >
          Invite a platform user
        </SvButton>
      </template>
    </SvPageHeader>

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <p
        class="mb-4 text-xs text-sv-text-muted"
        data-testid="access-last-refreshed"
      >
        Last refreshed
        <SvDateTime :value="store.lastRefreshed" />
      </p>

      <SvAlert
        v-if="grantingCount <= 1 && !store.loading"
        severity="warning"
        title="Only one account currently grants platform access"
        class="mb-6"
        data-testid="access-sole-admin-warning"
      >
        <p>
          Suspending, deactivating or denying permissions on the last account that grants access
          would lock everyone out. The server refuses that change independently of this warning.
        </p>
      </SvAlert>

      <SvAlert
        v-if="store.error"
        severity="error"
        title="We could not load internal platform access"
        class="mb-6"
      >
        <p>{{ store.error }}</p>
        <SvButton
          variant="secondary"
          size="sm"
          class="mt-3"
          data-testid="access-retry"
          @click="store.load()"
        >
          Try again
        </SvButton>
      </SvAlert>

      <!-- Roster --------------------------------------------------------------------------- -->
      <section
        aria-labelledby="access-roster-heading"
        class="mb-8"
      >
        <h2
          id="access-roster-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Platform users
        </h2>
        <p class="mb-3 max-w-sv-readable text-sm text-sv-text-muted">
          Only platform roles appear here. A platform user can never be given a merchant role,
          merchant membership, branch assignment or staff profile.
        </p>

        <!--
          UI08-RESP-001: these tables carry six or more columns including masked emails and
          timestamps, and pushed the document past the 768px tablet floor. They render from the
          DESKTOP floor; tablet reads the labelled record cards, which is what the plan asks a
          tablet to do anyway.
        -->
        <div class="hidden lg:block">
          <SvDataTable
            :columns="userColumns"
            :rows="store.users"
            :row-key="(r) => r.id"
            caption="Internal platform users"
            :state="dataState"
            empty-message="No platform user has been recorded."
            @retry="store.load()"
          >
            <template #cell:status="{ row }">
              <SvStatusBadge
                :label="row.status"
                :tone="statusTone(row.status)"
                sr-prefix="Status:"
              />
            </template>
            <template #cell:last_login_at="{ row }">
              <SvDateTime :value="row.user.last_login_at" />
            </template>
          </SvDataTable>
        </div>

        <div class="lg:hidden">
          <SvResponsiveRecordList
            :columns="userColumns"
            :rows="store.users"
            :row-key="(r) => r.id"
            caption="Internal platform users"
            :state="dataState"
            empty-message="No platform user has been recorded."
            @retry="store.load()"
          >
            <template #cell:status="{ row }">
              <SvStatusBadge
                :label="row.status"
                :tone="statusTone(row.status)"
                sr-prefix="Status:"
              />
            </template>
          </SvResponsiveRecordList>
        </div>

        <!-- Per-user governance actions. Rendered as an explicit list so every action names its
             target, rather than relying on a row the user must first select. -->
        <ul
          v-if="canManage && store.users.length > 0"
          class="mt-4 space-y-3"
          data-testid="access-actions"
        >
          <li
            v-for="membership in store.users"
            :key="`actions-${membership.id}`"
            class="rounded-card border border-sv-border bg-sv-surface-raised p-4"
          >
            <p class="text-sm font-medium text-sv-text">
              {{ membership.user.name }}
              <span class="font-normal text-sv-text-muted">({{ membership.user.email }})</span>
            </p>
            <p
              v-if="isSelf(membership)"
              class="mt-1 text-xs text-sv-text-muted"
              :data-testid="`access-self-warning-${membership.id}`"
            >
              This is your own account. Changing it can remove your own access.
            </p>
            <p
              v-else-if="wouldLockOut(membership)"
              class="mt-1 text-xs text-sv-text-muted"
            >
              This is currently the only account granting platform access.
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
              <SvButton
                variant="secondary"
                size="sm"
                @click="openOverride(membership)"
              >
                Deny permissions
              </SvButton>
              <SvButton
                v-if="membership.status === 'active'"
                variant="secondary"
                size="sm"
                @click="startAction({ kind: 'lifecycle', action: 'suspend', membership })"
              >
                Suspend
              </SvButton>
              <SvButton
                v-if="membership.status === 'suspended'"
                variant="secondary"
                size="sm"
                @click="startAction({ kind: 'lifecycle', action: 'reactivate', membership })"
              >
                Reactivate
              </SvButton>
              <SvButton
                variant="secondary"
                size="sm"
                @click="startAction({ kind: 'sessions', membership })"
              >
                Revoke sessions
              </SvButton>
              <SvButton
                v-if="membership.status !== 'deactivated'"
                variant="destructive"
                size="sm"
                @click="startAction({ kind: 'lifecycle', action: 'deactivate', membership })"
              >
                Deactivate
              </SvButton>
            </div>
          </li>
        </ul>
      </section>

      <!-- Invitations ---------------------------------------------------------------------- -->
      <section aria-labelledby="access-invitations-heading">
        <h2
          id="access-invitations-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Invitations
        </h2>

        <!--
          UI08-RESP-001: these tables carry six or more columns including masked emails and
          timestamps, and pushed the document past the 768px tablet floor. They render from the
          DESKTOP floor; tablet reads the labelled record cards, which is what the plan asks a
          tablet to do anyway.
        -->
        <div class="hidden lg:block">
          <SvDataTable
            :columns="invitationColumns"
            :rows="store.invitations"
            :row-key="(r) => r.id"
            caption="Platform access invitations"
            :state="invitationState"
            empty-message="No invitation is outstanding."
            @retry="store.load()"
          >
            <template #cell:status="{ row }">
              <SvStatusBadge
                :label="row.status"
                :tone="statusTone(row.status)"
                sr-prefix="Status:"
              />
            </template>
            <template #cell:expires_at="{ row }">
              <SvDateTime :value="row.expires_at" />
            </template>
          </SvDataTable>
        </div>

        <div class="lg:hidden">
          <SvResponsiveRecordList
            :columns="invitationColumns"
            :rows="store.invitations"
            :row-key="(r) => r.id"
            caption="Platform access invitations"
            :state="invitationState"
            empty-message="No invitation is outstanding."
            @retry="store.load()"
          />
        </div>

        <ul
          v-if="canManage"
          class="mt-4 space-y-2"
        >
          <li
            v-for="invitation in store.invitations.filter((i) => i.redeemable)"
            :key="`inv-actions-${invitation.id}`"
            class="flex flex-wrap items-center gap-2 rounded-card border border-sv-border bg-sv-surface-raised p-3 text-sm"
          >
            <span class="min-w-0 grow break-words">{{ invitation.email }}</span>
            <SvButton
              variant="secondary"
              size="sm"
              @click="store.resendInvitation(invitation.id)"
            >
              Resend
            </SvButton>
            <SvButton
              variant="destructive"
              size="sm"
              @click="startAction({ kind: 'revoke-invitation', invitation })"
            >
              Revoke
            </SvButton>
          </li>
        </ul>
      </section>
    </template>

    <!-- Invite dialog ---------------------------------------------------------------------- -->
    <SvDialog
      :open="inviteOpen"
      title="Invite a platform user"
      description="A single-use Magic Link is sent to this address. The token is hashed at rest and is never shown to anyone, including you."
      persistent
      @close="inviteOpen = false"
    >
      <div class="space-y-4">
        <SvTextInput
          id="access-invite-email"
          v-model="inviteEmail"
          label="Email address"
          type="email"
          autocomplete="email"
          required
        />
        <SvTextInput
          id="access-invite-role"
          v-model="inviteRole"
          label="Platform role"
          readonly
          help="Only platform roles can be issued here. Merchant roles are not addressable from this screen."
        />
        <SvTextArea
          id="access-invite-reason"
          v-model="inviteReason"
          label="Reason"
          :rows="3"
          help="Recorded on the audit event for this invitation."
          required
        />

        <SvAlert
          v-if="inviteError"
          severity="error"
          data-testid="access-invite-error"
        >
          <p>{{ inviteError }}</p>
        </SvAlert>

        <p class="text-xs text-sv-text-muted">
          This change requires multi-factor authentication and a fresh step-up.
        </p>
      </div>

      <template #footer>
        <SvButton
          variant="ghost"
          @click="inviteOpen = false"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="primary"
          :loading="inviteSubmitting"
          loading-label="Sending"
          data-testid="access-invite-submit"
          @click="submitInvite"
        >
          Send invitation
        </SvButton>
      </template>
    </SvDialog>

    <!-- Lifecycle / session / invitation confirmation ---------------------------------------- -->
    <SvDialog
      :open="pending !== null"
      :title="actionTitle"
      :description="actionConsequence"
      persistent
      @close="pending = null"
    >
      <div class="space-y-4">
        <SvAlert
          v-if="pending?.kind !== 'revoke-invitation' && pending && 'membership' in pending && isSelf(pending.membership)"
          severity="warning"
          data-testid="access-self-change-warning"
        >
          <p>This is your own account. You may remove your own access.</p>
        </SvAlert>

        <SvAlert
          v-if="pending?.kind === 'lifecycle' && wouldLockOut(pending.membership) && pending.action !== 'reactivate'"
          severity="warning"
          data-testid="access-lockout-warning"
        >
          <p>
            This is the only account granting platform access. The server will refuse a change that
            would leave none.
          </p>
        </SvAlert>

        <SvTextArea
          id="access-action-reason"
          v-model="actionReason"
          label="Reason"
          :rows="3"
          help="Recorded on the audit event."
          required
        />

        <SvAlert
          v-if="sessionsRevoked !== null"
          severity="success"
          data-testid="access-sessions-revoked"
        >
          <p>{{ sessionsRevoked }} session(s) were revoked.</p>
        </SvAlert>

        <SvAlert
          v-if="actionError"
          severity="error"
          data-testid="access-action-error"
        >
          <p>{{ actionError }}</p>
        </SvAlert>
      </div>

      <template #footer>
        <SvButton
          variant="ghost"
          @click="pending = null"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="destructive"
          :loading="actionSubmitting"
          loading-label="Applying"
          data-testid="access-action-submit"
          @click="confirmAction"
        >
          Confirm
        </SvButton>
      </template>
    </SvDialog>

    <!-- Deny-only permission override --------------------------------------------------------- -->
    <SvDialog
      :open="overrideTarget !== null"
      title="Deny permissions"
      description="Overrides are deny-only: they subtract from what this user's platform role already grants. Nothing here can add a permission, which is why it cannot be used to escalate."
      persistent
      @close="overrideTarget = null"
    >
      <div class="space-y-4">
        <SvTextArea
          id="access-override-permissions"
          v-model="overrideInput"
          label="Permissions to deny (one per line)"
          :rows="6"
          help="Leave empty to remove every override and restore the role's own grants."
        />

        <div
          class="rounded-card border border-sv-border bg-sv-surface-raised p-3 text-sm"
          data-testid="access-override-preview"
        >
          <p class="font-medium text-sv-text">
            Impact
          </p>
          <p class="mt-1 text-sv-text-muted">
            <template v-if="overrideList.length === 0">
              No permission will be denied. This user keeps everything their platform role grants.
            </template>
            <template v-else>
              {{ overrideList.length }} permission(s) will be denied on top of the role:
              {{ overrideList.join(', ') }}.
            </template>
          </p>
        </div>

        <SvTextArea
          id="access-override-reason"
          v-model="overrideReason"
          label="Reason"
          :rows="3"
          help="Recorded on the audit event."
          required
        />

        <SvAlert
          v-if="overrideError"
          severity="error"
          data-testid="access-override-error"
        >
          <p>{{ overrideError }}</p>
        </SvAlert>
      </div>

      <template #footer>
        <SvButton
          variant="ghost"
          @click="overrideTarget = null"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="primary"
          :loading="overrideSubmitting"
          loading-label="Saving"
          data-testid="access-override-submit"
          @click="submitOverride"
        >
          Save overrides
        </SvButton>
      </template>
    </SvDialog>
  </div>
</template>
