<script setup lang="ts">
/**
 * AuditExportStatusCard — one export request and its server-owned lifecycle (Phase UI-15).
 *
 * Status, expiry and download count are shown exactly as the server reports them. The card never
 * holds a signed URL, file id or path: downloading happens on the export record, which requests a
 * short-lived authorized link at the moment of use.
 */
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { SvIconChevronRight, SvIconDocument } from '@/design-system/icons';
import type { AuditExportView } from '@/stores/auditExportStore';
import { exportPresentation } from '@/utils/auditPresentation';

const props = defineProps<{ exportRecord: AuditExportView }>();

const presentation = computed(() => exportPresentation(props.exportRecord.status));
const scopeSummary = computed(() => {
  const scope = props.exportRecord.scope;
  const domains = scope.domains.length > 0 ? scope.domains.join(', ') : 'general';
  const severities = scope.severities.length > 0 ? scope.severities.join(', ') : 'every severity';
  return `${domains} · ${severities}`;
});
</script>

<template>
  <article
    class="sv-export-card flex h-full flex-col gap-3 rounded-card border border-sv-border bg-sv-surface-raised p-4 shadow-card md:p-5"
    :data-status="exportRecord.status"
    data-testid="audit-export-row"
  >
    <div class="flex items-start justify-between gap-3">
      <div class="flex min-w-0 items-center gap-3">
        <span
          aria-hidden="true"
          class="sv-export-card__icon inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-control"
        >
          <SvIconDocument class="h-5 w-5" />
        </span>
        <div class="min-w-0">
          <p class="truncate font-semibold text-sv-text-heading">
            {{ exportRecord.branch?.name ?? 'Branch export' }}
          </p>
          <p class="truncate text-xs text-sv-text-muted">
            {{ scopeSummary }}
          </p>
        </div>
      </div>
      <span
        data-testid="audit-export-status"
        :data-status="exportRecord.status"
      >
        <SvStatusBadge
          :label="presentation.label"
          :tone="presentation.tone"
          size="sm"
          sr-prefix="Export status:"
        />
      </span>
    </div>
    <p class="line-clamp-2 break-words text-sm text-sv-text-secondary">
      <span class="font-semibold text-sv-text">Reason:</span> {{ exportRecord.reason }}
    </p>
    <dl class="grid grid-cols-3 gap-2 text-xs">
      <div>
        <dt class="text-sv-text-muted">
          Rows
        </dt>
        <dd class="font-display text-base font-bold text-sv-text-heading">
          {{ exportRecord.row_count ?? '—' }}
        </dd>
      </div>
      <div>
        <dt class="text-sv-text-muted">
          Downloads
        </dt>
        <dd class="font-display text-base font-bold text-sv-text-heading">
          {{ exportRecord.download_count }}
        </dd>
      </div>
      <div class="min-w-0">
        <dt class="text-sv-text-muted">
          {{ exportRecord.status === 'ready' ? 'Expires' : 'Requested' }}
        </dt>
        <dd class="font-medium text-sv-text">
          <SvDateTime
            :value="exportRecord.status === 'ready' ? exportRecord.expires_at : (exportRecord.requested_at ?? exportRecord.created_at)"
          />
        </dd>
      </div>
    </dl>
    <RouterLink
      :to="{ name: 'audit.export-detail', params: { exportUlid: exportRecord.id } }"
      class="sv-focus-ring mt-auto inline-flex min-h-sv-touch items-center gap-1 self-start rounded-control text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
      data-testid="audit-export-open-detail"
    >
      Open export record
      <SvIconChevronRight
        aria-hidden="true"
        class="h-4 w-4"
      />
    </RouterLink>
  </article>
</template>

<style scoped>
.sv-export-card__icon {
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 14%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
.sv-export-card[data-status='ready'] {
  border-top: 3px solid color-mix(in srgb, var(--sv-color-growth) 70%, var(--sv-color-border-default));
}
.sv-export-card[data-status='failed'] {
  border-top: 3px solid var(--sv-color-status-error-border);
}
</style>
