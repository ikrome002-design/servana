<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { useFrontOfficeWorkspaceStore } from '@/stores/frontOfficeWorkspaceStore';

const store = useFrontOfficeWorkspaceStore();
const domain = ref('');
const options = [
  { value: '', label: 'All branch work' },
  { value: 'clients', label: 'Clients' },
  { value: 'appointments', label: 'Appointments' },
  { value: 'queue', label: 'Queue and walk-ins' },
  { value: 'sessions', label: 'Service sessions' },
  { value: 'invoices', label: 'Invoices' },
  { value: 'billing', label: 'Payment and receipts' },
];
const state = computed(() => (store.activityLoading ? 'loading' : store.activityError ? 'error' : store.activity.length ? 'success' : 'empty'));

function load(): void {
  void store.fetchActivity({ domain: domain.value || undefined });
}
function label(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1);
}

onMounted(load);
</script>

<template>
  <section
    class="mx-auto max-w-5xl"
    data-testid="front-office-activity"
  >
    <SvOperationalHero
      eyebrow="Today’s chronology"
      title="Daily activity"
      description="A concise branch work log of the operational transitions that matter at the service desk. This is not the raw Audit account."
    >
      <template #actions>
        <SvButton
          variant="secondary"
          @click="load"
        >
          Refresh activity
        </SvButton>
      </template>
    </SvOperationalHero>

    <SvCard
      class="mt-5"
      padding="lg"
    >
      <form
        class="flex flex-wrap items-end gap-3"
        @submit.prevent="load"
      >
        <div class="min-w-64 flex-1">
          <SvSelect
            id="activity-domain"
            v-model="domain"
            label="Work area"
            :options="options"
          />
        </div>
        <SvButton type="submit">
          Apply filter
        </SvButton>
      </form>

      <div class="mt-5">
        <SvStateBoundary
          :state="state"
          :error-message="store.activityError ?? undefined"
          empty-message="No Front Office activity is recorded for today yet."
          @retry="load"
        >
          <ol class="relative space-y-3 before:absolute before:bottom-5 before:left-[1.15rem] before:top-5 before:w-px before:bg-sv-border">
            <li
              v-for="event in store.activity"
              :key="event.id"
              class="relative grid grid-cols-[2.25rem_1fr] gap-3"
            >
              <span
                aria-hidden="true"
                class="z-10 mt-4 h-3 w-3 justify-self-center rounded-full border-2 border-sv-surface-raised bg-primary"
              />
              <article class="rounded-control border border-sv-border bg-sv-surface-subtle p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <h2 class="font-semibold text-heading">
                      {{ event.label }}
                    </h2>
                    <p class="mt-1 text-xs text-text-muted">
                      {{ event.action }}
                    </p>
                  </div>
                  <SvStatusBadge
                    :label="label(event.domain)"
                    tone="info"
                    sr-prefix="Work area:"
                  />
                </div>
                <time
                  v-if="event.occurred_at"
                  class="mt-3 block text-sm text-text-muted"
                  :datetime="event.occurred_at"
                >{{ new Intl.DateTimeFormat('en-KE', { hour: 'numeric', minute: '2-digit', timeZone: 'Africa/Nairobi' }).format(new Date(event.occurred_at)) }}</time>
              </article>
            </li>
          </ol>
        </SvStateBoundary>
      </div>
    </SvCard>
  </section>
</template>
