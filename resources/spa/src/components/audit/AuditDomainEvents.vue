<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuditEventStore, type AuditDomain } from '@/stores/auditEventStore';

/**
 * Shared read-only, branch-scoped, masked audit-event panel for a single domain
 * segment (Plan §70; Phase 19). Used by the finance and compensation Audit views.
 * The server enforces the domain permission (audit.finance.view / audit.compensation
 * .view, or finance.audit.view for Finance) and branch scope; merchant-level
 * (branch_id = null) rows are never returned. When a domain has no events yet
 * (e.g. compensation until its owning phases), it shows an honest empty state —
 * no events are ever fabricated.
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
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-2xl font-bold text-heading">
          {{ title }}
        </h1>
        <p class="mt-1 text-sm text-text-muted">
          {{ subtitle }}
        </p>
        <p
          v-if="mfaNote"
          class="mt-1 text-xs font-medium text-text-muted"
          data-testid="audit-domain-mfa-note"
        >
          Multi-factor authentication is required to view this audit trail.
        </p>
      </div>
      <SvSelect
        :id="`${domain}-severity-filter`"
        v-model="store.filters.severity"
        label="Severity"
        :options="severityOptions"
        class="w-full sm:w-56"
        @update:model-value="reload"
      />
    </div>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load these audit events."
      :empty-message="emptyMessage"
      @retry="reload"
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="event in store.events"
          :key="event.id"
        >
          <SvCard
            as="article"
            padding="md"
            data-testid="audit-domain-row"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-display font-semibold text-heading">
                  {{ event.action }}
                </p>
                <p class="text-sm text-text-muted">
                  {{ event.severity }}<span v-if="event.actor"> · {{ event.actor }}</span>
                </p>
                <p class="text-xs text-text-muted">
                  {{ event.created_at }}
                </p>
              </div>
              <RouterLink
                v-if="detailRouteName"
                :to="{ name: detailRouteName, params: { id: event.id } }"
                class="inline-flex min-h-[44px] items-center rounded-control px-3 text-sm font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
              >
                View
              </RouterLink>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
