<script setup lang="ts">
import { computed, onMounted } from 'vue';
import AuditEventCard from '@/components/audit/AuditEventCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useRefinedExperience } from '@/design-system/experience';
import { SvIconClock } from '@/design-system/icons';
import { useAuditEventStore, type AuditDomain } from '@/stores/auditEventStore';

/**
 * Shared read-only, branch-scoped, masked audit-event panel for a single domain
 * segment (Plan §70; Phase 19). Used by the finance and compensation Audit views.
 * The server enforces the domain permission (audit.finance.view / audit.compensation
 * .view, or finance.audit.view for Finance) and branch scope; merchant-level
 * (branch_id = null) rows are never returned. When a domain has no events yet
 * (e.g. compensation until its owning phases), it shows an honest empty state —
 * no events are ever fabricated.
 *
 * UI-16 (UI16-VIS-015): rendered with the tonal page header and the UI-15 AuditEventCard — time-led,
 * the readable action above its exact token, the real severity badge — instead of a raw action
 * token, a bare severity word and an ISO timestamp. The card is the same masked, read-only record;
 * it only links to evidence when this shell has a detail route, and never offers a flag control.
 */
const props = defineProps<{
  domain: AuditDomain;
  title: string;
  subtitle: string;
  emptyMessage: string;
  /** Route to a per-event detail screen (shell-specific); omit for a list-only view. */
  detailRouteName?: string;
  /** Show an MFA-required note (the Finance-role finance-audit surface is MFA-gated). */
  mfaNote?: boolean;
}>();

const store = useAuditEventStore();
/** COR-UI08-UI12-VIS-001: only the Finance role's refined shell renders the dated stream. */
const refined = useRefinedExperience();

const severityOptions = [
  { value: '', label: 'All severities' },
  { value: 'info', label: 'Info' },
  { value: 'notice', label: 'Notice' },
  { value: 'warning', label: 'Warning' },
  { value: 'high', label: 'High' },
  { value: 'critical', label: 'Critical' },
];

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.events.length === 0) return 'empty';
  return 'success';
});

function reload(): void {
  void store.fetchEvents(props.domain, 1);
}

onMounted(() => {
  store.filters = { sort: '-created_at' };
  reload();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — inside the refined experience (the Finance role's own audit view) the
    events read as a TIME-ANCHORED STREAM: the unchanged UI-15 AuditEventCard on a dated rail, with a
    rail stating the read boundary (masked, branch-scoped, MFA-gated, read-only). Any other shell
    keeps the UI-16 list byte for byte.
  -->
  <section :class="refined ? 'sv-r-page' : 'mx-auto max-w-6xl'">
    <SvPageHeader
      :title="title"
      eyebrow="Reporting & Audit"
    >
      <template #description>
        {{ subtitle }}
        <span
          v-if="mfaNote"
          class="mt-1 block text-xs font-medium text-sv-text-muted"
          data-testid="audit-domain-mfa-note"
        >Multi-factor authentication is required to view this audit trail.</span>
      </template>
      <template #actions>
        <SvSelect
          :id="`${domain}-severity-filter`"
          v-model="store.filters.severity"
          label="Severity"
          :options="severityOptions"
          class="w-full md:w-56"
          @update:model-value="reload"
        />
      </template>
    </SvPageHeader>

    <div
      v-if="refined"
      class="sv-r-grid sv-r-split"
    >
      <SvStateBoundary
        class="min-w-0"
        :state="boundaryState"
        error-message="We couldn’t load these audit events."
        :empty-message="emptyMessage"
        @retry="reload"
      >
        <ol class="sv-r-steps">
          <li
            v-for="event in store.events"
            :key="event.id"
            class="sv-r-step"
            data-testid="audit-domain-row"
          >
            <span
              aria-hidden="true"
              class="sv-r-step__marker"
            ><SvIconClock class="h-4 w-4" /></span>
            <AuditEventCard
              :event="event"
              :to="detailRouteName ? { name: detailRouteName, params: { id: event.id } } : null"
            />
          </li>
        </ol>
      </SvStateBoundary>

      <aside
        class="sv-r-stack-sm sv-r-sticky"
        aria-label="Audit read boundary"
      >
        <section class="sv-r-note sv-r-tone-teal">
          <h2 class="sv-r-note__title">
            Read-only trail
          </h2>
          <p class="sv-r-note__body">
            Events are append-only and hash-chained on the server. Nothing on this page changes or flags them.
          </p>
        </section>
        <section class="sv-r-note sv-r-tone-neutral">
          <h2 class="sv-r-note__title">
            Masked and scoped
          </h2>
          <p class="sv-r-note__body">
            Only events for your assigned branches are returned, with contact details and references masked.
          </p>
        </section>
      </aside>
    </div>

    <SvStateBoundary
      v-else
      :state="boundaryState"
      error-message="We couldn’t load these audit events."
      :empty-message="emptyMessage"
      @retry="reload"
    >
      <ul class="flex flex-col gap-3">
        <li
          v-for="event in store.events"
          :key="event.id"
          data-testid="audit-domain-row"
        >
          <AuditEventCard
            :event="event"
            :to="detailRouteName ? { name: detailRouteName, params: { id: event.id } } : null"
          />
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
