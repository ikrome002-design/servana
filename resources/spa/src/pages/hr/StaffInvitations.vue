<script setup lang="ts">
import axios from 'axios';
import { onMounted } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import { useForm } from '@/composables/useForm';
import { useAuthStore } from '@/stores/authStore';
import { useBranchStore } from '@/stores/branchStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { useStaffStore } from '@/stores/staffStore';

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
});

const submit = form.handleSubmit(async (values) => {
  try {
    // Pass a plain snapshot — not the reactive form object — so resetting the
    // form afterwards cannot mutate what was sent.
    await staff.invite({ ...values });
    notifications.addToast({ type: 'success', message: 'Invitation sent.' });
    form.reset();
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
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Staff invitations
    </h1>

    <SvCard
      as="div"
      padding="lg"
      class="mt-6 max-w-lg"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
      >
        <SvTextInput
          id="email"
          v-model="form.values.email"
          label="Email address"
          type="email"
          required
          :errors="form.errors.email"
        />
        <SvSelect
          id="branch_id"
          v-model="form.values.branch_id"
          label="Branch"
          placeholder="Select a branch"
          :options="branches.branches.map((b) => ({ value: b.id, label: b.name }))"
          required
          :errors="form.errors.branch_id"
        />
        <SvSelect
          id="role"
          v-model="form.values.role"
          label="Role"
          placeholder="Select a role"
          :options="roleOptions"
          required
          :errors="form.errors.role"
        />
        <SvButton
          type="submit"
          variant="primary"
          :loading="form.submitting.value"
        >
          Send invitation
        </SvButton>
      </form>
    </SvCard>

    <ul
      class="mt-8 flex flex-col gap-3"
      aria-label="Pending invitations"
    >
      <li
        v-for="invitation in staff.invitations"
        :key="invitation.id"
      >
        <SvCard
          as="article"
          padding="md"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p class="font-medium text-text">
                {{ invitation.email }}
              </p>
              <p class="text-sm text-text-muted">
                {{ invitation.role }} · {{ invitation.status }}
              </p>
            </div>
            <div
              v-if="invitation.status === 'pending'"
              class="flex gap-2"
            >
              <SvButton
                variant="secondary"
                @click="resend(invitation.id)"
              >
                Resend
              </SvButton>
              <SvButton
                variant="ghost"
                @click="revoke(invitation.id)"
              >
                Revoke
              </SvButton>
            </div>
          </div>
        </SvCard>
      </li>
    </ul>
  </section>
</template>
