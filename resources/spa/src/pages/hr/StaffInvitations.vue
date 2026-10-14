<script setup lang="ts">
import axios from 'axios';
import { onMounted } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { navigationIcon } from '@/navigation/navigationIcons';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import { useForm } from '@/composables/useForm';
import { useAuthStore } from '@/stores/authStore';
import { useBranchStore } from '@/stores/branchStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { useStaffStore } from '@/stores/staffStore';
import { formatDateTime } from '@/utils/dates';

// Staff invitations (Scope §3.2/§3.4). The role options shown depend on the
// signed-in member's authority (admin → branch_manager/hr; HR → operational
// roles). The API is the real boundary — this list is UX only.
const staff = useStaffStore();
const branches = useBranchStore();
const auth = useAuthStore();
const notifications = useNotificationStore();

const adminRoles = [
  { value: 'branch_manager', label: 'Branch Manager' },
  { value: 'hr', label: 'Human Resource' },
];
const hrRoles = [
  { value: 'front_office', label: 'Front Office' },
  { value: 'finance', label: 'Finance' },
  { value: 'personnel', label: 'Personnel' },
  { value: 'audit', label: 'Audit' },
];

const roleOptions = auth.isMerchantAdmin() ? adminRoles : hrRoles;

const form = useForm<{ email: string; branch_id: string; role: string }>({
  email: '',
  branch_id: '',
  role: '',
});

onMounted(async () => {
  await Promise.all([staff.fetchInvitations(), branches.fetchBranches()]);
  if (!auth.isMerchantAdmin() && branches.branches.length === 1) {
    form.values.branch_id = branches.branches[0]!.id;
  }
});

const submit = form.handleSubmit(async (values) => {
  try {
    // Pass a plain snapshot — not the reactive form object — so resetting the
    // form afterwards cannot mutate what was sent.
    await staff.invite({ ...values });
    notifications.addToast({ type: 'success', message: 'Invitation sent.' });
    form.reset();
    if (!auth.isMerchantAdmin() && branches.branches.length === 1) {
      form.values.branch_id = branches.branches[0]!.id;
    }
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      if (err.apiError.code === 'validation_failed') {
        form.mergeServerErrors(err.apiError);
        return;
      }
      notifications.addToast({ type: 'error', message: err.apiError.message });
      return;
    }
    notifications.addToast({ type: 'error', message: 'Could not send the invitation.' });
  }
});

async function resend(id: string): Promise<void> {
  await staff.resendInvitation(id);
  notifications.addToast({ type: 'success', message: 'Invitation resent.' });
}

async function revoke(id: string): Promise<void> {
  await staff.revokeInvitation(id);
  notifications.addToast({ type: 'success', message: 'Invitation revoked.' });
}
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — a focused invitation journey. The form reads as three short steps —
    who, where, what role — each with its marker, under the target-role boundary; sent invitations
    run beside it as a status list with their expiry and the two permitted follow-ups. The server
    still decides which roles may be issued and validates every field.
  -->
  <section
    class="sv-r-page"
    data-testid="hr-staff-invite"
  >
    <SvPageHeader
      title="Invite staff"
      eyebrow="Staff"
      description="Invite an operational user into the assigned branch. The server controls which target roles you may issue."
    />

    <div class="sv-r-grid sv-r-split-wide">
      <div class="sv-r-stack min-w-0">
        <SvAlert
          v-if="!auth.isMerchantAdmin()"
          severity="info"
          title="Human Resource target roles"
        >
          You may invite Front Office, Finance, Personnel and Audit users into this branch. You cannot invite or activate a Merchant Administrator or move someone across branches.
        </SvAlert>

        <section class="sv-r-panel sv-r-panel--tonal sv-r-tone-warm">
          <form
            class="sv-r-steps"
            novalidate
            @submit.prevent="submit"
          >
            <div class="sv-r-step sv-r-step--current">
              <span
                aria-hidden="true"
                class="sv-r-step__marker"
              >1</span>
              <SvTextInput
                id="email"
                v-model="form.values.email"
                label="Email address"
                type="email"
                required
                :errors="form.errors.email"
              />
            </div>
            <div class="sv-r-step">
              <span
                aria-hidden="true"
                class="sv-r-step__marker"
              >2</span>
              <SvSelect
                id="branch_id"
                v-model="form.values.branch_id"
                label="Branch"
                placeholder="Select a branch"
                :options="branches.branches.map((b) => ({ value: b.id, label: b.name }))"
                required
                :errors="form.errors.branch_id"
              />
            </div>
            <div class="sv-r-step">
              <span
                aria-hidden="true"
                class="sv-r-step__marker"
              >3</span>
              <SvSelect
                id="role"
                v-model="form.values.role"
                label="Role"
                placeholder="Select a role"
                :options="roleOptions"
                required
                :errors="form.errors.role"
              />
            </div>
            <div class="pl-[3.25rem]">
              <SvButton
                type="submit"
                variant="primary"
                :loading="form.submitting.value"
              >
                Send invitation
              </SvButton>
            </div>
          </form>
        </section>
      </div>

      <section aria-labelledby="sent-invitations-heading">
        <h2
          id="sent-invitations-heading"
          class="sv-r-title mb-3"
        >
          Sent invitations
        </h2>
        <ul
          class="flex flex-col gap-3"
          aria-label="Staff invitations"
        >
          <li
            v-for="invitation in staff.invitations"
            :key="invitation.id"
          >
            <article class="sv-r-record">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-3">
                  <span
                    aria-hidden="true"
                    class="sv-r-icon sv-r-tone-warm"
                  >
                    <component :is="navigationIcon('invite')" />
                  </span>
                  <div class="min-w-0">
                    <p class="break-all font-medium text-text">
                      {{ invitation.email }}
                    </p>
                    <p class="text-sm capitalize text-text-muted">
                      {{ invitation.role.replaceAll('_', ' ') }}
                    </p>
                  </div>
                </div>
                <SvStatusBadge
                  :label="invitation.status"
                  :tone="invitation.status === 'accepted' ? 'success' : invitation.status === 'pending' ? 'warning' : 'neutral'"
                  size="sm"
                />
              </div>
              <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-sv-border pt-3">
                <p class="text-xs text-text-muted">
                  Expires {{ formatDateTime(invitation.expires_at) }}
                </p>
                <div
                  v-if="invitation.status === 'pending'"
                  class="flex gap-2"
                >
                  <SvButton
                    variant="secondary"
                    size="sm"
                    @click="resend(invitation.id)"
                  >
                    Resend
                  </SvButton>
                  <SvButton
                    variant="ghost"
                    size="sm"
                    @click="revoke(invitation.id)"
                  >
                    Revoke
                  </SvButton>
                </div>
              </div>
            </article>
          </li>
        </ul>
        <p
          v-if="staff.invitations.length === 0"
          class="sv-r-panel sv-r-panel--dashed sv-r-lede text-center"
        >
          No invitations have been sent from this branch yet.
        </p>
      </section>
    </div>
  </section>
</template>