<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { usePersonnelServiceSessionStore } from '@/stores/serviceSessionStore';
import { serviceSessionStatusLabel } from '@/utils/serviceSession';

// Personnel own-scope service sessions (Plan §25.2, §19; Phase 16C). Shows ONLY
// sessions assigned to the authenticated Personnel user (enforced server-side).
// Read-only: no branch-wide filter, no staff selector, no mutation controls, no
// contact export, NO commission preview. Client contact is masked.
const store = usePersonnelServiceSessionStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.sessions.length === 0) return 'empty';
  return 'success';
});

onMounted(() => {
  void store.fetchMine();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      My sessions
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="You have no service sessions yet."
      error-message="We couldn’t load your sessions."
      @retry="() => store.fetchMine()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="My sessions"
      >
        <li
          v-for="session in store.sessions"
          :key="session.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-semibold text-heading">
                  {{ session.client?.full_name ?? 'Client' }}
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  {{ session.service?.name }}
                </p>
                <p
                  v-if="session.started_at"
                  class="mt-0.5 text-xs text-text-muted"
                >
                  Started {{ new Date(session.started_at).toLocaleTimeString() }}
                </p>
              </div>
              <span
                class="rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
                data-testid="session-status-badge"
              >{{ serviceSessionStatusLabel(session.status) }}</span>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
