<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconCalendar, SvIconProfile, SvIconSuccess } from '@/design-system/icons';
import { usePersonnelQueueStore } from '@/stores/queueStore';
import { nairobiTime, personnelStatusTone } from '@/utils/personnelStatus';
import { queueStatusLabel } from '@/utils/queue';

// Personnel own-scope queue (Plan §37, §19; Phase 16B; UI-14). Shows ONLY entries assigned
// to the authenticated Personnel user (enforced server-side). Read-only: no branch-wide
// filter, no staff selector, no reorder/transfer control, no contact export. Client contact
// is the server-masked value only. Ordering is the server's position, never re-sorted here.
const queue = usePersonnelQueueStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (queue.loading) return 'loading';
  if (queue.error) return 'error';
  if (queue.entries.length === 0) return 'empty';
  return 'success';
});
const ordered = computed(() => [...queue.entries].sort((a, b) => a.position - b.position));
const current = computed(() => ordered.value[0] ?? null);
const upNext = computed(() => ordered.value.slice(1));
const preferredCount = computed(() => queue.entries.filter((entry) => entry.is_preferred_request).length);

onMounted(() => {
  void queue.fetchMine();
});
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="personnel-my-queue"
  >
    <SvTonalPageHeader
      title="My queue"
      eyebrow="My work"
      description="Your assigned queue only, in the order the server keeps. Contacts stay masked and Front Office owns assignment and transfers."
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'personnel.work-appointments' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center justify-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          View appointments
        </RouterLink>
      </template>
      <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <SvStatTile
          label="Assigned now"
          tone="brand"
        >
          <template #icon>
            <SvIconProfile class="h-5 w-5" />
          </template>
          {{ queue.entries.length }}
        </SvStatTile>
        <SvStatTile
          label="Next position"
          tone="teal"
        >
          <template #icon>
            <SvIconCalendar class="h-5 w-5" />
          </template>
          {{ current ? `#${current.position}` : '—' }}
        </SvStatTile>
        <SvStatTile
          label="Requested you"
          tone="sun"
        >
          <template #icon>
            <SvIconSuccess class="h-5 w-5" />
          </template>
          {{ preferredCount }}
        </SvStatTile>
      </div>
    </SvTonalPageHeader>

    <SvStateBoundary
      :state="boundaryState"
      empty-message="You have no one in your queue right now."
      error-message="We couldn’t load your queue."
      @retry="() => queue.fetchMine()"
    >
      <div class="grid gap-5 lg:grid-cols-[1.15fr_0.85fr]">
        <section
          v-if="current"
          aria-labelledby="queue-now-heading"
          class="sv-queue-now relative overflow-hidden rounded-card border border-sv-border p-5 shadow-raised md:p-6"
        >
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
            Your turn
          </p>
          <h2
            id="queue-now-heading"
            class="mt-1 font-display text-2xl font-extrabold text-sv-text-heading"
          >
            {{ current.service?.name ?? 'Assigned service' }}
          </h2>
          <div class="mt-5 flex flex-wrap items-center justify-between gap-4">
            <SvMaskedIdentity
              :name="current.client?.full_name"
              :phone-masked="current.client?.phone_masked"
            />
            <span data-testid="queue-status-badge">
              <SvStatusBadge
                :label="queueStatusLabel(current.status)"
                :tone="personnelStatusTone(current.status)"
              />
            </span>
          </div>
          <dl class="mt-6 grid grid-cols-2 gap-3 text-sm md:grid-cols-3">
            <div class="rounded-control border border-sv-border bg-sv-surface-raised p-3">
              <dt class="text-xs text-sv-text-muted">
                Position
              </dt>
              <dd class="mt-1 font-display text-xl font-bold text-sv-text-heading">
                #{{ current.position }}
              </dd>
            </div>
            <div class="rounded-control border border-sv-border bg-sv-surface-raised p-3">
              <dt class="text-xs text-sv-text-muted">
                Estimated wait
              </dt>
              <dd class="mt-1 font-semibold text-sv-text-heading">
                {{ current.estimated_wait.label }}
              </dd>
            </div>
            <div class="col-span-2 rounded-control border border-sv-border bg-sv-surface-raised p-3 md:col-span-1">
              <dt class="text-xs text-sv-text-muted">
                Request
              </dt>
              <dd class="mt-1 font-semibold text-sv-text-heading">
                {{ current.is_preferred_request ? 'Client requested you' : 'Assigned by Front Office' }}
              </dd>
            </div>
          </dl>
        </section>

        <section aria-labelledby="queue-next-heading">
          <h2
            id="queue-next-heading"
            class="mb-3 font-display text-lg font-bold text-sv-text-heading"
          >
            Up next
          </h2>
          <p
            v-if="upNext.length === 0"
            class="rounded-card border border-dashed border-sv-border-strong p-5 text-sm text-sv-text-muted"
          >
            No one else is waiting for you. New assignments appear here when Front Office adds them.
          </p>
          <ul
            v-else
            class="flex flex-col gap-3"
            aria-label="My queue"
          >
            <li
              v-for="entry in upNext"
              :key="entry.id"
            >
              <SvLeadRecord
                :lead-value="`#${entry.position}`"
                lead-label="Position"
                :tone="entry.is_preferred_request ? 'sun' : 'teal'"
              >
                <p class="font-display text-base font-bold text-sv-text-heading">
                  {{ entry.service?.name }}
                </p>
                <div class="mt-2">
                  <SvMaskedIdentity
                    size="sm"
                    :name="entry.client?.full_name"
                    :phone-masked="entry.client?.phone_masked"
                  />
                </div>
                <template #status>
                  <span data-testid="queue-status-badge">
                    <SvStatusBadge
                      size="sm"
                      :label="queueStatusLabel(entry.status)"
                      :tone="personnelStatusTone(entry.status)"
                    />
                  </span>
                </template>
                <template #meta>
                  Joined {{ nairobiTime(entry.queued_at) }} · {{ entry.estimated_wait.label }} · ~{{ entry.estimated_wait.effective_minutes }} min<span v-if="entry.is_preferred_request"> · requested you</span>
                </template>
              </SvLeadRecord>
            </li>
          </ul>
        </section>
      </div>
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-queue-now {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-primary) 18%, transparent), transparent 50%),
    linear-gradient(160deg, color-mix(in srgb, var(--sv-color-accent) 12%, var(--sv-color-surface-raised)), var(--sv-color-surface-raised) 70%);
}
</style>
