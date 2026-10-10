<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconInfo } from '@/design-system/icons';
import { usePersonnelServiceSessionStore } from '@/stores/serviceSessionStore';
import { nairobiDate, nairobiTime, personnelStatusTone } from '@/utils/personnelStatus';
import { serviceSessionStatusLabel } from '@/utils/serviceSession';

// Personnel own-scope service sessions (Plan §25.2, §19; Phase 16C; UI-14). Shows ONLY sessions
// assigned to the authenticated Personnel user (enforced server-side). Read-only: Front Office
// runs session start/completion, so there is no mutation control, no staff selector, no contact
// export and no commission estimate. Earned commission is created only by Finance validation.
const store = usePersonnelServiceSessionStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.sessions.length === 0) return 'empty';
  return 'success';
});
const active = computed(() => store.sessions.filter((session) => session.status === 'in_progress'));
const others = computed(() => store.sessions.filter((session) => session.status !== 'in_progress'));
const completedCount = computed(() => store.sessions.filter((session) => session.status === 'completed').length);

function duration(start: string | null, end: string | null): string | null {
  if (!start || !end) return null;
  return `${Math.max(0, Math.round((new Date(end).getTime() - new Date(start).getTime()) / 60000))} min`;
}

onMounted(() => {
  void store.fetchMine();
});
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="personnel-my-sessions"
  >
    <SvTonalPageHeader
      title="My sessions"
      eyebrow="My work"
      description="Your own service record. Completing a service proves the work; commission becomes earned only after Finance validates the client payment."
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'personnel.work-history' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center justify-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          View service history
        </RouterLink>
      </template>
      <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
        <SvStatTile
          label="In progress"
          tone="brand"
        >
          {{ active.length }}
        </SvStatTile>
        <SvStatTile
          label="Completed"
          tone="green"
        >
          {{ completedCount }}
        </SvStatTile>
        <div class="col-span-2 flex items-start gap-3 rounded-card border border-sv-info-border bg-sv-info-bg p-4 text-sm text-sv-info-fg md:col-span-1">
          <SvIconInfo
            aria-hidden="true"
            class="mt-0.5 h-5 w-5 shrink-0"
          />
          <p><strong>Earnings boundary.</strong> Payment validation, not this screen, creates earned commission.</p>
        </div>
      </div>
    </SvTonalPageHeader>

    <SvStateBoundary
      :state="boundaryState"
      empty-message="You have no service sessions yet."
      error-message="We couldn’t load your sessions."
      @retry="() => store.fetchMine()"
    >
      <section
        v-if="active.length"
        aria-labelledby="sessions-active-heading"
        class="mb-6"
      >
        <h2
          id="sessions-active-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          With you now
        </h2>
        <div class="grid gap-3 md:grid-cols-2">
          <article
            v-for="session in active"
            :key="session.id"
            class="sv-session-live rounded-card border border-sv-border p-5 shadow-raised"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <p class="font-display text-lg font-extrabold text-sv-text-heading">
                {{ session.service?.name }}
              </p>
              <span data-testid="session-status-badge">
                <SvStatusBadge
                  :label="serviceSessionStatusLabel(session.status)"
                  :tone="personnelStatusTone(session.status)"
                />
              </span>
            </div>
            <div class="mt-4">
              <SvMaskedIdentity
                :name="session.client?.full_name"
                :phone-masked="session.client?.phone_masked"
              />
            </div>
            <p
              v-if="session.started_at"
              class="mt-4 text-sm text-sv-text-secondary"
            >
              Started {{ nairobiTime(session.started_at) }} · Front Office records completion.
            </p>
          </article>
        </div>
      </section>

      <section aria-labelledby="sessions-recent-heading">
        <h2
          id="sessions-recent-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Recent sessions
        </h2>
        <p
          v-if="others.length === 0"
          class="rounded-card border border-dashed border-sv-border-strong p-4 text-sm text-sv-text-muted"
        >
          Completed and cancelled sessions will appear here.
        </p>
        <ul
          v-else
          class="grid gap-3 lg:grid-cols-2"
          aria-label="My sessions"
        >
          <li
            v-for="session in others"
            :key="session.id"
          >
            <SvLeadRecord
              :lead-value="nairobiTime(session.completed_at ?? session.cancelled_at ?? session.started_at)"
              :lead-label="session.status === 'completed' ? 'Finished' : 'Updated'"
              :tone="session.status === 'completed' ? 'green' : session.status === 'cancelled' ? 'clay' : 'teal'"
            >
              <p class="font-display text-base font-bold text-sv-text-heading">
                {{ session.service?.name }}
              </p>
              <div class="mt-2">
                <SvMaskedIdentity
                  size="sm"
                  :name="session.client?.full_name"
                  :phone-masked="session.client?.phone_masked"
                />
              </div>
              <template #status>
                <span data-testid="session-status-badge">
                  <SvStatusBadge
                    size="sm"
                    :label="serviceSessionStatusLabel(session.status)"
                    :tone="personnelStatusTone(session.status)"
                  />
                </span>
              </template>
              <template #meta>
                {{ nairobiDate(session.started_at ?? session.completed_at) }}<span v-if="duration(session.started_at, session.completed_at)"> · {{ duration(session.started_at, session.completed_at) }}</span>
              </template>
            </SvLeadRecord>
          </li>
        </ul>
      </section>
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-session-live {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-secondary) 16%, transparent), transparent 55%),
    var(--sv-color-surface-raised);
}
</style>
