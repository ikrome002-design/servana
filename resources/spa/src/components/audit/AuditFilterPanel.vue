<script setup lang="ts">
/**
 * AuditFilterPanel — the investigation filter rail (Phase UI-15; §12.4.3).
 *
 * Severity is a one-tap segmented control (the most-used lens); review state, dates, correlation
 * reference and sort sit behind it. Every option maps 1:1 to a backend allowlisted filter, and the
 * state lives in the URL via `useAuditRouteFilters`. On narrow screens the secondary fields fold
 * into a disclosure so the evidence list stays in view; nothing scrolls sideways.
 */
import { computed, reactive, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { SvIconClose, SvIconFilter } from '@/design-system/icons';
import { useAuditRouteFilters } from '@/composables/useAuditRouteFilters';

withDefaults(defineProps<{
  idPrefix: string;
  showReviewFilter?: boolean;
  showCorrelation?: boolean;
}>(), { showReviewFilter: true, showCorrelation: true });

const { filters, activeCount, apply, clear } = useAuditRouteFilters();

const severities = [
  { value: '', label: 'All' },
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'warning', label: 'Warning' },
  { value: 'notice', label: 'Notice' },
  { value: 'info', label: 'Info' },
];
const reviewOptions = [
  { value: '', label: 'Any review state' },
  { value: 'flagged', label: 'Flagged for review' },
  { value: 'unflagged', label: 'Not flagged' },
];
const sortOptions = [
  { value: '-created_at', label: 'Newest first' },
  { value: 'created_at', label: 'Oldest first' },
  { value: '-severity', label: 'Severity, highest first' },
  { value: 'action', label: 'Action, A to Z' },
];

const expanded = ref(false);
const draft = reactive({ date_from: '', date_to: '', correlation_id: '' });

watch(filters, (value) => {
  draft.date_from = value.date_from ?? '';
  draft.date_to = value.date_to ?? '';
  draft.correlation_id = value.correlation_id ?? '';
}, { immediate: true });

const dateError = computed(() => (draft.date_from !== '' && draft.date_to !== '' && draft.date_to < draft.date_from
  ? ['The end date must be on or after the start date.']
  : []));

const chips = computed(() => {
  const list: Array<{ key: 'severity' | 'flagged' | 'date_from' | 'date_to' | 'correlation_id' | 'action'; label: string }> = [];
  const f = filters.value;
  if (f.severity) list.push({ key: 'severity', label: `Severity: ${f.severity}` });
  if (f.flagged) list.push({ key: 'flagged', label: f.flagged === 'flagged' ? 'Flagged for review' : 'Not flagged' });
  if (f.date_from) list.push({ key: 'date_from', label: `From ${f.date_from}` });
  if (f.date_to) list.push({ key: 'date_to', label: `To ${f.date_to}` });
  if (f.correlation_id) list.push({ key: 'correlation_id', label: `Correlation ${f.correlation_id}` });
  if (f.action) list.push({ key: 'action', label: `Action ${f.action}` });
  return list;
});

function applyDraft(): void {
  if (dateError.value.length > 0) return;
  apply({ date_from: draft.date_from, date_to: draft.date_to, correlation_id: draft.correlation_id.trim() });
}
</script>

<template>
  <section
    class="sv-audit-filters rounded-card border border-sv-border bg-sv-surface-raised p-4 shadow-card md:p-5"
    :aria-label="`${idPrefix} filters`"
    data-testid="audit-filter-panel"
  >
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <fieldset class="min-w-0">
        <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
          Event severity
        </legend>
        <div
          class="flex flex-wrap gap-2"
          role="group"
        >
          <button
            v-for="option in severities"
            :key="option.value || 'all'"
            type="button"
            class="sv-chip sv-focus-ring inline-flex min-h-sv-touch items-center rounded-pill border px-4 text-sm font-semibold transition-colors duration-sv-fast motion-reduce:transition-none"
            :aria-pressed="filters.severity === option.value"
            :data-testid="`audit-filter-severity-${option.value || 'all'}`"
            @click="apply({ severity: option.value })"
          >
            {{ option.label }}
          </button>
        </div>
      </fieldset>
      <button
        type="button"
        class="sv-focus-ring inline-flex min-h-sv-touch shrink-0 items-center gap-2 self-start whitespace-nowrap rounded-control border border-sv-border px-4 text-sm font-semibold text-sv-text lg:self-auto"
        :aria-expanded="expanded"
        :aria-controls="`${idPrefix}-more-filters`"
        data-testid="audit-filter-toggle"
        @click="expanded = !expanded"
      >
        <SvIconFilter
          aria-hidden="true"
          class="h-4 w-4"
        />
        {{ expanded ? 'Hide filters' : 'More filters' }}
        <span
          v-if="activeCount > 0"
          class="rounded-pill bg-sv-brand px-2 text-xs font-bold text-sv-text-on-brand"
        >{{ activeCount }}<span class="sr-only"> active</span></span>
      </button>
    </div>

    <form
      v-show="expanded"
      :id="`${idPrefix}-more-filters`"
      class="mt-4 grid grid-cols-1 gap-3 border-t border-sv-border pt-4 md:grid-cols-2 lg:grid-cols-4"
      @submit.prevent="applyDraft"
    >
      <SvSelect
        v-if="showReviewFilter"
        :id="`${idPrefix}-review`"
        :model-value="filters.flagged ?? ''"
        label="Review state"
        :options="reviewOptions"
        @update:model-value="(value) => apply({ flagged: value })"
      />
      <SvSelect
        :id="`${idPrefix}-sort`"
        :model-value="filters.sort ?? '-created_at'"
        label="Sort"
        :options="sortOptions"
        @update:model-value="(value) => apply({ sort: value })"
      />
      <SvTextInput
        :id="`${idPrefix}-from`"
        v-model="draft.date_from"
        label="From date"
        type="date"
      />
      <SvTextInput
        :id="`${idPrefix}-to`"
        v-model="draft.date_to"
        label="To date"
        type="date"
        :errors="dateError"
      />
      <SvTextInput
        v-if="showCorrelation"
        :id="`${idPrefix}-correlation`"
        v-model="draft.correlation_id"
        label="Correlation reference"
        :maxlength="64"
        help="Find every event recorded by the same request."
      />
      <div class="flex items-end gap-2 md:col-span-2 lg:col-span-1">
        <SvButton
          type="submit"
          :disabled="dateError.length > 0"
          data-testid="audit-filter-apply"
        >
          Apply filters
        </SvButton>
      </div>
    </form>

    <div
      v-if="chips.length > 0"
      class="mt-4 flex flex-wrap items-center gap-2"
      aria-live="polite"
    >
      <span class="text-xs font-semibold text-sv-text-muted">Active:</span>
      <button
        v-for="chip in chips"
        :key="chip.key"
        type="button"
        class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-1 rounded-pill border border-sv-border bg-sv-surface-subtle px-3 text-xs font-semibold text-sv-text"
        @click="apply({ [chip.key]: '' })"
      >
        {{ chip.label }}
        <SvIconClose
          aria-hidden="true"
          class="h-3.5 w-3.5"
        /><span class="sr-only">Remove filter</span>
      </button>
      <button
        type="button"
        class="sv-focus-ring inline-flex min-h-sv-touch items-center px-2 text-xs font-semibold text-sv-link underline-offset-4 hover:underline"
        data-testid="audit-filter-clear"
        @click="clear"
      >
        Clear all
      </button>
    </div>
  </section>
</template>

<style scoped>
.sv-chip {
  border-color: var(--sv-color-border-default);
  background: var(--sv-color-surface-raised);
  color: var(--sv-color-text-primary);
}
.sv-chip:hover { background: var(--sv-color-surface-subtle); }
.sv-chip[aria-pressed='true'] {
  border-color: var(--sv-color-brand-secondary);
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 14%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
  box-shadow: inset 0 0 0 1px var(--sv-color-brand-secondary);
}
</style>
