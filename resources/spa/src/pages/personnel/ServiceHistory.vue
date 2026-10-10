<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconFilter } from '@/design-system/icons';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';
import { humanizeStatus, nairobiDate, nairobiTime, personnelStatusTone } from '@/utils/personnelStatus';

// Own completed/cancelled sessions only (UI-14 read seam). Every figure — counts, commission
// status and its explanation — is the server's. No merchant-wide revenue, no ranking, no
// comparison with other Personnel and no client contact beyond the masked value.
const store = usePersonnelExperienceStore();
const filters = reactive({ status: '', date_from: '', date_to: '' });
const state = computed(() => store.loading.history ? 'loading' : store.errors.history ? 'error' : store.history.length ? 'success' : 'empty');
const statusOptions = [{ value: '', label: 'All outcomes' }, { value: 'completed', label: 'Completed' }, { value: 'cancelled', label: 'Cancelled' }];
function load(): void {
  const params: Record<string, string> = {};
  if (filters.status) params.status = filters.status;
  if (filters.date_from) params.date_from = filters.date_from;
  if (filters.date_to) params.date_to = filters.date_to;
  void store.fetchHistory(params);
}
onMounted(load);
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="personnel-service-history"
  >
    <SvTonalPageHeader
      title="Service history"
      eyebrow="My work"
      tone="green"
      description="Your completed and cancelled sessions only. Contacts stay masked, and every commission label comes from the server."
    >
      <div
        v-if="store.historySummary"
        class="grid grid-cols-2 gap-3 md:grid-cols-4"
      >
        <SvStatTile
          label="Completed"
          tone="green"
          :hint="`${store.historySummary.cancelled} cancelled`"
        >
          {{ store.historySummary.completed }}
        </SvStatTile>
        <SvStatTile
          label="Clients served"
          tone="teal"
        >
          {{ store.historySummary.clients_served }}
        </SvStatTile>
        <SvStatTile
          label="Preferred honored"
          tone="sun"
        >
          {{ store.historySummary.preferred_requests_honored }}
        </SvStatTile>
        <SvStatTile
          label="Records in view"
          tone="neutral"
        >
          {{ store.historySummary.records }}
        </SvStatTile>
      </div>
    </SvTonalPageHeader>

    <div class="grid gap-6 lg:grid-cols-[0.3fr_0.7fr]">
      <form
        class="flex flex-col gap-4 self-start rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card lg:sticky lg:top-6"
        aria-labelledby="history-filter-heading"
        @submit.prevent="load"
      >
        <h2
          id="history-filter-heading"
          class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading"
        >
          <SvIconFilter
            aria-hidden="true"
            class="h-5 w-5"
          />Filter
        </h2>
        <SvSelect
          id="history-status"
          v-model="filters.status"
          label="Outcome"
          :options="statusOptions"
        />
        <label class="text-sm font-medium text-sv-text">From<input
          v-model="filters.date_from"
          type="date"
          class="sv-focus-ring mt-1 min-h-sv-touch w-full rounded-control border border-sv-border-input bg-sv-surface-raised px-3 text-sv-text"
        ></label>
        <label class="text-sm font-medium text-sv-text">To<input
          v-model="filters.date_to"
          type="date"
          class="sv-focus-ring mt-1 min-h-sv-touch w-full rounded-control border border-sv-border-input bg-sv-surface-raised px-3 text-sv-text"
        ></label>
        <button
          type="submit"
          class="sv-focus-ring min-h-sv-touch rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          Apply filters
        </button>
      </form>

      <SvStateBoundary
        :state="state"
        :error-message="store.errors.history ?? undefined"
        empty-message="No service history matches these filters."
        @retry="load"
      >
        <ul
          class="flex flex-col gap-3"
          aria-label="Service history"
        >
          <li
            v-for="record in store.history"
            :key="record.id"
          >
            <SvLeadRecord
              :lead-value="nairobiTime(record.completed_at ?? record.cancelled_at ?? record.started_at)"
              :lead-label="nairobiDate(record.completed_at ?? record.cancelled_at ?? record.started_at)"
              :tone="record.status === 'completed' ? 'green' : 'clay'"
            >
              <p class="font-display text-base font-bold text-sv-text-heading">
                {{ record.service?.name ?? 'Service' }}
              </p>
              <div class="mt-2">
                <SvMaskedIdentity
                  size="sm"
                  :name="record.client?.full_name"
                  :phone-masked="record.client?.phone_masked"
                />
              </div>
              <template #status>
                <SvStatusBadge
                  size="sm"
                  :label="humanizeStatus(record.status)"
                  :tone="personnelStatusTone(record.status)"
                />
              </template>
              <template #meta>
                <div class="flex flex-wrap items-center gap-2">
                  <span class="rounded-full bg-sv-surface-subtle px-2.5 py-1 text-xs font-semibold text-sv-text">{{ record.duration_minutes ?? '—' }} min</span>
                  <span
                    v-if="record.preferred_personnel_honored"
                    class="rounded-full bg-sv-selected-bg px-2.5 py-1 text-xs font-semibold text-sv-selected-fg"
                  >Requested you</span>
                  <SvStatusBadge
                    size="sm"
                    sr-prefix="Commission:"
                    :label="`Commission ${record.commission.status ? humanizeStatus(record.commission.status).toLowerCase() : 'not recorded'}`"
                    :tone="personnelStatusTone(record.commission.status)"
                  />
                </div>
                <p class="mt-2 text-xs leading-5 text-sv-text-muted">
                  {{ record.commission.explanation }}
                </p>
              </template>
            </SvLeadRecord>
          </li>
        </ul>
      </SvStateBoundary>
    </div>
  </section>
</template>
