<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { useNotificationStore } from '@/stores/notificationStore';
import { useStaffStore } from '@/stores/staffStore';

type LifecycleAction = 'suspend' | 'activate' | 'deactivate';

const route = useRoute();
const store = useStaffStore();
const notifications = useNotificationStore();
const id = computed(() => String(route.params.staffUlid ?? ''));
const member = computed(() => store.current?.id === id.value ? store.current : null);
const state = computed(() => (store.loading ? 'loading' : store.error ? 'error' : member.value ? 'success' : 'empty'));
const action = ref<LifecycleAction | null>(null);
const reason = ref('');
const typedName = ref('');
const busy = ref(false);
const reasonRequired = computed(() => action.value === 'suspend' || action.value === 'deactivate');
const confirmed = computed(() => {
  if (!member.value || action.value === null) return false;
  if (typedName.value.trim() !== member.value.display_name) return false;
  return !reasonRequired.value || reason.value.trim().length > 0;
});

const copy: Record<LifecycleAction, { title: string; description: string; button: string }> = {
  suspend: {
    title: 'Suspend staff access',
    description: 'Suspension revokes active sessions and unused Magic Links immediately while preserving history.',
    button: 'Suspend access',
  },
  activate: {
    title: 'Reactivate staff access',
    description: 'Reactivation restores eligibility to sign in; the server rechecks membership and branch authority.',
    button: 'Reactivate access',
  },
  deactivate: {
    title: 'Deactivate staff',
    description: 'Deactivation revokes access and preserves operational and financial history. It is not a record deletion.',
    button: 'Deactivate staff',
  },
};

function open(next: LifecycleAction): void {
  action.value = next;
  reason.value = '';
  typedName.value = '';
}

function close(): void {
  action.value = null;
  reason.value = '';
  typedName.value = '';
}

async function confirm(): Promise<void> {
  if (!member.value || !action.value || !confirmed.value || busy.value) return;
  busy.value = true;
  const currentAction = action.value;
  try {
    if (currentAction === 'suspend') await store.suspendStaff(member.value.id, reason.value.trim());
    if (currentAction === 'activate') await store.activateStaff(member.value.id);
    if (currentAction === 'deactivate') await store.deactivateStaff(member.value.id, reason.value.trim());
    const status = currentAction === 'activate' ? 'active' : currentAction === 'suspend' ? 'suspended' : 'deactivated';
    notifications.addToast({ type: 'success', message: 'Staff access is now ' + status + '.' });
    close();
  } catch {
    notifications.addToast({ type: 'error', message: 'The lifecycle change was not accepted by the server.' });
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  if (id.value) void store.fetchStaffMember(id.value);
});
</script>

<template>
  <section
    class="mx-auto max-w-4xl"
    data-testid="hr-staff-lifecycle"
  >
    <SvPageHeader
      :title="member ? member.display_name + ' lifecycle' : 'Staff lifecycle'"
      eyebrow="Access and employment lifecycle"
      description="Server-enforced activation, suspension, reactivation and deactivation for a staff member in your assigned branch."
    />

    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="This staff profile is not available in your assigned branch."
      @retry="store.fetchStaffMember(id)"
    >
      <template v-if="member">
        <SvCard as="section">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Current access state
              </p>
              <h2 class="mt-1 font-display text-xl font-bold text-heading">
                {{ member.display_name }}
              </h2>
              <p class="text-sm capitalize text-text-muted">
                {{ member.role?.replaceAll('_', ' ') ?? 'No role' }} · {{ member.employment_status.replaceAll('_', ' ') }}
              </p>
            </div>
            <SvStatusBadge
              :label="member.status?.replaceAll('_', ' ') ?? 'Unknown'"
              :tone="member.status === 'active' ? 'success' : member.status === 'deactivated' ? 'error' : 'warning'"
            />
          </div>
          <SvAlert
            class="mt-5"
            severity="warning"
            title="Immediate access effect"
          >
            Suspension and deactivation revoke sessions, unused Magic Links and pending access. Historical staff, compensation and payout records are preserved.
          </SvAlert>
          <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <SvButton
              variant="secondary"
              :disabled="member.status !== 'active'"
              @click="open('suspend')"
            >
              Suspend
            </SvButton>
            <SvButton
              :disabled="member.status !== 'suspended'"
              @click="open('activate')"
            >
              Reactivate
            </SvButton>
            <SvButton
              variant="destructive"
              :disabled="member.status === 'deactivated'"
              @click="open('deactivate')"
            >
              Deactivate
            </SvButton>
          </div>
        </SvCard>

        <SvCard
          as="section"
          class="mt-4"
        >
          <h2 class="font-display text-lg font-bold text-heading">
            Authority boundary
          </h2>
          <p class="mt-2 text-sm text-text-muted">
            Human Resource can manage operational staff only in this assigned branch. This page cannot change your own authority, activate a Merchant Administrator or move staff across branches.
          </p>
        </SvCard>
      </template>
    </SvStateBoundary>

    <SvDialog
      :open="action !== null"
      :title="action ? copy[action].title : ''"
      :description="action ? copy[action].description : ''"
      :persistent="busy"
      @close="close"
    >
      <div
        v-if="member && action"
        class="space-y-4"
      >
        <SvTextInput
          v-if="reasonRequired"
          id="lifecycle-reason"
          v-model="reason"
          label="Reason"
          required
          :maxlength="500"
          help="Recorded with the lifecycle event."
        />
        <SvTextInput
          id="lifecycle-confirm-name"
          v-model="typedName"
          :label="'Type “' + member.display_name + '” to confirm'"
          required
          autocomplete="off"
        />
      </div>
      <template #footer>
        <SvButton
          variant="secondary"
          :disabled="busy"
          @click="close"
        >
          Cancel
        </SvButton>
        <SvButton
          :variant="action === 'activate' ? 'primary' : 'destructive'"
          :loading="busy"
          :disabled="!confirmed"
          @click="confirm"
        >
          {{ action ? copy[action].button : 'Confirm' }}
        </SvButton>
      </template>
    </SvDialog>
  </section>
</template>
