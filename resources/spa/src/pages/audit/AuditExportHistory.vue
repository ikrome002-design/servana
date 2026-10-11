<script setup lang="ts">
/**
 * Export History — Appendix A §12.4.12 (Phase UI-15 over the Phase 19 export read).
 *
 * Every audit export request in the caller's branch scope, filtered by status on the server and
 * paginated with the server's total. Expired and revoked exports say so and offer no download;
 * a ready export is downloaded from its record, which asks the server for a short-lived
 * authorized link at that moment. Requester identity is not part of the export payload, so it is
 * not shown rather than guessed.
 */
import { computed, onMounted, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import AuditExportStatusCard from '@/components/audit/AuditExportStatusCard.vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { useAuditExportStore, type AuditExportStatus } from '@/stores/auditExportStore';
import { usePermissionStore } from '@/stores/permissionStore';
import { exportPresentation } from '@/utils/auditPresentation';

const route = useRoute();
const router = useRouter();
const store = useAuditExportStore();
const permissions = usePermissionStore();

const STATUSES: Array<{ value: AuditExportStatus | ''; label: string }> = [
  { value: '', label: 'All' },
  { value: 'ready', label: 'Ready' },
  { value: 'queued', label: 'Queued' },
  { value: 'processing', label: 'Processing' },
  { value: 'failed', label: 'Failed' },
  { value: 'expired', label: 'Expired' },
  { value: 'revoked', label: 'Revoked' },
];

const canExport = computed(() => permissions.can('audit.export'));
const activeStatus = computed<AuditExportStatus | ''>(() => {
  const value = route.query.status;
  return typeof value === 'string' && STATUSES.some((s) => s.value === value) ? (value as AuditExportStatus) : '';
});
const page = computed(() => {
  const value = Number.parseInt(String(route.query.page ?? '1'), 10);
  return Number.isFinite(value) && value > 0 ? value : 1;
});

const state = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && store.exports.length === 0) return 'loading';
  if (store.error) return 'error';
  if (store.exports.length === 0) return 'empty';
  return 'success';
});

function load(): void {
  if (!canExport.value) return;
  store.filterStatus = activeStatus.value;
  void store.fetchExports(page.value);
}

function select(status: AuditExportStatus | ''): void {
  void router.replace({ query: status === '' ? {} : { status } });
}

function goToPage(next: number): void {
  void router.replace({ query: { ...route.query, page: next > 1 ? String(next) : undefined } });
}

function scopeLabel(domains: string[], severities: string[]): string {
  return `${domains.length > 0 ? domains.join(', ') : 'general'} · ${severities.length > 0 ? severities.join(', ') : 'every severity'}`;
}

watch(() => route.query, load);
onMounted(load);
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="audit-export-history-page"
  >
    <SvTonalPageHeader
      eyebrow="Export"
      title="Export History"
      description="Every audit export requested for your branch: why it was requested, what it covered, where it is in its lifecycle and how often it has been downloaded."
      context="Assigned branch"
      tone="teal"
    >
      <template #actions>
        <RouterLink
          v-if="canExport"
          :to="{ name: 'audit.exports' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          Request an export
        </RouterLink>
      </template>
      <div
        v-if="canExport"
        class="flex flex-wrap gap-2"
        role="group"
        aria-label="Filter by export status"
      >
        <button
          v-for="status in STATUSES"
          :key="status.value || 'all'"
          type="button"
          class="sv-status-filter sv-focus-ring inline-flex min-h-sv-touch items-center rounded-pill border px-4 text-sm font-semibold transition-colors duration-sv-fast motion-reduce:transition-none"
          :aria-pressed="activeStatus === status.value"
          :data-testid="`audit-export-history-filter-${status.value || 'all'}`"
          @click="select(status.value)"
        >
          {{ status.label }}
        </button>
      </div>
    </SvTonalPageHeader>

    <SvAlert
      v-if="!canExport"
      severity="info"
      title="Export history is not available to your account"
      data-testid="audit-export-history-denied"
    >
      Export history is shown to Audit users who hold audit export.
    </SvAlert>

    <SvStateBoundary
      v-else
      :state="state"
      error-message="We couldn’t load export history."
      :empty-message="activeStatus === '' ? 'No audit exports have been requested for your branch yet.' : `No ${exportPresentation(activeStatus).label.toLowerCase()} exports.`"
      @retry="load"
    >
      <p
        class="mb-3 text-sm text-sv-text-secondary"
        role="status"
        aria-live="polite"
      >
        <strong class="font-semibold text-sv-text-heading">{{ store.meta.total.toLocaleString('en-KE') }}</strong>
        {{ store.meta.total === 1 ? 'export' : 'exports' }}{{ activeStatus ? ` · ${exportPresentation(activeStatus).label.toLowerCase()}` : '' }}
      </p>

      <div class="hidden overflow-hidden rounded-card border border-sv-border bg-sv-surface-raised shadow-card lg:block">
        <table class="w-full text-left text-sm">
          <caption class="sr-only">
            Audit export history
          </caption>
          <thead class="bg-sv-table-header text-xs font-semibold uppercase tracking-wide text-sv-text-secondary">
            <tr>
              <th
                scope="col"
                class="px-4 py-3"
              >
                Requested
              </th>
              <th
                scope="col"
                class="px-4 py-3"
              >
                Reason and scope
              </th>
              <th
                scope="col"
                class="px-4 py-3"
              >
                Status
              </th>
              <th
                scope="col"
                class="px-4 py-3 text-right"
              >
                Rows
              </th>
              <th
                scope="col"
                class="px-4 py-3 text-right"
              >
                Downloads
              </th>
              <th
                scope="col"
                class="px-4 py-3"
              >
                Expires
              </th>
              <th
                scope="col"
                class="px-4 py-3"
              >
                <span class="sr-only">Open</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="exp in store.exports"
              :key="exp.id"
              class="border-t border-sv-border hover:bg-sv-table-hover"
              data-testid="audit-export-history-row"
            >
              <td class="whitespace-nowrap px-4 py-3 text-sv-text">
                <SvDateTime :value="exp.requested_at ?? exp.created_at" />
              </td>
              <td class="max-w-md px-4 py-3">
                <p class="line-clamp-2 break-words font-medium text-sv-text-heading">
                  {{ exp.reason }}
                </p>
                <p class="text-xs text-sv-text-muted">
                  {{ exp.branch?.name ?? 'Branch' }} · {{ scopeLabel(exp.scope.domains, exp.scope.severities) }}
                </p>
              </td>
              <td class="px-4 py-3">
                <SvStatusBadge
                  :label="exportPresentation(exp.status).label"
                  :tone="exportPresentation(exp.status).tone"
                  size="sm"
                  sr-prefix="Export status:"
                />
              </td>
              <td class="px-4 py-3 text-right font-semibold text-sv-text-heading">
                {{ exp.row_count ?? '—' }}
              </td>
              <td class="px-4 py-3 text-right font-semibold text-sv-text-heading">
                {{ exp.download_count }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-sv-text">
                <template v-if="exp.status === 'expired' || exp.status === 'revoked'">
                  <span class="text-sv-text-muted">No longer available</span>
                </template>
                <SvDateTime
                  v-else
                  :value="exp.expires_at"
                  unavailable-label="Set when ready"
                />
              </td>
              <td class="px-4 py-3">
                <RouterLink
                  :to="{ name: 'audit.export-detail', params: { exportUlid: exp.id } }"
                  class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-2 font-semibold text-sv-link underline-offset-4 hover:underline"
                >
                  Open<span class="sr-only"> export requested for {{ exp.reason }}</span>
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <ul class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden">
        <li
          v-for="exp in store.exports"
          :key="exp.id"
        >
          <AuditExportStatusCard :export-record="exp" />
        </li>
      </ul>

      <SvPagination
        v-if="store.meta.last_page > 1"
        class="mt-4"
        :current-page="store.meta.current_page"
        :last-page="store.meta.last_page"
        :total="store.meta.total"
        label="Export history pages"
        :disabled="store.loading"
        @change="goToPage"
      />
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-status-filter {
  border-color: var(--sv-color-border-default);
  background: var(--sv-color-surface-raised);
  color: var(--sv-color-text-primary);
}
.sv-status-filter[aria-pressed='true'] {
  border-color: var(--sv-color-brand-secondary);
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 14%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
  box-shadow: inset 0 0 0 1px var(--sv-color-brand-secondary);
}
</style>
