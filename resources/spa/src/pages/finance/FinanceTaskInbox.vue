<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import { SvIconChevronRight, SvIconSecurity } from '@/design-system/icons';
import { useFinanceWorkspaceStore, type FinanceWorkspaceTask } from '@/stores/financeWorkspaceStore';

const store = useFinanceWorkspaceStore();
const severity = ref('');
const overview = computed(() => store.overview);
const state = computed(() => (store.overviewLoading ? 'loading' : store.overviewError ? 'error' : overview.value ? 'success' : 'empty'));
const RANK = { critical: 0, high: 1, medium: 2 } as const;
const tasks = computed(() => (overview.value?.tasks ?? [])
  .filter((task) => severity.value === '' || task.severity === severity.value)
  .sort((a, b) => RANK[a.severity] - RANK[b.severity] || b.count - a.count));

const severityOptions = [
  { value: '', label: 'All priorities' },
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
];

/** Severity lanes: the server's counts summed per severity, never re-weighted. */
const lanes = computed(() => (['critical', 'high', 'medium'] as const).map((level) => ({
  level,
  label: level.charAt(0).toUpperCase() + level.slice(1),
  total: (overview.value?.tasks ?? []).filter((task) => task.severity === level).reduce((sum, task) => sum + task.count, 0),
  tone: level === 'critical' ? 'sv-r-tone-alert' : level === 'high' ? 'sv-r-tone-sun' : 'sv-r-tone-teal',
})));

function tone(value: string): SvStatusTone {
  if (value === 'critical') return 'error';
  if (value === 'high') return 'warning';
  return 'info';
}

function laneTone(task: FinanceWorkspaceTask): string {
  return task.severity === 'critical' ? 'sv-r-tone-alert' : task.severity === 'high' ? 'sv-r-tone-sun' : 'sv-r-tone-teal';
}

onMounted(() => {
  void store.fetchOverview();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — a RANKED inbox. Three severity lanes summarise the server's counts; the
    ranked list orders critical → high → medium and then by volume, each row carrying its severity,
    the maker/checker owner, any fresh step-up requirement and the queue it opens. Item age is not
    reported by the overview endpoint, so the page says so instead of inventing one; assignment and
    claim controls stay absent because no task-assignment runtime exists.
  -->
  <section
    class="sv-r-page"
    data-testid="finance-task-inbox"
  >
    <SvPageHeader
      title="Finance task inbox"
      eyebrow="Prioritized control work"
      description="A server-derived view of shipped Finance queues. Assignment and claim controls are absent because no authoritative task-assignment runtime exists."
    >
      <template #actions>
        <div class="w-48">
          <SvSelect
            id="finance-task-priority"
            v-model="severity"
            label="Priority"
            :options="severityOptions"
          />
        </div>
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.overviewError ?? undefined"
      empty-message="No assigned Finance branch is available."
      @retry="store.fetchOverview()"
    >
      <div
        v-if="overview"
        class="sv-r-grid sv-r-split"
      >
        <div class="sv-r-stack min-w-0">
          <div class="sv-r-grid-tight md:grid-cols-3">
            <article
              v-for="lane in lanes"
              :key="lane.level"
              class="sv-r-kpi"
              :class="lane.tone"
            >
              <div class="sv-r-kpi__top">
                <p class="sv-r-kpi__label sv-r-eyebrow--dot">
                  {{ lane.label }}
                </p>
              </div>
              <p class="sv-r-kpi__value">
                {{ lane.total }}
              </p>
              <p class="sv-r-kpi__hint">
                items in {{ lane.level }} queues
              </p>
            </article>
          </div>

          <section aria-labelledby="finance-ranked-heading">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
              <div>
                <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal">
                  Ranked by severity, then volume
                </p>
                <h2
                  id="finance-ranked-heading"
                  class="sv-r-title mt-1"
                >
                  Control queues
                </h2>
              </div>
              <span class="sv-r-chip">{{ tasks.length }} queues</span>
            </div>
            <ol
              class="sv-r-queue"
              aria-label="Finance tasks"
            >
              <li
                v-for="(task, index) in tasks"
                :key="task.key"
                class="sv-r-queue__row"
                :class="laneTone(task)"
              >
                <span
                  aria-hidden="true"
                  class="w-6 font-display text-sm font-extrabold text-sv-text-muted"
                >{{ String(index + 1).padStart(2, '0') }}</span>
                <span
                  class="sv-r-queue__count"
                  :aria-label="`${task.count} items`"
                >{{ task.count }}</span>
                <span class="sv-r-queue__label">
                  <span class="flex flex-wrap items-center gap-2">
                    {{ task.label }}
                    <SvStatusBadge
                      :label="task.severity"
                      :tone="tone(task.severity)"
                      size="sm"
                      sr-prefix="Priority:"
                    />
                  </span>
                  <span class="sv-r-queue__detail flex flex-wrap items-center gap-2">
                    Owner: {{ task.maker_checker }} · server authorization remains the boundary
                    <span
                      v-if="task.step_up_required"
                      class="inline-flex items-center gap-1 rounded-pill border border-sv-warning-border bg-sv-warning-bg px-2 py-px font-semibold text-sv-warning-fg"
                    ><SvIconSecurity
                      aria-hidden="true"
                      class="h-3.5 w-3.5"
                    />Fresh step-up</span>
                  </span>
                </span>
                <RouterLink
                  class="sv-r-action sv-r-action--quiet sv-focus-ring shrink-0"
                  :to="{ name: task.route_name }"
                >
                  Open queue<SvIconChevronRight
                    aria-hidden="true"
                    class="h-4 w-4"
                  />
                </RouterLink>
              </li>
              <li
                v-if="tasks.length === 0"
                class="sv-r-panel sv-r-panel--dashed text-sm text-sv-text-muted"
              >
                No Finance queues match this priority.
              </li>
            </ol>
          </section>
        </div>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="How the inbox is ranked"
        >
          <section class="sv-r-note sv-r-tone-teal">
            <h2 class="sv-r-note__title">
              Ranking
            </h2>
            <p class="sv-r-note__body">
              Critical queues first, then high, then medium; inside a severity the larger queue leads. Counts and severity come from the server.
            </p>
          </section>
          <section class="sv-r-note sv-r-tone-sun">
            <h2 class="sv-r-note__title">
              Aging
            </h2>
            <p class="sv-r-note__body">
              Item age is not reported by this overview. Each queue opens oldest first, so the item that has waited longest is always at the top.
            </p>
          </section>
          <section class="sv-r-note sv-r-tone-neutral">
            <h2 class="sv-r-note__title">
              Ownership
            </h2>
            <p class="sv-r-note__body">
              The owner shown is the maker/checker role that may act. No claim or assignment is recorded here.
            </p>
          </section>
        </aside>
      </div>
    </SvStateBoundary>
  </section>
</template>
