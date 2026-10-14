<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
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
  <!--
    COR-UI08-UI12-VIS-001 — a versioned compensation TIMELINE. Each append-only event sits on a dated
    rail: what happened, who did it, which plan version, the recorded reason as a quotation, and a
    backdated marker where it applies. Previous terms are read-only and never rewritten.
  -->
  <section
    class="sv-r-page"
    data-testid="hr-compensation-history"
  >
    <SvPageHeader
      :title="member ? member.display_name + ' change history' : 'Compensation change history'"
      eyebrow="Declared terms"
      description="Append-only plan versions and approval decisions. Historical configuration remains visible and is never rewritten in place."
    />

    <div class="sv-r-grid sv-r-split">
      <SvStateBoundary
        :state="state"
        :error-message="staff.error ?? undefined"
        empty-message="No compensation change history has been recorded for this staff member."
        @retry="store.fetchStaffHistory(staffId)"
      >
        <ol
          class="relative grid gap-4 border-l-2 border-sv-border pl-6"
          aria-label="Compensation change history"
        >
          <li
            v-for="event in store.staffHistory"
            :key="event.id"
            class="relative"
            data-testid="history-event"
          >
            <span
              class="absolute -left-[1.95rem] top-5 h-3.5 w-3.5 rounded-full border-2 border-sv-surface-raised bg-sv-brand-secondary shadow-card"
              aria-hidden="true"
            />
            <article class="sv-r-record">
              <time
                class="sv-r-eyebrow block"
                :datetime="event.occurred_at ?? undefined"
              >
                {{ event.occurred_at ? formatDateTime(event.occurred_at) : 'Time unavailable' }}
              </time>
              <div class="mt-1 flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 class="font-display text-lg font-bold capitalize text-heading">
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
              <blockquote
                v-if="event.change_reason"
                class="mt-3 rounded-card border-l-4 border-sv-border-strong bg-sv-surface-subtle px-4 py-2 text-sm text-text"
              >
                {{ event.change_reason }}
              </blockquote>
            </article>
          </li>
        </ol>
      </SvStateBoundary>
      <aside
        class="sv-r-stack-sm sv-r-sticky"
        aria-label="History rules"
      >
        <div class="sv-r-note sv-r-tone-teal">
          <p class="sv-r-note__title">
            Append-only
          </p>
          <p class="sv-r-note__body">
            Each version and decision is added, never edited. Earlier terms stay visible exactly as they were declared.
          </p>
        </div>
      </aside>
    </div>
  </section>
</template>