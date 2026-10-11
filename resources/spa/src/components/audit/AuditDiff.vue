<script setup lang="ts">
/**
 * AuditDiff — a readable before/after comparison of one event's recorded change (§12.4.4).
 *
 * The rows come from `buildContextBreakdown`, which only re-arranges the server's already-masked
 * context. A semantic table carries the comparison for assistive technology on every width; at
 * narrow widths each row is laid out as a labelled stacked card by CSS alone, so nothing scrolls
 * sideways and the reading order never changes. Masked values carry a lock and say so.
 */
import { SvIconLocked } from '@/design-system/icons';
import type { AuditDiffRow } from '@/utils/auditPresentation';
import { isMaskedValue } from '@/utils/auditPresentation';

defineProps<{ rows: AuditDiffRow[] }>();
</script>

<template>
  <table
    class="sv-diff w-full border-separate border-spacing-0 text-sm"
    data-testid="audit-diff"
  >
    <caption class="sr-only">
      Recorded before and after values
    </caption>
    <thead>
      <tr>
        <th
          scope="col"
          class="text-left"
        >
          Field
        </th>
        <th
          scope="col"
          class="text-left"
        >
          Before
        </th>
        <th
          scope="col"
          class="text-left"
        >
          After
        </th>
      </tr>
    </thead>
    <tbody>
      <tr
        v-for="row in rows"
        :key="row.field"
        :data-changed="row.changed"
      >
        <th
          scope="row"
          class="text-left font-semibold text-sv-text-heading"
        >
          {{ row.field }}
          <span
            v-if="row.changed"
            class="sv-diff__changed ml-1 inline-flex rounded-pill px-2 text-[0.6875rem] font-bold uppercase tracking-wide"
          >Changed</span>
        </th>
        <td
          class="sv-diff__before"
          data-label="Before"
        >
          <span class="inline-flex items-center gap-1 break-words">
            <SvIconLocked
              v-if="isMaskedValue(row.before)"
              aria-hidden="true"
              class="h-3.5 w-3.5 shrink-0"
            /><span
              v-if="isMaskedValue(row.before)"
              class="sr-only"
            >Masked:</span>{{ row.before }}
          </span>
        </td>
        <td
          class="sv-diff__after"
          data-label="After"
        >
          <span class="inline-flex items-center gap-1 break-words">
            <SvIconLocked
              v-if="isMaskedValue(row.after)"
              aria-hidden="true"
              class="h-3.5 w-3.5 shrink-0"
            /><span
              v-if="isMaskedValue(row.after)"
              class="sr-only"
            >Masked:</span>{{ row.after }}
          </span>
        </td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.sv-diff th,
.sv-diff td {
  padding: 0.75rem;
  border-bottom: 1px solid var(--sv-color-border-default);
  vertical-align: top;
}
.sv-diff thead th {
  background: var(--sv-color-table-header);
  color: var(--sv-color-text-secondary);
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.sv-diff__before { color: var(--sv-color-text-secondary); }
.sv-diff tr[data-changed='true'] .sv-diff__before {
  background: color-mix(in srgb, var(--sv-color-status-error-bg) 55%, transparent);
}
.sv-diff tr[data-changed='true'] .sv-diff__after {
  background: color-mix(in srgb, var(--sv-color-status-success-bg) 60%, transparent);
  color: var(--sv-color-text-heading);
  font-weight: 600;
}
.sv-diff__changed {
  background: color-mix(in srgb, var(--sv-color-accent) 30%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}

/* Narrow widths: each row becomes a labelled card; the table stays a table for assistive tech. */
@media (max-width: 767px) {
  .sv-diff thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
  .sv-diff tr { display: block; margin-bottom: 0.75rem; border: 1px solid var(--sv-color-border-default); border-radius: var(--sv-radius-control); overflow: hidden; }
  .sv-diff th,
  .sv-diff td { display: block; border-bottom: 0; }
  .sv-diff td::before {
    content: attr(data-label);
    display: block;
    margin-bottom: 0.125rem;
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--sv-color-text-muted);
  }
}
</style>
