<script setup lang="ts">
/**
 * AuditEventFeed — a server-paginated stream of immutable, masked audit events (Phase UI-15).
 *
 * Shared by the Branch Audit Log and the five Audit Views. The domain (`general`, `finance`,
 * `compensation`) picks the permission segment and `view` picks the server-side lens; filters come
 * from the URL. The header states the server's total and the exact coverage window requested —
 * an unbounded query says so rather than implying a date range it did not ask for.
 */
import { computed, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import AuditEventCard from '@/components/audit/AuditEventCard.vue';
import AuditFlagDialog from '@/components/audit/AuditFlagDialog.vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuditRouteFilters } from '@/composables/useAuditRouteFilters';
import { useAuditEventStore, type AuditDomain, type AuditEventView, type AuditReviewViewKey } from '@/stores/auditEventStore';
import type { FlaggedEventView } from '@/stores/flaggedEventStore';
import { usePermissionStore } from '@/stores/permissionStore';
import { humanizeAction } from '@/utils/auditPresentation';

const props = withDefaults(defineProps<{
  domain?: AuditDomain;
  view?: AuditReviewViewKey;
  label: string;
  emptyMessage: string;
  /** Visually cluster consecutive identical events on this page (§12.4.9); each event stays listed. */
  groupRepeats?: boolean;
}>(), { domain: 'general', view: undefined, groupRepeats: false });

const route = useRoute();
const store = useAuditEventStore();
const permissions = usePermissionStore();
const { filters, goToPage } = useAuditRouteFilters();

const flagging = ref<AuditEventView | null>(null);
const flagged = ref<FlaggedEventView | null>(null);
const canFlag = computed(() => permissions.can('audit.flagged_event.create'));

const state = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && store.events.length === 0) return 'loading';
  if (store.error) return 'error';
  if (store.events.length === 0) return 'empty';
  return 'success';
});

const coverage = computed(() => {
  const { date_from: from, date_to: to } = filters.value;
  if (from && to) return `${from} to ${to}`;
  if (from) return `from ${from} onwards`;
  if (to) return `up to ${to}`;
  return 'all recorded history in your branch scope';
});

function load(): void {
  const { page, ...rest } = filters.value;
  store.filters = { ...rest, view: props.view ?? '' };
  void store.fetchEvents(props.domain, page);
}

watch(() => [route.query, props.domain, props.view], load, { immediate: true, deep: true });

function onFlagged(flag: FlaggedEventView): void {
  flagging.value = null;
  flagged.value = flag;
  load();
}

interface FeedGroup {
  key: string;
  events: AuditEventView[];
}

/**
 * Consecutive events with the same action and actor on THIS page form one group. Grouping is
 * presentation only: it never merges, hides or counts beyond the page, and the server total above
 * is unaffected.
 */
const groups = computed<FeedGroup[]>(() => {
  const list: FeedGroup[] = [];
  for (const event of store.events) {
    const last = list[list.length - 1];
    const same = last && props.groupRepeats && last.events[0].action === event.action && last.events[0].actor === event.actor;
    if (same) last.events.push(event);
    else list.push({ key: event.id, events: [event] });
  }
  return list;
});

const detailTarget = (event: AuditEventView) => ({
  name: 'audit.audit-event-detail',
  params: { eventUlid: event.id },
  query: { from: route.fullPath },
});
</script>

<template>
  <section
    :aria-label="label"
    class="flex flex-col gap-4"
    data-testid="audit-event-feed"
  >
    <div class="flex flex-col gap-1 md:flex-row md:items-baseline md:justify-between">
      <p
        class="text-sm text-sv-text-secondary"
        role="status"
        aria-live="polite"
        data-testid="audit-feed-summary"
      >
        <template v-if="state === 'success'">
          <strong class="font-semibold text-sv-text-heading">{{ store.meta.total.toLocaleString('en-KE') }}</strong>
          {{ store.meta.total === 1 ? 'event' : 'events' }} · {{ coverage }}
        </template>
        <template v-else-if="state === 'loading'">
          Loading events…
        </template>
      </p>
      <p class="text-xs text-sv-text-muted">
        Append-only record · values masked by the server
      </p>
    </div>

    <SvAlert
      v-if="flagged"
      severity="success"
      title="Flag created"
      dismissible
      data-testid="audit-flag-success"
      @dismiss="flagged = null"
    >
      The event is now in the review queue.
      <RouterLink
        :to="{ name: 'audit.flag-detail', params: { flagUlid: flagged.id } }"
        class="sv-focus-ring font-semibold text-sv-link underline"
      >
        Open the review
      </RouterLink>
    </SvAlert>

    <SvStateBoundary
      :state="state"
      error-message="We couldn’t load these audit events."
      :empty-message="emptyMessage"
      @retry="load"
    >
      <ul
        class="flex flex-col gap-3"
        :aria-busy="store.loading"
      >
        <li
          v-for="group in groups"
          :key="group.key"
        >
          <AuditEventCard
            v-if="group.events.length === 1"
            :event="group.events[0]"
            :to="detailTarget(group.events[0])"
            :can-flag="canFlag"
            @flag="flagging = $event"
          />
          <details
            v-else
            class="sv-repeat-group group rounded-card border p-3 md:p-4"
            data-testid="audit-repeat-group"
          >
            <summary class="sv-focus-ring flex min-h-sv-touch cursor-pointer list-none flex-wrap items-center gap-2 rounded-control">
              <span class="sv-repeat-count inline-flex h-9 min-w-9 items-center justify-center rounded-control px-2 font-display text-lg font-extrabold text-sv-text-heading">{{ group.events.length }}</span>
              <span class="min-w-0 flex-1 font-semibold text-sv-text-heading">
                repeated {{ humanizeAction(group.events[0].action) }} events
                <span class="font-normal text-sv-text-secondary">by {{ group.events[0].actor ?? 'System' }} on this page</span>
              </span>
              <span class="text-sm font-semibold text-sv-link group-open:hidden">Show each event</span>
              <span class="hidden text-sm font-semibold text-sv-link group-open:inline">Hide</span>
            </summary>
            <ul class="mt-3 flex flex-col gap-2">
              <li
                v-for="event in group.events"
                :key="event.id"
              >
                <AuditEventCard
                  :event="event"
                  :to="detailTarget(event)"
                  :can-flag="canFlag"
                  compact
                  @flag="flagging = $event"
                />
              </li>
            </ul>
          </details>
        </li>
      </ul>
      <SvSkeleton
        v-if="store.loading"
        class="mt-3"
        label="Refreshing events"
        :lines="2"
      />
      <SvPagination
        v-if="store.meta.last_page > 1"
        class="mt-4"
        :current-page="store.meta.current_page"
        :last-page="store.meta.last_page"
        :total="store.meta.total"
        :label="`${label} pages`"
        :disabled="store.loading"
        @change="goToPage"
      />
    </SvStateBoundary>

    <AuditFlagDialog
      :event="flagging"
      @close="flagging = null"
      @flagged="onFlagged"
    />
  </section>
</template>

<style scoped>
.sv-repeat-group {
  border-color: color-mix(in srgb, var(--sv-color-status-error-border) 45%, var(--sv-color-border-default));
  background: color-mix(in srgb, var(--sv-color-status-error-bg) 40%, var(--sv-color-surface-raised));
}
.sv-repeat-count {
  background: color-mix(in srgb, var(--sv-color-status-error-border) 22%, var(--sv-color-surface-raised));
}
</style>