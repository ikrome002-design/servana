<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuditEventStore } from '@/stores/auditEventStore';

/**
 * Branch audit event list (Plan §70, §74; Phase 19). Read-only, branch-scoped,
 * field-masked. The server confines rows to the caller's assigned active branches
 * and excludes merchant-level (branch_id = null) rows — this screen never renders
 * raw before/after/context. Filters/sorts are the backend allowlist only.
 */
const store = useAuditEventStore();

const severityOptions = [
  { value: '', label: 'All severities' },
  { value: 'info', label: 'Info' },
  { value: 'notice', label: 'Notice' },
  { value: 'warning', label: 'Warning' },
  { value: 'high', label: 'High' },
  { value: 'critical', label: 'Critical' },
];

const sortOptions = [
  { value: '-created_at', label: 'Newest first' },
  { value: 'created_at', label: 'Oldest first' },
  { value: '-severity', label: 'Severity (high→low)' },
  { value: 'action', label: 'Action (A→Z)' },
];

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.events.length === 0) return 'empty';
  return 'success';
});

function applyFilters(): void {
  void store.fetchEvents('general', 1);
}

function goToPage(page: number): void {
  if (page >= 1 && page <= store.meta.last_page) void store.fetchEvents('general', page);
}

onMounted(() => {
  void store.fetchEvents('general', 1);
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Branch audit log
    </h1>
    <p class="mt-1 text-sm text-text-muted">
      Read-only, branch-scoped events. Sensitive values are masked.
    </p>

    <form
      class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
      aria-label="Filter audit events"
      @submit.prevent="applyFilters"
    >
      <SvSelect
        id="audit-severity"
        v-model="store.filters.severity"
        label="Severity"
        :options="severityOptions"
        @update:model-value="applyFilters"
      />
      <SvTextInput
        id="audit-action"
        v-model="store.filters.action"
        label="Action"
        placeholder="e.g. invoice.created"
        @keyup.enter="applyFilters"
      />
      <SvTextInput
        id="audit-date-from"
        v-model="store.filters.date_from"
        label="From"
        type="date"
        @change="applyFilters"
      />
      <SvSelect
        id="audit-sort"
        v-model="store.filters.sort"
        label="Sort"
        :options="sortOptions"
        @update:model-value="applyFilters"
      />
    </form>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load audit events."
      empty-message="No audit events match these filters."
      @retry="applyFilters"
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="event in store.events"
          :key="event.id"
        >
          <SvCard
            as="article"
            padding="md"
            data-testid="audit-event-row"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-display font-semibold text-heading">
                  {{ event.action }}
                </p>
                <p class="text-sm text-text-muted">
                  <span data-testid="audit-event-severity">{{ event.severity }}</span>
                  <span v-if="event.actor"> · {{ event.actor }}</span>
                  <span v-if="event.subject_type"> · {{ event.subject_type }}</span>
                </p>
                <p class="text-xs text-text-muted">
                  {{ event.created_at }}
                </p>
              </div>
              <RouterLink
                :to="{ name: 'audit.event-detail', params: { id: event.id } }"
                class="inline-flex min-h-[44px] items-center rounded-control px-3 text-sm font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                data-testid="audit-event-open"
              >
                View
              </RouterLink>
            </div>
          </SvCard>
        </li>
      </ul>

      <nav
        v-if="store.meta.last_page > 1"
        class="mt-4 flex items-center justify-between"
        aria-label="Pagination"
      >
        <SvButton
          variant="secondary"
          :disabled="store.meta.current_page <= 1"
          @click="goToPage(store.meta.current_page - 1)"
        >
          Previous
        </SvButton>
        <span class="text-sm text-text-muted">
          Page {{ store.meta.current_page }} of {{ store.meta.last_page }}
        </span>
        <SvButton
          variant="secondary"
          :disabled="store.meta.current_page >= store.meta.last_page"
          @click="goToPage(store.meta.current_page + 1)"
        >
          Next
        </SvButton>
      </nav>
    </SvStateBoundary>
  </section>
</template>
