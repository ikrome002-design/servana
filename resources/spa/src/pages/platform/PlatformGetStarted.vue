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
import SvFeaturePanel from '@/components/refined/SvFeaturePanel.vue';
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
import { navigationIcon } from '@/navigation/navigationIcons';

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
    class="sv-r-page"
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
        class="sv-r-panel sv-r-panel--tonal sv-r-tone-sun mx-auto max-w-xl text-center"
        data-testid="get-started-dismissed"
      >
        <span
          aria-hidden="true"
          class="sv-r-icon sv-r-icon--lg sv-r-tone-sun mx-auto"
        >
          <component :is="navigationIcon('get-started')" />
        </span>
        <h2 class="sv-r-title mt-4">
          The setup guide is hidden
        </h2>
        <p class="sv-r-lede mt-1">
          You hid the setup guide. Your progress is unchanged and you can reopen it at any time.
        </p>
        <SvButton
          variant="primary"
          class="mt-5"
          data-testid="get-started-reopen"
          @click="reopen"
        >
          Reopen the setup guide
        </SvButton>
      </div>
    </template>

    <template v-else>
      <p
        class="sv-r-muted -mt-2 mb-5"
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
        COR-UI08-UI12-VIS-001 — a leadership checklist. The progress NARRATIVE leads on the dark
        feature surface: the server's complete/total, a meter drawn from those two numbers, the
        blocked-step caveat, and the next step as the one dominant action. Below, the steps run down
        a dependency rail; each marker states done / open / blocked, and every card keeps its
        server evidence line, dependency warning and direct link.
      -->
      <div
        v-else
        class="sv-r-stack"
      >
        <SvFeaturePanel
          as="div"
          eyebrow="Setup progress"
          data-testid="get-started-progress"
        >
          <div class="mt-4 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] lg:items-end">
            <div>
              <p class="sv-r-feature__value sv-r-figure-xl">
                {{ store.progress.complete }}<span class="sv-r-feature__hint text-2xl"> / {{ store.progress.total }}</span>
              </p>
              <p class="sv-r-feature__label mt-2">
                {{ store.progress.complete }} of {{ store.progress.total }} steps complete
              </p>
              <div
                class="mt-4 h-2 overflow-hidden rounded-pill bg-white/15"
                aria-hidden="true"
              >
                <span
                  class="block h-full rounded-pill bg-sv-accent"
                  :style="{ width: `${store.progress.total > 0 ? (store.progress.complete / store.progress.total) * 100 : 0}%` }"
                />
              </div>
              <p
                v-if="store.progress.blocked > 0"
                class="sv-r-feature__hint mt-3"
              >
                {{ store.progress.blocked }} step is blocked by an external dependency and is not
                counted against your progress.
              </p>
            </div>
            <div
              v-if="store.nextStep"
              class="sv-r-feature__cell"
            >
              <p class="sv-r-feature__eyebrow">
                Up next
              </p>
              <p class="sv-r-feature__title">
                {{ store.nextStep.order }}. {{ store.nextStep.label }}
              </p>
              <button
                type="button"
                class="sv-r-feature-action sv-focus-ring mt-4"
                data-testid="get-started-next"
                @click="$router.push({ name: store.nextStep.routeName as string })"
              >
                Open the next step
              </button>
            </div>
          </div>
        </SvFeaturePanel>

        <!-- Steps -------------------------------------------------------------------------- -->
        <ol
          class="sv-r-steps"
          data-testid="get-started-steps"
        >
          <li
            v-for="step in store.steps"
            :key="step.id"
            class="sv-r-step"
            :class="{
              'sv-r-step--done': step.state === 'complete',
              'sv-r-step--current': store.nextStep?.id === step.id,
              'sv-r-step--future': step.state === 'blocked_by_gate',
            }"
            :data-state="step.state"
            :data-testid="`get-started-step-${step.id}`"
          >
            <span
              aria-hidden="true"
              class="sv-r-step__marker"
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
            <div
              class="sv-r-record"
              :class="step.state === 'blocked_by_gate' ? 'sv-r-record--dashed' : ''"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="sv-r-title">
                    {{ step.order }}. {{ step.label }}
                  </p>
                  <p class="sv-r-lede mt-1">
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
                class="mt-3 rounded-control bg-sv-surface-subtle px-3 py-2 text-xs text-sv-text-secondary"
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
                  class="sv-r-link sv-focus-ring rounded-control"
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
                  class="sv-r-chip"
                  :data-testid="`get-started-gate-${step.id}`"
                >
                  Blocked by {{ step.gate }}
                </span>
              </div>
            </div>
          </li>
        </ol>
      </div>
    </template>
  </div>
</template>