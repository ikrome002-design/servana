<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';
import { humanizeStatus, nairobiDate, nairobiTime, personnelStatusTone } from '@/utils/personnelStatus';

// Requests that named the acting Personnel member (UI-14 read seam). A preference is NOT an
// assignment: the server's `assigned_to_you` / `assignment_label` is authoritative, and there is
// deliberately no accept/decline control — Front Office owns assignment and transfer.
const store = usePersonnelExperienceStore();
const source = ref('');
const sources = [{ value: '', label: 'All' }, { value: 'queue', label: 'Queue' }, { value: 'appointment', label: 'Appointments' }];
const state = computed(() => store.loading.preferred ? 'loading' : store.errors.preferred ? 'error' : store.preferredRequests.length ? 'success' : 'empty');
const assigned = computed(() => store.preferredRequests.filter((request) => request.assigned_to_you).length);
function choose(value: string): void { source.value = value; load(); }
function load(): void { void store.fetchPreferredRequests(source.value ? { source: source.value } : {}); }
onMounted(load);
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="personnel-preferred-requests"
  >
    <SvTonalPageHeader
      title="Preferred requests"
      eyebrow="My work"
      tone="warm"
      description="Clients who asked for you by name. A preference is not an assignment — the assignment label on each request is authoritative."
    >
      <div class="grid grid-cols-2 gap-3 md:max-w-xl">
        <SvStatTile
          label="Requests in view"
          tone="sun"
        >
          {{ store.preferredRequests.length }}
        </SvStatTile>
        <SvStatTile
          label="Assigned to you"
          tone="green"
        >
          {{ assigned }}
        </SvStatTile>
      </div>
    </SvTonalPageHeader>

    <div
      role="group"
      aria-label="Request source"
      class="mb-5 flex flex-wrap gap-2"
    >
      <button
        v-for="option in sources"
        :key="option.value"
        type="button"
        :aria-pressed="source === option.value"
        class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-full border px-4 text-sm font-semibold transition-colors duration-sv-fast motion-reduce:transition-none"
        :class="source === option.value ? 'border-sv-brand bg-sv-selected-bg text-sv-selected-fg' : 'border-sv-border bg-sv-surface-raised text-sv-text-secondary hover:border-sv-brand'"
        @click="choose(option.value)"
      >
        {{ option.label }}
      </button>
    </div>

    <SvStateBoundary
      :state="state"
      :error-message="store.errors.preferred ?? undefined"
      empty-message="No clients have requested you in this view."
      @retry="load"
    >
      <ul
        class="grid gap-3 lg:grid-cols-2"
        aria-label="Preferred requests"
      >
        <li
          v-for="request in store.preferredRequests"
          :key="request.source + request.id"
        >
          <SvLeadRecord
            :lead-value="request.position ? `#${request.position}` : nairobiTime(request.requested_at)"
            :lead-label="request.position ? 'In queue' : request.source === 'appointment' ? 'Booked' : 'Requested'"
            :tone="request.assigned_to_you ? 'green' : 'sun'"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
              {{ request.source === 'queue' ? 'Queue request' : 'Appointment request' }}
            </p>
            <p class="mt-1 font-display text-base font-bold text-sv-text-heading">
              {{ request.service.name }}
            </p>
            <div class="mt-2">
              <SvMaskedIdentity
                size="sm"
                :name="request.client.full_name"
                :phone-masked="request.client.phone_masked"
              />
            </div>
            <template #status>
              <SvStatusBadge
                size="sm"
                :label="humanizeStatus(request.status)"
                :tone="personnelStatusTone(request.status)"
              />
            </template>
            <template #meta>
              <p
                class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="request.assigned_to_you ? 'bg-sv-success-bg text-sv-success-fg' : 'bg-sv-warning-bg text-sv-warning-fg'"
              >
                {{ request.assignment_label }}
              </p>
              <p class="mt-2 text-xs text-sv-text-muted">
                Requested {{ nairobiDate(request.requested_at, true) }}
              </p>
            </template>
          </SvLeadRecord>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
