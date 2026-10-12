<script setup lang="ts">
/**
 * Get Started — Super Administrator contract page §5.4.2 (Phase UI-08).
 *
 * Guides initial platform governance configuration in the CORRECT DEPENDENCY ORDER, and proves
 * each step from the server rather than from a checkbox.
 *
 * ## The rule this page exists to honour
 *
 * A client-only boolean must never claim a server-owned step is done. An administrator who ticked
 * "billing mode configured" on one laptop would otherwise see a configured platform that is not
 * configured at all — and would then be surprised by the first failed billing run. Every step
 * therefore renders the evidence that produced its state.
 *
 * Exactly ONE step is user-markable: reviewing registration monitoring, because the platform
 * cannot observe that a human read something. It reuses the shipped `getStartedStore` persistence
 * and the shipped `review-registration-monitoring` item id, so dismissal, resume and reopen behave
 * exactly as they already do for the other seven accounts. No new table, no new endpoint.
 *
 * The Wallet/R&E step is `blocked_by_gate` and is not completable at all — not by evidence,
 * because none exists, and not by hand, because that would assert an unreachable integration is
 * verified.
 */
import { computed, onMounted, watch } from 'vue';
import { RouterLink } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { useCan } from '@/composables/useCan';
import { useAuthStore } from '@/stores/authStore';
import { useGetStartedStore } from '@/stores/getStartedStore';
import { usePlatformGetStartedStore, type GetStartedStep } from '@/stores/platformGetStartedStore';
import { SvIconCheck, SvIconLocked } from '@/design-system/icons';

const store = usePlatformGetStartedStore();
const persistence = useGetStartedStore();
const auth = useAuthStore();
const { can } = useCan();

const IDENTITY = 'super_administrator' as const;

const canView = computed(() => can('platform.billing_settings.view'));
const userId = computed(() => auth.user?.id ?? null);

const dismissed = computed(() =>
  userId.value === null ? false : persistence.isDismissed(userId.value, IDENTITY),
);

/** Feed the server-evidence store the acknowledgements the shared persistence already holds. */
function syncAcknowledgements(): void {
  if (userId.value === null) return;
  store.setAcknowledged(persistence.read(userId.value, IDENTITY).completed);
}

onMounted(() => {
  if (!canView.value) return;
  syncAcknowledgements();
  void store.load();
});

watch(userId, syncAcknowledgements);

function markReviewed(step: GetStartedStep): void {
  if (userId.value === null || !step.manuallyCompletable) return;
  persistence.setCompleted(userId.value, IDENTITY, step.id, true);
  syncAcknowledgements();
}

function dismiss(): void {
  if (userId.value !== null) persistence.dismiss(userId.value, IDENTITY);
}

function reopen(): void {
  if (userId.value !== null) persistence.reopen(userId.value, IDENTITY);
}

const badge = (step: GetStartedStep): { label: string; tone: 'success' | 'warning' | 'neutral' } => {
  if (step.state === 'complete') return { label: 'Complete', tone: 'success' };
  if (step.state === 'blocked_by_gate') return { label: 'Blocked', tone: 'warning' };
  return { label: 'Not started', tone: 'neutral' };
};
</script>

<template>
  <div
    class="mx-auto w-full max-w-6xl"
    data-testid="platform-get-started-screen"
  >
    <SvPageHeader
      title="Get started"
      eyebrow="Home"
      description="Configure platform governance in the order each step depends on. Progress is read from the platform itself, not from a checklist you tick."
    >
      <template #actions>
        <SvButton
          v-if="!dismissed && store.allCompletableDone"
          variant="secondary"
          data-testid="get-started-dismiss"
          @click="dismiss"
        >
          Hide this guide
        </SvButton>
      </template>
    </SvPageHeader>

    <SvPermissionState v-if="!canView" />

    <template v-else-if="dismissed">
      <div
        class="rounded-card border border-sv-border bg-sv-surface-raised p-6 text-center"
        data-testid="get-started-dismissed"
      >
        <h2 class="font-display text-lg font-bold text-sv-text-heading">
          The setup guide is hidden
        </h2>
        <p class="mt-1 text-sm text-sv-text-muted">
          You hid the setup guide. Your progress is unchanged and you can reopen it at any time.
        </p>
        <SvButton
          variant="primary"
          class="mt-4"
          data-testid="get-started-reopen"
          @click="reopen"
        >
          Reopen the setup guide
        </SvButton>
      </div>
    </template>

    <template v-else>
      <p
        class="mb-4 text-xs text-sv-text-muted"
        data-testid="get-started-last-refreshed"
      >
        Progress checked
        <SvDateTime :value="store.lastRefreshed" />
      </p>

      <SvAlert
        v-if="store.error"
        severity="error"
        title="We could not check your setup progress"
        class="mb-6"
      >
        <p>{{ store.error }}</p>
        <SvButton
          variant="secondary"
          size="sm"
          class="mt-3"
          data-testid="get-started-retry"
          @click="store.load()"
        >
          Try again
        </SvButton>
      </SvAlert>

      <SvSkeleton
        v-else-if="store.loading"
        shape="text"
        :lines="7"
        label="Checking your platform setup"
      />

      <!--
        UI-16 (UI16-VIS-018): the guide was a single column of identical bordered boxes. It now
        mirrors the merchant checklist — a progress panel that names the next step beside numbered
        step cards whose marker states done / open / blocked — with the same server-read states,
        evidence lines and controls.
      -->
      <div
        v-else
        class="grid gap-5 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)] lg:items-start"
      >
        <!-- Progress ---------------------------------------------------------------------- -->
        <div
          class="sv-pgs-progress rounded-card border border-sv-border p-5 shadow-card lg:sticky lg:top-4"
          data-testid="get-started-progress"
        >
          <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
            Setup progress
          </p>
          <p class="mt-1 font-display text-3xl font-extrabold leading-none text-sv-text-heading">
            {{ store.progress.complete }}<span class="text-lg text-sv-text-muted"> / {{ store.progress.total }}</span>
          </p>
          <p class="mt-1 text-sm font-medium text-sv-text">
            {{ store.progress.complete }} of {{ store.progress.total }} steps complete
          </p>
          <p
            v-if="store.progress.blocked > 0"
            class="mt-1 text-xs text-sv-text-muted"
          >
            {{ store.progress.blocked }} step is blocked by an external dependency and is not
            counted against your progress.
          </p>

          <SvButton
            v-if="store.nextStep"
            variant="primary"
            class="mt-3"
            data-testid="get-started-next"
            @click="$router.push({ name: store.nextStep.routeName as string })"
          >
            Open the next step
          </SvButton>
        </div>

        <!-- Steps -------------------------------------------------------------------------- -->
        <ol
          class="flex flex-col gap-3"
          data-testid="get-started-steps"
        >
          <li
            v-for="step in store.steps"
            :key="step.id"
            class="sv-pgs-step grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 rounded-card border p-4 shadow-card"
            :data-state="step.state"
            :data-testid="`get-started-step-${step.id}`"
          >
            <span
              aria-hidden="true"
              class="sv-pgs-step__marker row-span-4 inline-flex h-9 w-9 items-center justify-center rounded-full font-display text-sm font-extrabold"
            >
              <SvIconCheck
                v-if="step.state === 'complete'"
                class="h-5 w-5"
              />
              <SvIconLocked
                v-else-if="step.state === 'blocked_by_gate'"
                class="h-4 w-4"
              />
              <template v-else>{{ step.order }}</template>
            </span>
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="font-semibold text-sv-text-heading">
                  {{ step.order }}. {{ step.label }}
                </p>
                <p class="mt-1 text-sm text-sv-text-muted">
                  {{ step.description }}
                </p>
              </div>
              <SvStatusBadge
                :label="badge(step).label"
                :tone="badge(step).tone"
                sr-prefix="Step status:"
              />
            </div>

            <!-- The evidence. Always shown, so a state is never unexplained. -->
            <p
              class="mt-3 text-xs text-sv-text-muted"
              :data-testid="`get-started-evidence-${step.id}`"
            >
              {{ step.evidence }}
            </p>

            <p
              v-if="step.dependencyWarning"
              class="mt-2 text-xs font-medium text-sv-text"
              :data-testid="`get-started-dependency-${step.id}`"
            >
              {{ step.dependencyWarning }}
            </p>

            <div class="mt-3 flex flex-wrap items-center gap-3">
              <RouterLink
                v-if="step.routeName"
                :to="{ name: step.routeName }"
                class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-medium text-sv-link"
                :data-testid="`get-started-open-${step.id}`"
              >
                Open
              </RouterLink>

              <SvButton
                v-if="step.manuallyCompletable && step.state !== 'complete'"
                variant="secondary"
                size="sm"
                :data-testid="`get-started-mark-${step.id}`"
                @click="markReviewed(step)"
              >
                Mark as reviewed
              </SvButton>

              <span
                v-if="step.state === 'blocked_by_gate'"
                class="text-xs font-medium text-sv-text-muted"
                :data-testid="`get-started-gate-${step.id}`"
              >
                Blocked by {{ step.gate }}
              </span>
            </div>
          </li>
        </ol>
      </div>
    </template>
  </div>
</template>

<style scoped>
.sv-pgs-progress {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-accent) 22%, transparent), transparent 55%),
    var(--sv-color-surface-raised);
}
.sv-pgs-step {
  border-color: var(--sv-color-border-default);
  background: var(--sv-color-surface-raised);
}
.sv-pgs-step__marker {
  background: var(--sv-color-surface-subtle);
  color: var(--sv-color-text-heading);
}
.sv-pgs-step[data-state='complete'] { border-color: var(--sv-color-status-success-border); }
.sv-pgs-step[data-state='complete'] .sv-pgs-step__marker {
  background: var(--sv-color-growth);
  color: var(--sv-color-text-inverse);
}
.sv-pgs-step[data-state='incomplete'] .sv-pgs-step__marker {
  background: color-mix(in srgb, var(--sv-color-brand-primary) 18%, var(--sv-color-surface-raised));
}
.sv-pgs-step[data-state='blocked_by_gate'] { border-style: dashed; }
</style>
