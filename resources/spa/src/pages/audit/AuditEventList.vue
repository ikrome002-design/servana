<script setup lang="ts">
/**
 * Branch Audit Log — Appendix A §12.4.3 (Phase 19 read; Phase UI-15 experience).
 *
 * The append-only, hash-chained branch record for every general-segment module, filtered and
 * paginated by the server inside the caller's assigned branch (`audit.branch_events.view`).
 * Finance and compensation events are separate permission segments with their own views, so this
 * page links to them instead of mixing them in. Every value is masked server-side; this page has
 * no edit, delete, reorder or raw-JSON control because none may exist.
 */
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import AuditEventFeed from '@/components/audit/AuditEventFeed.vue';
import AuditFilterPanel from '@/components/audit/AuditFilterPanel.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconChainLink, SvIconChevronRight, SvIconDownload } from '@/design-system/icons';
import { usePermissionStore } from '@/stores/permissionStore';

const permissions = usePermissionStore();

const lenses = computed(() => [
  { name: 'audit.audit-operations', label: 'Operational', permitted: permissions.can('audit.branch_events.view') },
  { name: 'audit.audit-finance', label: 'Finance', permitted: permissions.can('audit.finance.view') },
  { name: 'audit.audit-compensation', label: 'Compensation', permitted: permissions.can('audit.compensation.view') },
  { name: 'audit.audit-access', label: 'Access and security', permitted: permissions.can('audit.branch_events.view') },
  { name: 'audit.audit-privacy-preferred', label: 'Contact protection', permitted: permissions.can('audit.branch_events.view') },
].filter((lens) => lens.permitted));
const canExport = computed(() => permissions.can('audit.export'));
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="audit-log-page"
  >
    <SvTonalPageHeader
      eyebrow="Audit"
      title="Branch Audit Log"
      description="Every recorded branch event, newest first. The record is append-only and hash-chained; filters and pages are applied by the server."
      context="Assigned branch · append-only"
      tone="teal"
    >
      <template #actions>
        <RouterLink
          v-if="canExport"
          :to="{ name: 'audit.exports' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-2 rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          <SvIconDownload
            aria-hidden="true"
            class="h-4 w-4"
          />Request masked export
        </RouterLink>
        <RouterLink
          :to="{ name: 'audit.audit-integrity' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-2 rounded-control border border-sv-border bg-sv-surface-raised px-4 text-sm font-semibold text-sv-text"
        >
          <SvIconChainLink
            aria-hidden="true"
            class="h-4 w-4"
          />Integrity status
        </RouterLink>
      </template>
      <nav
        aria-label="Audit views"
        class="flex flex-wrap items-center gap-2"
      >
        <span class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">Focused views</span>
        <RouterLink
          v-for="lens in lenses"
          :key="lens.name"
          :to="{ name: lens.name }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-1 rounded-pill border border-sv-border bg-sv-surface-raised px-4 text-sm font-semibold text-sv-text transition-colors duration-sv-fast hover:bg-sv-surface-subtle motion-reduce:transition-none"
        >
          {{ lens.label }}
          <SvIconChevronRight
            aria-hidden="true"
            class="h-4 w-4"
          />
        </RouterLink>
      </nav>
    </SvTonalPageHeader>

    <div class="flex flex-col gap-4">
      <AuditFilterPanel id-prefix="audit-log" />
      <AuditEventFeed
        label="Branch audit events"
        empty-message="No audit events match these filters in your branch. Clear a filter or widen the date range."
      />
    </div>
  </section>
</template>
