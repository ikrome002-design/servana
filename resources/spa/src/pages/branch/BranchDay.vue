<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvFeaturePanel from '@/components/refined/SvFeaturePanel.vue';
import SvKpiCard from '@/components/refined/SvKpiCard.vue';
import SvPanel from '@/components/refined/SvPanel.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { SvIconWarning } from '@/design-system/icons';
import { navigationIcon } from '@/navigation/navigationIcons';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';
import { formatDateTime } from '@/utils/dates';

const store = useBranchExperienceStore();
const busy = ref(false);
const actionError = ref<string | null>(null);
const state = computed(() => store.loading ? 'loading' : store.error ? 'error' : store.overview ? 'success' : 'empty');
const day = computed(() => store.overview?.day ?? null);
const isLive = computed(() => ['open', 'paused', 'reopened'].includes(day.value?.status ?? ''));
const blockers = computed(() => [...new Set([...(day.value?.close_blockers ?? []), ...(day.value?.financial_close_blockers ?? [])])]);

/**
 * The day's lifecycle as the rail shows it. Each stage lists the server BranchDayStatus values it
 * covers (not_opened, open, paused, reopened, closed) — the rail never invents a state.
 */
const STAGES: ReadonlyArray<{ key: string; label: string; match: readonly string[] }> = [
  { key: 'before', label: 'Not opened', match: ['not_opened'] },
  { key: 'live', label: 'Open · paused · reopened', match: ['open', 'paused', 'reopened'] },
  { key: 'closed', label: 'Closed', match: ['closed'] },
];

function humanize(value: string): string {
  const label = value.replaceAll('_', ' ');
  return label.charAt(0).toUpperCase() + label.slice(1);
}

async function transition(): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    await store.transitionDay(isLive.value ? 'close' : 'open');
  } catch (error: unknown) {
    actionError.value = axios.isAxiosError(error) && error.apiError
      ? error.apiError.message
      : 'The branch-day state could not be changed.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => { void store.fetchOverview(); });
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — Branch Day as an operational control room. The current state leads on
    the dark feature surface with the ONE deliberate action (open or close the day) beside it —
    disabled, with the reason in view, while close blockers remain; a state rail shows where the
    day sits in its lifecycle; close readiness and the cash-up maker step sit side by side. The
    transition is still the server's: the button only asks.
  -->
  <section
    class="sv-r-page"
    data-testid="branch-day"
  >
    <SvPageHeader
      title="Branch day"
      eyebrow="Branch operations"
      :description="store.overview ? `${store.overview.branch.name} · ${store.overview.business_date}` : 'Open, monitor and close the Nairobi business day.'"
    />
    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No assigned branch is available."
      @retry="store.fetchOverview()"
    >
      <div
        v-if="store.overview && day"
        class="sv-r-stack"
      >
        <SvAlert
          v-if="actionError"
          severity="error"
          title="Day action unavailable"
        >
          {{ actionError }}
        </SvAlert>

        <SvFeaturePanel
          as="div"
          eyebrow="Current state"
        >
          <div class="mt-3 grid gap-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div>
              <p class="sv-r-feature__value sv-r-figure-xl capitalize">
                {{ day.status.replaceAll('_', ' ') }}
              </p>
              <div class="mt-4 flex flex-wrap gap-2">
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white">Queue {{ day.queue_is_open ? 'open' : 'closed' }}</span>
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white">Opened {{ day.opened_at ? formatDateTime(day.opened_at) : 'Not opened' }}</span>
              </div>
            </div>
            <div class="flex flex-col items-start gap-2 lg:items-end">
              <SvButton
                variant="primary"
                size="lg"
                :loading="busy"
                :disabled="isLive && blockers.length > 0"
                @click="transition"
              >
                {{ isLive ? 'Close branch day' : 'Open branch day' }}
              </SvButton>
              <p
                v-if="isLive && blockers.length > 0"
                class="sv-r-feature__hint"
              >
                Resolve the close blockers below first.
              </p>
            </div>
          </div>
          <ol
            class="mt-6 grid grid-cols-3 gap-2 text-xs font-semibold"
            aria-label="Day lifecycle"
          >
            <li
              v-for="stage in STAGES"
              :key="stage.key"
              class="rounded-control px-3 py-2 text-center"
              :class="stage.match.includes(day.status) ? 'bg-sv-accent text-sv-text-on-brand' : 'bg-white/10 text-white/80'"
              :aria-current="stage.match.includes(day.status) ? 'step' : undefined"
            >
              {{ stage.label }}
            </li>
          </ol>
        </SvFeaturePanel>

        <div class="sv-r-grid sv-r-split-wide">
          <SvPanel
            eyebrow="Before close"
            title="Close readiness"
            tone="green"
          >
            <p
              v-if="blockers.length === 0"
              class="rounded-control bg-sv-success-bg px-3 py-2 text-sm font-semibold text-sv-success-fg"
              role="status"
            >
              No current close blockers.
            </p>
            <ul
              v-else
              class="grid gap-2 md:grid-cols-2"
              aria-label="Close blockers"
            >
              <li
                v-for="blocker in blockers"
                :key="blocker"
                class="flex items-center gap-2 rounded-control border border-sv-warning-border bg-sv-warning-bg px-3 py-2 text-sm font-semibold text-sv-warning-fg"
              >
                <SvIconWarning
                  aria-hidden="true"
                  class="h-4 w-4 shrink-0"
                />
                {{ humanize(blocker) }}
              </li>
            </ul>
            <p class="sv-r-muted mt-4">
              Branch submits the cash-up as maker. Finance approval remains the independent checker before financial close.
            </p>
          </SvPanel>

          <SvKpiCard
            label="Cash-up"
            tone="sun"
            :icon="navigationIcon('cash-up')"
            :to="{ name: 'branch.cash-up' }"
            link-label="Prepare cash-up"
            hint="You prepare it as maker; Finance checks it."
          >
            <span class="capitalize">{{ store.overview.cash_up?.status.replaceAll('_', ' ') ?? 'Not prepared' }}</span>
          </SvKpiCard>
        </div>
      </div>
    </SvStateBoundary>
  </section>
</template>