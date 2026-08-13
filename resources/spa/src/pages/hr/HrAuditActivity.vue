<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import { useHrWorkspaceStore, type HrAuditActivity } from '@/stores/hrWorkspaceStore';
import { formatDateTime } from '@/utils/dates';

const store = useHrWorkspaceStore();
const filters = reactive({ domain: '', severity: '', page: 1 });
const state = computed(() => (store.loading ? 'loading' : store.error ? 'error' : store.auditEvents.length ? 'success' : 'empty'));
const domainOptions = [
  { value: '', label: 'All HR activity' },
  { value: 'staff', label: 'Staff and access' },
  { value: 'readiness', label: 'Eligibility and availability' },
  { value: 'compensation', label: 'Compensation configuration' },
  { value: 'payout', label: 'Payout preparation' },
];
const severityOptions = [
  { value: '', label: 'All severities' },
  { value: 'info', label: 'Info' },
  { value: 'notice', label: 'Notice' },
  { value: 'warning', label: 'Warning' },
  { value: 'high', label: 'High' },
  { value: 'critical', label: 'Critical' },
];

function tone(severity: string): SvStatusTone {
  if (severity === 'critical' || severity === 'high') return 'error';
  if (severity === 'warning') return 'warning';
  if (severity === 'notice') return 'info';
  return 'neutral';
}

function humanize(value: string): string {
  return value.replaceAll('.', ' ').replaceAll('_', ' ');
}

function contextPairs(event: HrAuditActivity): Array<[string, string]> {
  return Object.entries(event.context)
    .filter(([, value]) => ['string', 'number', 'boolean'].includes(typeof value))
    .slice(0, 8)
    .map(([key, value]) => [humanize(key), String(value)]);
}

async function load(page = 1): Promise<void> {
  filters.page = page;
  const params: Record<string, string | number> = { sort: '-created_at', page, per_page: 20 };
  if (filters.domain) params.domain = filters.domain;
  if (filters.severity) params.severity = filters.severity;
  await store.fetchAudit(params);
}

function reset(): void {
  filters.domain = '';
  filters.severity = '';
  void load();
}

onMounted(() => {
  void load();
});
</script>

<template>
  <section
    class="mx-auto max-w-5xl"
    data-testid="hr-audit-activity"
  >
    <SvPageHeader
      title="HR audit activity"
      eyebrow="Reporting"
      description="A masked, read-only timeline of staff access, eligibility, availability, compensation configuration and payout-preparation events in your assigned branch."
    />

    <SvCard
      as="form"
      class="grid gap-4 sm:grid-cols-[1fr_1fr_auto]"
      aria-label="HR audit filters"
      @submit.prevent="load(1)"
    >
      <SvSelect
        id="hr-audit-domain"
        v-model="filters.domain"
        label="Activity area"
        :options="domainOptions"
      />
      <SvSelect
        id="hr-audit-severity"
        v-model="filters.severity"
        label="Severity"
        :options="severityOptions"
      />
      <div class="flex items-end gap-2">
        <SvButton type="submit">
          Apply
        </SvButton>
        <SvButton
          type="button"
          variant="ghost"
          @click="reset"
        >
          Reset
        </SvButton>
      </div>
    </SvCard>

    <p
      class="mt-4 rounded-control border border-sv-info-border bg-sv-info-bg px-4 py-3 text-sm text-sv-info-fg"
      role="note"
    >
      Raw cross-module audit review, flagged-event decisions and exports stay with the Audit account. Human Resource cannot export client or payment data here.
    </p>

    <SvStateBoundary
      class="mt-5"
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No HR activity matches these filters."
      @retry="load(filters.page)"
    >
      <ol
        class="relative grid gap-3 border-l border-sv-border pl-5"
        aria-label="HR audit timeline"
      >
        <li
          v-for="event in store.auditEvents"
          :key="event.id"
          class="relative"
        >
          <span
            class="absolute -left-[1.63rem] top-5 h-3 w-3 rounded-full border-2 border-sv-surface bg-sv-brand"
            aria-hidden="true"
          />
          <SvCard as="article">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="font-display font-bold capitalize text-heading">
                  {{ humanize(event.action) }}
                </h2>
                <p class="mt-1 text-sm text-text-muted">
                  {{ event.actor ?? 'System' }} · {{ event.subject_type ?? 'HR record' }}
                </p>
              </div>
              <SvStatusBadge
                :label="event.severity"
                :tone="tone(event.severity)"
              />
            </div>
            <time
              class="mt-3 block text-xs text-text-muted"
              :datetime="event.created_at ?? undefined"
            >
              {{ event.created_at ? formatDateTime(event.created_at) : 'Time unavailable' }}
            </time>
            <details
              v-if="contextPairs(event).length"
              class="mt-3 rounded-control bg-surface-alt p-3"
            >
              <summary class="sv-focus-ring cursor-pointer rounded-control text-sm font-semibold text-heading">
                Masked event context
              </summary>
              <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                <div
                  v-for="[label, value] in contextPairs(event)"
                  :key="label"
                >
                  <dt class="text-xs capitalize text-text-muted">
                    {{ label }}
                  </dt>
                  <dd class="break-words text-sm text-text">
                    {{ value }}
                  </dd>
                </div>
              </dl>
            </details>
          </SvCard>
        </li>
      </ol>
    </SvStateBoundary>

    <SvPagination
      v-if="store.auditMeta && store.auditMeta.last_page > 1"
      class="mt-5"
      :current-page="store.auditMeta.current_page"
      :last-page="store.auditMeta.last_page"
      :per-page="store.auditMeta.per_page"
      :total="store.auditMeta.total"
      label="HR audit pages"
      :disabled="store.loading"
      @change="load"
    />
  </section>
</template>
