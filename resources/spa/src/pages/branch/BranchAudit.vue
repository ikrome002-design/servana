<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';
import { humanizeAction, severityPresentation } from '@/utils/auditPresentation';
import { formatDateTime } from '@/utils/dates';

const store = useBranchExperienceStore();
const state = computed(() => store.loading ? 'loading' : store.error ? 'error' : store.auditEvents.length ? 'success' : 'empty');
// UI-16: branch audit rows are `audit_logs` rows — the shared AuditSeverity presentation gives the
// label and tone, and the readable action uses the same humanisation the Audit account shows.
const tone = (severity: string) => severityPresentation(severity).tone;

/** A severity filter over the events already loaded — presentation only, the server is not re-queried. */
const severity = ref<string>('all');
const severityOptions = computed(() => [
  { value: 'all', label: 'All severities' },
  ...[...new Set(store.auditEvents.map((event) => event.severity))].map((value) => ({ value, label: severityPresentation(value).label })),
]);
const visibleEvents = computed(() => severity.value === 'all'
  ? store.auditEvents
  : store.auditEvents.filter((event) => event.severity === severity.value));

onMounted(() => { void store.fetchAudit({ sort: '-created_at' }); });
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — an efficient, observational audit timeline. Severity chips narrow the
    events already loaded (presentation only); each event sits on a time rail with its readable
    action, masked actor and subject, severity and time; the rail states that raw review, flagging
    and exports stay with the Audit account.
  -->
  <section
    class="sv-r-page"
    data-testid="branch-audit"
  >
    <SvPageHeader
      title="Branch audit"
      eyebrow="Operational visibility"
      description="A masked, read-only timeline for your assigned branch. Raw audit review, flagged-event workflow and exports stay with the Audit account."
    />
    <div class="sv-r-grid sv-r-split">
      <SvStateBoundary
        :state="state"
        :error-message="store.error ?? undefined"
        empty-message="No branch audit events are available."
        @retry="store.fetchAudit({ sort: '-created_at' })"
      >
        <div
          class="mb-4 flex flex-wrap gap-2"
          role="group"
          aria-label="Filter by severity"
        >
          <button
            v-for="option in severityOptions"
            :key="option.value"
            type="button"
            class="sv-focus-ring min-h-sv-touch rounded-pill border px-4 text-sm font-semibold transition-colors duration-sv-fast"
            :class="severity === option.value ? 'border-transparent bg-sv-nav-active-bg text-sv-nav-active-fg' : 'border-sv-border bg-sv-surface-raised text-sv-text-secondary hover:bg-sv-surface-subtle'"
            :aria-pressed="severity === option.value"
            @click="severity = option.value"
          >
            {{ option.label }}
          </button>
        </div>
        <ol
          class="relative grid gap-3 border-l-2 border-sv-border pl-6"
          aria-label="Branch audit timeline"
        >
          <li
            v-for="event in visibleEvents"
            :key="event.id"
            class="relative"
          >
            <span
              class="absolute -left-[1.95rem] top-5 h-3.5 w-3.5 rounded-full border-2 border-sv-surface-raised bg-sv-brand shadow-card"
              aria-hidden="true"
            />
            <article class="sv-r-record">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 class="font-display font-bold text-heading">
                    {{ humanizeAction(event.action) }}
                  </h2>
                  <p class="mt-0.5 break-all font-mono text-xs text-sv-text-muted">
                    <span class="sr-only">Action token: </span>{{ event.action }}
                  </p>
                  <p class="mt-1 text-sm text-text-muted">
                    {{ event.actor ?? 'System' }} · {{ event.subject_type ?? 'Branch record' }}
                  </p>
                </div>
                <SvStatusBadge
                  :label="severityPresentation(event.severity).label"
                  :tone="tone(event.severity)"
                />
              </div>
              <time
                class="mt-3 block text-xs text-text-muted"
                :datetime="event.created_at ?? undefined"
              >{{ event.created_at ? formatDateTime(event.created_at) : 'Time unavailable' }}</time>
            </article>
          </li>
        </ol>
      </SvStateBoundary>

      <aside
        class="sv-r-stack-sm sv-r-sticky"
        aria-label="Audit role"
      >
        <div class="sv-r-note sv-r-tone-teal">
          <p class="sv-r-note__title">
            Observation only
          </p>
          <p class="sv-r-note__body">
            Values arrive masked from the server. Raw audit review, flagged-event workflow and exports belong to the Audit account.
          </p>
        </div>
      </aside>
    </div>
  </section>
</template>