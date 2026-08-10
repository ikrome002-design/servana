<script setup lang="ts">
/**
 * Platform Audit — Super Administrator contract page §5.4.18 (Phase UI-08).
 *
 * Inspection of the append-only platform governance, billing, integration, security and
 * administrative chain. There is no second audit domain here: this reads the SAME `audit_logs`
 * authority Phase 19 built, through the platform-scoped endpoint that returns `merchant_id IS NULL`
 * rows only.
 *
 * ## Read-only is structural, not a styling choice
 *
 * `audit_logs` forbids UPDATE and DELETE at the database level and no endpoint accepts either, so
 * this page renders no edit, resolve, annotate or delete affordance — not a disabled one.
 *
 * ## Export
 *
 * The contract describes a permissioned masked export and an evidence package. `platform.audit.
 * export` exists in the permission matrix as **`implementation_status: planned`, owned by Phase 23**,
 * and the shipped `audit-exports` endpoints are branch-scoped merchant exports gated on
 * `audit.export` with `EnsureBranchScope` — they cannot export the platform chain. No export control
 * is rendered, and the page states the disposition instead of implying a capability that would fail.
 *
 * ## Hash chain
 *
 * The chain is verified out of band; no HTTP surface exposes chain status or verifier incidents.
 * Rendering "chain: healthy" from the absence of a check would be a fabricated integrity assurance,
 * so the page states what IS guaranteed (append-only storage, server-side masking) and names what it
 * cannot show.
 *
 * ## Route collision
 *
 * `/audit` is also a Merchant Audit account route. The account host selects which tree is
 * registered (`createAppRouter(accountKey)`, Increment 7B); the host is never authorization — every
 * request re-checks `platform.audit.view` server-side.
 */
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvDataTable from '@/components/ui/SvDataTable.vue';
import SvFilterBar from '@/components/ui/SvFilterBar.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvResponsiveRecordList from '@/components/ui/SvResponsiveRecordList.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import type { SvColumn, SvDataState } from '@/components/ui/dataContract';
import { SvIconChevronRight } from '@/design-system/icons';
import { useCan } from '@/composables/useCan';
import { usePlatformAuditStore, type PlatformAuditEvent } from '@/stores/platformAuditStore';

const store = usePlatformAuditStore();
const { can } = useCan();

const canView = computed(() => can('platform.audit.view'));

const SEVERITY_OPTIONS = [
  { value: '', label: 'All severities' },
  { value: 'info', label: 'Info' },
  { value: 'notice', label: 'Notice' },
  { value: 'warning', label: 'Warning' },
  { value: 'high', label: 'High' },
  { value: 'critical', label: 'Critical' },
];

const SORT_OPTIONS = [
  { value: '-created_at', label: 'Newest first' },
  { value: 'created_at', label: 'Oldest first' },
  { value: '-severity', label: 'Severity, highest first' },
  { value: 'action', label: 'Action, A to Z' },
];

const SEVERITY_TONES: Record<string, SvStatusTone> = {
  info: 'info',
  notice: 'info',
  warning: 'warning',
  high: 'error',
  critical: 'error',
};

function severityTone(severity: string): SvStatusTone {
  return SEVERITY_TONES[severity] ?? 'neutral';
}

const columns: SvColumn<PlatformAuditEvent>[] = [
  { key: 'action', label: 'Action', priority: 'primary', value: (row) => row.action },
  { key: 'severity', label: 'Severity', priority: 'secondary', value: (row) => row.severity },
  { key: 'actor', label: 'Actor', priority: 'secondary', value: (row) => row.actor ?? '—' },
  { key: 'created_at', label: 'Occurred', priority: 'secondary', value: (row) => row.created_at ?? '—' },
  { key: 'subject_type', label: 'Subject', priority: 'detail', value: (row) => row.subject_type ?? '—' },
  { key: 'correlation_id', label: 'Correlation', priority: 'detail', value: (row) => row.correlation_id ?? '—' },
];

const dataState = computed<SvDataState>(() => {
  if (!canView.value) return 'forbidden';
  if (store.loading) return 'loading';
  if (store.error !== null) return 'error';
  return store.events.length === 0 ? 'empty' : 'idle';
});

const activeFilterCount = computed(() => {
  const f = store.filters;
  return [f.action, f.severity, f.actor, f.date_from, f.date_to].filter((value) => value !== '').length;
});

/**
 * A readable before/after view of the masked context. Where the recorded event carries a
 * `from_*`/`to_*` pair — the shape the merchant lifecycle, billing and flag state machines use — it
 * is presented as a change. Everything else is shown as a plain masked key/value: this page never
 * infers a "previous value" that the record does not contain.
 */
const changes = computed<Array<{ field: string; before: string; after: string }>>(() => {
  const context = store.current?.context ?? {};
  const rows: Array<{ field: string; before: string; after: string }> = [];
  for (const key of Object.keys(context)) {
    if (!key.startsWith('from_')) continue;
    const field = key.slice('from_'.length);
    const toKey = `to_${field}`;
    if (!(toKey in context)) continue;
    rows.push({ field: field.replace(/_/g, ' '), before: display(context[key]), after: display(context[toKey]) });
  }
  return rows;
});

const changedFieldKeys = computed(() => new Set(changes.value.flatMap((c) => [`from_${c.field.replace(/ /g, '_')}`, `to_${c.field.replace(/ /g, '_')}`])));

const remainingContext = computed<Array<[string, string]>>(() =>
  Object.entries(store.current?.context ?? {})
    .filter(([key]) => !changedFieldKeys.value.has(key))
    .map(([key, value]) => [key, display(value)]),
);

function display(value: unknown): string {
  if (value === null || value === undefined) return '—';
  return typeof value === 'string' ? value : JSON.stringify(value);
}

onMounted(() => {
  if (canView.value) void store.fetchEvents();
});
</script>

<template>
  <div
    class="mx-auto w-full max-w-5xl"
    data-testid="platform-audit-screen"
  >
    <SvPageHeader
      title="Platform audit"
      eyebrow="Reporting & audit"
      description="The append-only record of platform governance, billing, integration, security and administrative events. Read-only, and masked before it reaches this page."
    />

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <SvFilterBar
        label="Audit filters"
        :active-count="activeFilterCount"
        @clear="store.clearFilters()"
      >
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
          <SvTextInput
            id="audit-action"
            v-model="store.filters.action"
            label="Action"
            placeholder="e.g. merchant.suspended"
            @keyup.enter="store.applyFilters()"
          />
          <SvSelect
            id="audit-severity"
            v-model="store.filters.severity"
            label="Severity"
            :options="SEVERITY_OPTIONS"
            @update:model-value="store.applyFilters()"
          />
          <SvTextInput
            id="audit-actor"
            v-model="store.filters.actor"
            label="Actor identifier"
            placeholder="26-character user identifier"
            @keyup.enter="store.applyFilters()"
          />
          <SvTextInput
            id="audit-date-from"
            v-model="store.filters.date_from"
            label="From"
            type="date"
            @change="store.applyFilters()"
          />
          <SvTextInput
            id="audit-date-to"
            v-model="store.filters.date_to"
            label="To"
            type="date"
            @change="store.applyFilters()"
          />
          <SvSelect
            id="audit-sort"
            v-model="store.filters.sort"
            label="Sort"
            :options="SORT_OPTIONS"
            @update:model-value="store.applyFilters()"
          />
        </div>
      </SvFilterBar>

      <!--
        UI08-RESP-001: six columns — two of them long machine tokens (`merchant.suspended`, a
        correlation id) — pushed the document 9px past the 768px tablet floor. The table is
        therefore desktop-only here and tablet reads the labelled record cards, which is what the
        plan asks for on a tablet anyway ("condense columns on tablets").
      -->
      <div class="hidden lg:block">
        <SvDataTable
          :columns="columns"
          :rows="store.events"
          :row-key="(row) => row.id"
          caption="Platform audit events"
          :state="dataState"
          :error-message="store.error ?? undefined"
          empty-message="No platform audit events match these filters."
          @retry="store.fetchEvents()"
        >
          <template #cell:severity="{ row }">
            <SvStatusBadge
              :label="row.severity"
              :tone="severityTone(row.severity)"
              size="sm"
              sr-prefix="Severity:"
            />
          </template>
          <template #cell:action="{ row }">
            <button
              type="button"
              class="sv-focus-ring break-words text-left font-medium text-sv-text underline underline-offset-2"
              :data-testid="`audit-event-open-${row.id}`"
              @click="store.fetchEvent(row.id)"
            >
              {{ row.action }}
            </button>
          </template>
        </SvDataTable>
      </div>

      <div class="lg:hidden">
        <SvResponsiveRecordList
          :columns="columns"
          :rows="store.events"
          :row-key="(row) => row.id"
          caption="Platform audit events"
          :state="dataState"
          :error-message="store.error ?? undefined"
          empty-message="No platform audit events match these filters."
          @retry="store.fetchEvents()"
        >
          <template #cell:severity="{ row }">
            <SvStatusBadge
              :label="row.severity"
              :tone="severityTone(row.severity)"
              size="sm"
              sr-prefix="Severity:"
            />
          </template>
          <template #cell:action="{ row }">
            <button
              type="button"
              class="sv-focus-ring break-words text-left font-medium text-sv-text underline underline-offset-2"
              :data-testid="`audit-event-open-${row.id}`"
              @click="store.fetchEvent(row.id)"
            >
              {{ row.action }}
            </button>
          </template>
        </SvResponsiveRecordList>
      </div>

      <SvPagination
        v-if="store.meta !== null && store.meta.last_page > 1"
        class="mt-4"
        :current-page="store.meta.current_page"
        :last-page="store.meta.last_page"
        :total="store.meta.total"
        label="Audit pages"
        data-testid="audit-pagination"
        @change="store.goToPage"
      />

      <!-- Event detail. Read-only; every value arrived masked. -->
      <SvCard
        v-if="store.current !== null || store.detailError !== null"
        as="section"
        class="mt-6"
        data-testid="audit-event-detail"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <h2 class="font-display text-lg font-bold text-sv-text-heading break-words">
            {{ store.current?.action ?? 'Audit event' }}
          </h2>
          <SvButton
            variant="ghost"
            size="sm"
            data-testid="audit-event-close"
            @click="store.closeEvent()"
          >
            Close
          </SvButton>
        </div>

        <p
          v-if="store.detailError !== null"
          class="mt-2 text-sm text-sv-text-muted"
          role="status"
          data-testid="audit-event-detail-unavailable"
        >
          {{ store.detailError }}
        </p>

        <template v-else-if="store.current">
          <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                Severity
              </dt>
              <dd class="mt-1 text-sm text-sv-text">
                {{ store.current.severity }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                Actor
              </dt>
              <dd class="mt-1 text-sm text-sv-text">
                {{ store.current.actor ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                Subject
              </dt>
              <dd class="mt-1 text-sm text-sv-text">
                {{ store.current.subject_type ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                Occurred
              </dt>
              <dd class="mt-1 text-sm text-sv-text">
                {{ store.current.created_at ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                Correlation
              </dt>
              <dd
                class="mt-1 break-words text-sm text-sv-text"
                data-testid="audit-event-correlation"
              >
                {{ store.current.correlation_id ?? '—' }}
              </dd>
            </div>
          </dl>

          <section
            v-if="changes.length > 0"
            class="mt-6"
            aria-label="Recorded change"
            data-testid="audit-event-changes"
          >
            <h3 class="font-display text-sm font-bold text-sv-text-heading">
              What changed
            </h3>
            <dl class="mt-2 flex flex-col gap-2">
              <div
                v-for="change in changes"
                :key="change.field"
                class="rounded-control border border-sv-border p-3"
              >
                <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                  {{ change.field }}
                </dt>
                <dd class="mt-1 text-sm text-sv-text">
                  <span data-testid="audit-change-before">{{ change.before }}</span>
                  <SvIconChevronRight
                    aria-hidden="true"
                    class="mx-1 inline-block h-4 w-4 align-text-bottom text-sv-text-muted"
                  />
                  <span class="sr-only">changed to</span>
                  <span data-testid="audit-change-after">{{ change.after }}</span>
                </dd>
              </div>
            </dl>
          </section>

          <section
            class="mt-6"
            aria-label="Recorded context"
          >
            <h3 class="font-display text-sm font-bold text-sv-text-heading">
              Context (masked)
            </h3>
            <p
              v-if="remainingContext.length === 0"
              class="mt-2 text-sm text-sv-text-muted"
            >
              No further context was recorded.
            </p>
            <dl
              v-else
              class="mt-2 flex flex-col gap-2"
            >
              <div
                v-for="[key, value] in remainingContext"
                :key="key"
                class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]"
              >
                <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                  {{ key }}
                </dt>
                <dd class="break-words text-sm text-sv-text">
                  {{ value }}
                </dd>
              </div>
            </dl>
          </section>
        </template>
      </SvCard>

      <SvAlert
        severity="info"
        title="What this record guarantees, and what it cannot show"
        class="mt-8"
        data-testid="audit-integrity-statement"
      >
        <ul class="list-disc space-y-1 pl-5">
          <li>
            Audit rows are append-only: the database rejects any update or delete, so nothing on
            this page can be edited, resolved away or removed.
          </li>
          <li>
            Actor emails and every recorded value are masked on the server before they are sent.
            There is no request that returns unmasked audit data.
          </li>
          <li>
            Hash-chain verification status and verifier incidents are not exposed by any endpoint,
            so no integrity indicator is shown. An indicator with nothing behind it would be a
            fabricated assurance.
          </li>
          <li>
            Filtering is by action, severity, actor, date and sort order. Filtering by merchant,
            branch, module, entity, event status or correlation identifier is not accepted by the
            shipped read.
          </li>
        </ul>
      </SvAlert>

      <SvAlert
        severity="info"
        title="Export is not available yet"
        class="mt-4"
        data-testid="audit-export-disposition"
      >
        <p>
          A permissioned, masked platform audit export is planned under
          <code>platform.audit.export</code>, which the permission matrix records as planned and
          owned by Phase 23. The audit exports that exist today are branch-scoped merchant exports
          and cannot produce the platform chain, so no export control is offered here.
        </p>
      </SvAlert>
    </template>
  </div>
</template>
