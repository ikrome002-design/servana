<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { useCompensationStore } from '@/stores/compensationStore';
import { useStaffStore } from '@/stores/staffStore';
import { formatDateTime } from '@/utils/dates';

const route = useRoute();
const store = useCompensationStore();
const staff = useStaffStore();
const staffId = computed(() => String(route.params.staffUlid ?? ''));
const member = computed(() => staff.current?.id === staffId.value ? staff.current : null);
const state = computed(() => (store.historyLoading || staff.loading ? 'loading' : staff.error ? 'error' : store.staffHistory.length ? 'success' : 'empty'));

function humanize(value: string): string {
  return value.replaceAll('_', ' ').replaceAll('.', ' ');
}

onMounted(async () => {
  await Promise.all([
    staff.fetchStaffMember(staffId.value),
    store.fetchStaffHistory(staffId.value),
  ]);
});
</script>

<template>
  <section
    class="mx-auto max-w-5xl"
    data-testid="hr-compensation-history"
  >
    <SvPageHeader
      :title="member ? member.display_name + ' change history' : 'Compensation change history'"
      eyebrow="Declared terms"
      description="Append-only plan versions and approval decisions. Historical configuration remains visible and is never rewritten in place."
    />

    <SvStateBoundary
      :state="state"
      :error-message="staff.error ?? undefined"
      empty-message="No compensation change history has been recorded for this staff member."
      @retry="store.fetchStaffHistory(staffId)"
    >
      <ol
        class="relative grid gap-3 border-l border-sv-border pl-5"
        aria-label="Compensation change history"
      >
        <li
          v-for="event in store.staffHistory"
          :key="event.id"
          class="relative"
          data-testid="history-event"
        >
          <span
            class="absolute -left-[1.63rem] top-5 h-3 w-3 rounded-full border-2 border-sv-surface bg-sv-brand"
            aria-hidden="true"
          />
          <SvCard as="article">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="font-display font-bold capitalize text-heading">
                  {{ humanize(event.event_label) }}
                </h2>
                <p class="mt-1 text-sm text-text-muted">
                  {{ event.actor_display_name ?? 'System' }} · plan {{ event.plan_id }}
                </p>
              </div>
              <SvStatusBadge
                v-if="event.was_backdated"
                label="Backdated decision"
                tone="warning"
              />
            </div>
            <p
              v-if="event.change_reason"
              class="mt-3 text-sm text-text"
            >
              {{ event.change_reason }}
            </p>
            <time
              class="mt-2 block text-xs text-text-muted"
              :datetime="event.occurred_at ?? undefined"
            >
              {{ event.occurred_at ? formatDateTime(event.occurred_at) : 'Time unavailable' }}
            </time>
          </SvCard>
        </li>
      </ol>
    </SvStateBoundary>
  </section>
</template>
