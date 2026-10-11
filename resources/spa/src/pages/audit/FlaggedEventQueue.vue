<script setup lang="ts">
/**
 * Flagged Events — Appendix A §12.4.5 (Phase 19 workflow; Phase UI-15 experience).
 *
 * The triage workspace for the only Audit-role mutation: review metadata on suspicious events.
 * Status tabs carry server counts; each record leads with how long the review has been waiting,
 * and shows the SOURCE event's immutable severity separately from the flag's mutable review
 * status. Critical unresolved flags on the current page are pinned first and say why.
 *
 * Only the transitions the backend offers exist: start review (assigns the reviewer), resolve,
 * dismiss, reopen — on the review page. There is no reassign, escalate or category control,
 * because no such endpoint exists.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import AuditReviewBadge from '@/components/audit/AuditReviewBadge.vue';
import AuditSeverityBadge from '@/components/audit/AuditSeverityBadge.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconClock, SvIconCritical } from '@/design-system/icons';
import { fetchFlagPage, useFlaggedEventStore, type FlaggedEventView, type FlaggedStatus } from '@/stores/flaggedEventStore';
import { ageLabel, humanizeAction, isActiveReview, REVIEW_STATUSES, reviewPresentation } from '@/utils/auditPresentation';

const route = useRoute();
const router = useRouter();
const store = useFlaggedEventStore();

type Tab = FlaggedStatus | 'all';
const TABS: Array<{ id: Tab; label: string }> = [
  { id: 'open', label: 'Open' },
  { id: 'under_review', label: 'Under review' },
  { id: 'reopened', label: 'Reopened' },
  { id: 'resolved', label: 'Resolved' },
  { id: 'dismissed', label: 'Dismissed' },
  { id: 'all', label: 'All' },
];

const counts = ref<Partial<Record<Tab, number | null>>>({});
const now = ref(new Date());

const activeTab = computed<Tab>(() => {
  const value = route.query.status;
  return typeof value === 'string' && (REVIEW_STATUSES as string[]).includes(value) ? (value as FlaggedStatus) : 'all';
});
const page = computed(() => {
  const value = Number.parseInt(String(route.query.page ?? '1'), 10);
  return Number.isFinite(value) && value > 0 ? value : 1;
});

const state = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && store.items.length === 0) return 'loading';
  if (store.error) return 'error';
  if (store.items.length === 0) return 'empty';
  return 'success';
});

/** Pin critical, still-active flags on this page first; everything else keeps server order. */
const ordered = computed(() => {
  const pinned = store.items.filter((item) => isPinned(item));
  return [...pinned, ...store.items.filter((item) => !isPinned(item))];
});

function isPinned(item: FlaggedEventView): boolean {
  return item.audit_event?.severity === 'critical' && isActiveReview(item.status);
}

function load(): void {
  store.filterStatus = activeTab.value === 'all' ? '' : activeTab.value;
  now.value = new Date();
  void store.fetchAll(page.value);
}

async function loadCounts(): Promise<void> {
  await Promise.all(TABS.map(async (tab) => {
    try {
      const result = await fetchFlagPage(tab.id === 'all' ? '' : tab.id, 1);
      counts.value[tab.id] = result.meta.total;
    } catch {
      counts.value[tab.id] = null;
    }
  }));
}

function selectTab(tab: Tab): void {
  void router.replace({ query: tab === 'all' ? {} : { status: tab } });
}

function goToPage(next: number): void {
  void router.replace({ query: { ...route.query, page: next > 1 ? String(next) : undefined } });
}

watch(() => route.query, load);
onMounted(() => {
  load();
  void loadCounts();
});
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="audit-flags-page"
  >
    <SvTonalPageHeader
      eyebrow="Review"
      title="Flagged Events"
      description="Your Audit team’s review queue. Reviews record findings about events — the events themselves never change."
      context="Assigned branch"
      tone="warm"
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'audit.audit' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          Find an event to flag
        </RouterLink>
      </template>
      <div
        class="flex flex-wrap gap-2"
        role="group"
        aria-label="Filter by review status"
      >
        <button
          v-for="tab in TABS"
          :key="tab.id"
          type="button"
          class="sv-status-tab sv-focus-ring inline-flex min-h-sv-touch items-center gap-2 rounded-pill border px-4 text-sm font-semibold transition-colors duration-sv-fast motion-reduce:transition-none"
          :aria-pressed="activeTab === tab.id"
          :data-testid="`audit-flags-tab-${tab.id}`"
          @click="selectTab(tab.id)"
        >
          {{ tab.label }}
          <span class="rounded-pill bg-sv-surface-subtle px-2 text-xs font-bold text-sv-text-heading">
            <span aria-hidden="true">{{ counts[tab.id] === undefined ? '…' : counts[tab.id] === null ? '—' : counts[tab.id] }}</span>
            <span class="sr-only">{{ counts[tab.id] === undefined ? ', count loading' : counts[tab.id] === null ? ', count unavailable' : `, ${counts[tab.id]} flags` }}</span>
          </span>
        </button>
      </div>
    </SvTonalPageHeader>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <SvStateBoundary
        :state="state"
        error-message="We couldn’t load the review queue."
        :empty-message="activeTab === 'all' ? 'Nothing has been flagged in your branch yet.' : `No ${reviewPresentation(activeTab).label.toLowerCase()} flags.`"
        @retry="load"
      >
        <ul
          class="flex flex-col gap-3"
          :aria-busy="store.loading"
        >
          <li
            v-for="item in ordered"
            :key="item.id"
          >
            <article
              class="sv-flag-card grid grid-cols-[auto_minmax(0,1fr)] gap-4 rounded-card border border-sv-border bg-sv-surface-raised p-4 shadow-card md:p-5"
              :data-pinned="isPinned(item)"
              data-testid="flagged-row"
            >
              <div class="sv-flag-card__age flex min-w-[4.5rem] flex-col items-center justify-center rounded-control px-2 py-3 text-center">
                <SvIconClock
                  aria-hidden="true"
                  class="h-4 w-4 text-sv-text-secondary"
                />
                <span class="mt-1 font-display text-lg font-extrabold leading-none text-sv-text-heading">{{ ageLabel(item.created_at, now) }}</span>
                <span class="mt-1 text-[0.6875rem] font-semibold uppercase tracking-wide text-sv-text-secondary">since flagged</span>
              </div>
              <div class="min-w-0">
                <p
                  v-if="isPinned(item)"
                  class="mb-1 inline-flex items-center gap-1 text-xs font-bold uppercase tracking-wide text-sv-error-fg"
                >
                  <SvIconCritical
                    aria-hidden="true"
                    class="h-3.5 w-3.5"
                  />Pinned · critical source event, review still active
                </p>
                <h2 class="break-words font-display text-base font-bold text-sv-text-heading md:text-lg">
                  {{ item.audit_event ? humanizeAction(item.audit_event.action) : 'Source event unavailable' }}
                </h2>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                  <AuditSeverityBadge
                    v-if="item.audit_event"
                    :severity="item.audit_event.severity"
                    size="sm"
                  />
                  <AuditReviewBadge :status="item.status" />
                </div>
                <dl class="mt-3 grid grid-cols-1 gap-x-5 gap-y-1 text-xs md:grid-cols-2">
                  <div>
                    <dt class="text-sv-text-muted">
                      Reviewer
                    </dt>
                    <dd class="font-medium text-sv-text">
                      {{ item.assigned_to ?? 'Not yet started' }}
                    </dd>
                  </div>
                  <div>
                    <dt class="text-sv-text-muted">
                      Event actor
                    </dt>
                    <dd class="font-medium text-sv-text">
                      {{ item.audit_event?.actor ?? 'System' }}
                    </dd>
                  </div>
                </dl>
                <p
                  v-if="item.review_notes"
                  class="mt-2 line-clamp-2 text-sm text-sv-text-secondary"
                >
                  {{ item.review_notes }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                  <RouterLink
                    :to="{ name: 'audit.flag-detail', params: { flagUlid: item.id } }"
                    class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
                    data-testid="flagged-open"
                  >
                    Open review<span class="sr-only">: {{ item.audit_event ? humanizeAction(item.audit_event.action) : item.id }}</span>
                  </RouterLink>
                  <RouterLink
                    v-if="item.audit_event"
                    :to="{ name: 'audit.audit-event-detail', params: { eventUlid: item.audit_event.id }, query: { from: route.fullPath } }"
                    class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-sv-border px-4 text-sm font-semibold text-sv-text"
                  >
                    Source evidence
                  </RouterLink>
                </div>
              </div>
            </article>
          </li>
        </ul>
        <SvPagination
          v-if="store.meta.last_page > 1"
          class="mt-4"
          :current-page="store.meta.current_page"
          :last-page="store.meta.last_page"
          :total="store.meta.total"
          label="Flagged events pages"
          :disabled="store.loading"
          @change="goToPage"
        />
      </SvStateBoundary>

      <aside
        class="flex flex-col gap-4"
        aria-label="How reviews work"
      >
        <section class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
          <h2 class="font-display text-base font-bold text-sv-text-heading">
            Two different facts
          </h2>
          <dl class="mt-3 flex flex-col gap-3 text-sm">
            <div>
              <dt class="flex items-center gap-2">
                <AuditSeverityBadge
                  severity="high"
                  size="sm"
                />
              </dt>
              <dd class="mt-1 text-sv-text-secondary">
                <strong class="text-sv-text-heading">Event severity</strong> was set when the event was recorded and never changes.
              </dd>
            </div>
            <div>
              <dt>
                <AuditReviewBadge status="under_review" />
              </dt>
              <dd class="mt-1 text-sv-text-secondary">
                <strong class="text-sv-text-heading">Review status</strong> is your team’s progress on the flag.
              </dd>
            </div>
          </dl>
        </section>
        <section class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
          <h2 class="font-display text-base font-bold text-sv-text-heading">
            Review lifecycle
          </h2>
          <ol class="mt-3 flex flex-col gap-2 text-sm text-sv-text-secondary">
            <li><strong class="text-sv-text-heading">Open</strong> → start review (you become the reviewer)</li>
            <li><strong class="text-sv-text-heading">Under review</strong> → resolve or dismiss, with a note</li>
            <li><strong class="text-sv-text-heading">Resolved / dismissed</strong> → reopen if new evidence appears</li>
          </ol>
          <p class="mt-3 text-xs text-sv-text-muted">
            Every review change is itself recorded in the audit log.
          </p>
        </section>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.sv-status-tab {
  border-color: var(--sv-color-border-default);
  background: var(--sv-color-surface-raised);
  color: var(--sv-color-text-primary);
}
.sv-status-tab[aria-pressed='true'] {
  border-color: var(--sv-color-brand-primary);
  background: color-mix(in srgb, var(--sv-color-brand-primary) 14%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
  box-shadow: inset 0 0 0 1px var(--sv-color-brand-primary);
}
.sv-flag-card {
  border-left: 4px solid color-mix(in srgb, var(--sv-color-brand-primary) 65%, var(--sv-color-border-default));
}
.sv-flag-card[data-pinned='true'] {
  border-left-color: var(--sv-color-status-error-border);
  box-shadow: var(--sv-shadow-raised);
}
.sv-flag-card__age {
  background: color-mix(in srgb, var(--sv-color-brand-primary) 12%, var(--sv-color-surface-raised));
}
</style>
