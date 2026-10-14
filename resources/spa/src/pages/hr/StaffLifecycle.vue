<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
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

/** The access states the lifecycle rail shows, in order. Transitions are decided by the server. */
const STATES = ['active', 'suspended', 'deactivated'] as const;
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the lifecycle as a STATE MAP. The person's current access state is set
    on a rail of the real states (active, suspended, deactivated) with the allowed transitions
    between them; each transition is its own card that says what it does and is enabled only from
    the state that allows it; the immediate-effect warning and the authority boundary frame them.
    Every change still requires the typed confirmation and is decided by the server.
  -->
  <section
    class="sv-r-page"
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
      <div
        v-if="member"
        class="sv-r-stack"
      >
        <section class="sv-r-panel sv-r-panel--tonal sv-r-tone-green">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p class="sv-r-eyebrow">
                Current access state
              </p>
              <h2 class="sv-r-title-lg mt-1">
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
          <ol
            class="mt-6 grid grid-cols-3 items-center gap-2"
            aria-label="Lifecycle states"
          >
            <li
              v-for="stage in STATES"
              :key="stage"
              class="rounded-card border-2 px-3 py-3 text-center text-sm font-semibold capitalize"
              :class="member.status === stage ? 'border-sv-brand bg-sv-surface-warm text-sv-text-heading' : 'border-sv-border bg-sv-surface-raised text-sv-text-muted'"
              :aria-current="member.status === stage ? 'step' : undefined"
            >
              {{ stage }}
            </li>
          </ol>
        </section>

        <SvAlert
          severity="warning"
          title="Immediate access effect"
        >
          Suspension and deactivation revoke sessions, unused Magic Links and pending access. Historical staff, compensation and payout records are preserved.
        </SvAlert>

        <div class="sv-r-grid sv-r-cols-3">
          <article class="sv-r-record flex flex-col gap-3">
            <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-sun">
              Active → suspended
            </p>
            <p class="sv-r-lede">
              Pause access while keeping the record. Allowed only from active.
            </p>
            <SvButton
              class="mt-auto"
              variant="secondary"
              :disabled="member.status !== 'active'"
              @click="open('suspend')"
            >
              Suspend
            </SvButton>
          </article>
          <article class="sv-r-record flex flex-col gap-3">
            <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green">
              Suspended → active
            </p>
            <p class="sv-r-lede">
              Restore access for a suspended staff member.
            </p>
            <SvButton
              class="mt-auto"
              :disabled="member.status !== 'suspended'"
              @click="open('activate')"
            >
              Reactivate
            </SvButton>
          </article>
          <article class="sv-r-record flex flex-col gap-3">
            <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-alert">
              Any → deactivated
            </p>
            <p class="sv-r-lede">
              End access permanently; history is preserved.
            </p>
            <SvButton
              class="mt-auto"
              variant="destructive"
              :disabled="member.status === 'deactivated'"
              @click="open('deactivate')"
            >
              Deactivate
            </SvButton>
          </article>
        </div>

        <section class="sv-r-note sv-r-tone-teal">
          <h2 class="sv-r-note__title">
            Authority boundary
          </h2>
          <p class="sv-r-note__body">
            Human Resource can manage operational staff only in this assigned branch. This page cannot change your own authority, activate a Merchant Administrator or move staff across branches.
          </p>
        </section>
      </div>
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
