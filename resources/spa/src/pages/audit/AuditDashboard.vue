<script setup lang="ts">
/**
 * Audit Dashboard — Appendix A §12.1 and §12.4.1 (Phase UI-15).
 *
 * A branch risk command centre built for "what do I investigate next?": the escalated events,
 * the review queue with its age, the finance / compensation / access / contact-protection slices,
 * integrity, and exports that need attention. Every number is a server total for an exact filter
 * inside the caller's branch scope; a slice the caller may not read says so, and a slice that
 * failed says so — neither is ever shown as zero. Nothing on this page changes a source record.
 */
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import AuditEventCard from '@/components/audit/AuditEventCard.vue';
import AuditFlagDialog from '@/components/audit/AuditFlagDialog.vue';
import AuditReviewBadge from '@/components/audit/AuditReviewBadge.vue';
import AuditSeverityBadge from '@/components/audit/AuditSeverityBadge.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import {
  SvIconChainLink,
  SvIconChevronRight,
  SvIconClock,
  SvIconCritical,
  SvIconDocument,
  SvIconFlag,
  SvIconLocked,
  SvIconSecurity,
  SvIconWarning,
} from '@/design-system/icons';
import { useAccountContextStore } from '@/stores/accountContextStore';
import type { AuditEventView } from '@/stores/auditEventStore';
import { useAuditWorkspaceStore, type SliceState } from '@/stores/auditWorkspaceStore';
import type { FlaggedEventView } from '@/stores/flaggedEventStore';
import { usePermissionStore } from '@/stores/permissionStore';
import { ageLabel, humanizeAction, integrityPresentation, nairobiParts } from '@/utils/auditPresentation';

const store = useAuditWorkspaceStore();
const contexts = useAccountContextStore();
const permissions = usePermissionStore();

const flagging = ref<AuditEventView | null>(null);
const now = ref(new Date());

const branchLabel = computed(() => contexts.currentContext?.branch_name ?? 'Assigned branch');
const canFlag = computed(() => permissions.can('audit.flagged_event.create'));
const d = computed(() => store.dashboard);

function total<T>(slice: SliceState<T>): number | null {
  return slice.state === 'ready' ? slice.total : null;
}

function display(value: number | null, slice: SliceState<unknown>): string {
  if (slice.state === 'loading') return '…';
  if (slice.state === 'unavailable') return 'Not permitted';
  if (value === null) return 'Unavailable';
  return value.toLocaleString('en-KE');
}

const activeReviews = computed<number | null>(() => {
  const parts = [total(d.value.openFlags), total(d.value.underReview), total(d.value.reopened)];
  return parts.some((part) => part === null) ? null : (parts as number[]).reduce((sum, part) => sum + part, 0);
});
const activeReviewSlice = computed<SliceState<FlaggedEventView>>(() => {
  const slices = [d.value.openFlags, d.value.underReview, d.value.reopened];
  if (slices.some((slice) => slice.state === 'loading')) return { state: 'loading' };
  if (slices.some((slice) => slice.state === 'unavailable')) return { state: 'unavailable' };
  if (slices.some((slice) => slice.state === 'failed')) return { state: 'failed' };
  return { state: 'ready', total: activeReviews.value ?? 0, items: [] };
});

/** Critical first, then high — each list already newest-first from the server. */
const investigateNext = computed<AuditEventView[]>(() => {
  const critical = d.value.critical.state === 'ready' ? d.value.critical.items : [];
  const high = d.value.high.state === 'ready' ? d.value.high.items : [];
  return [...critical, ...high].slice(0, 5);
});
const escalatedState = computed(() => {
  const states = [d.value.critical.state, d.value.high.state];
  if (states.includes('loading')) return 'loading';
  if (states.every((state) => state === 'unavailable')) return 'unavailable';
  if (states.includes('failed')) return 'failed';
  return 'ready';
});

/** Oldest-first waiting reviews: open + reopened, from the server's newest-first pages. */
const waitingReviews = computed<FlaggedEventView[]>(() => {
  const open = d.value.openFlags.state === 'ready' ? d.value.openFlags.items : [];
  const reopened = d.value.reopened.state === 'ready' ? d.value.reopened.items : [];
  return [...open, ...reopened]
    .sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)))
    .slice(0, 4);
});

const integrity = computed(() => integrityPresentation(store.integrity?.status));

const exportsInProgress = computed<number | null>(() => {
  const a = total(d.value.exportsInFlight);
  const b = total(d.value.exportsQueued);
  return a === null || b === null ? null : a + b;
});

const slices = computed(() => [
  { key: 'finance', title: 'Finance', route: 'audit.audit-finance', icon: SvIconDocument, tone: 'warm', slice: d.value.finance, blurb: 'Payments, receipts, refunds, cash-up, period locks' },
  { key: 'compensation', title: 'Compensation', route: 'audit.audit-compensation', icon: SvIconClock, tone: 'green', slice: d.value.compensation, blurb: 'Plans, ledgers, payout runs, earnings queries' },
  { key: 'access', title: 'Access and security', route: 'audit.audit-access', icon: SvIconSecurity, tone: 'teal', slice: d.value.access, blurb: 'Invitations, roles, branch access, blocked attempts' },
  { key: 'privacy', title: 'Contact protection', route: 'audit.audit-privacy-preferred', icon: SvIconLocked, tone: 'sun', slice: d.value.privacy, blurb: 'Consent, served-client access, SMS, export attempts' },
] as const);

function observed(iso: string | null): string | undefined {
  const parts = nairobiParts(iso);
  return parts ? `${parts.day}, ${parts.time} (Nairobi)` : undefined;
}

function refresh(): void {
  now.value = new Date();
  void store.fetchDashboard();
}

onMounted(() => {
  if (!contexts.loaded) void contexts.fetchContexts();
  refresh();
});
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="audit-dashboard"
  >
    <SvOperationalHero
      eyebrow="Branch risk review"
      title="Investigate next"
      description="Escalated events, reviews that are waiting and every audit slice of your branch — read-only, masked and append-only."
      :context="branchLabel"
      :observed-at="observed(store.observedAt)"
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'audit.audit-flags' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-2 rounded-control bg-sv-brand px-4 py-2 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          <SvIconFlag
            aria-hidden="true"
            class="h-4 w-4"
          />Open review queue
        </RouterLink>
        <RouterLink
          :to="{ name: 'audit.audit' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/15"
        >
          Search the audit log
        </RouterLink>
      </template>
      <ul
        class="grid grid-cols-2 gap-2 lg:grid-cols-4"
        aria-label="Branch risk at a glance"
      >
        <li>
          <RouterLink
            :to="{ name: 'audit.audit', query: { severity: 'critical' } }"
            class="sv-hero-tile sv-focus-ring flex h-full min-h-sv-touch flex-col rounded-control border border-white/10 bg-white/10 p-3 transition-colors duration-sv-fast hover:bg-white/15 motion-reduce:transition-none"
            data-testid="audit-dashboard-critical"
          >
            <span class="flex items-center gap-1 text-xs font-semibold text-white/80"><SvIconCritical
              aria-hidden="true"
              class="h-4 w-4"
            />Critical events</span>
            <strong class="mt-1 font-display text-2xl">{{ display(total(d.critical), d.critical) }}</strong>
            <span class="text-xs text-white/70">all time, your branch</span>
          </RouterLink>
        </li>
        <li>
          <RouterLink
            :to="{ name: 'audit.audit', query: { severity: 'high' } }"
            class="sv-hero-tile sv-focus-ring flex h-full min-h-sv-touch flex-col rounded-control border border-white/10 bg-white/10 p-3 transition-colors duration-sv-fast hover:bg-white/15 motion-reduce:transition-none"
            data-testid="audit-dashboard-high"
          >
            <span class="flex items-center gap-1 text-xs font-semibold text-white/80"><SvIconWarning
              aria-hidden="true"
              class="h-4 w-4"
            />High events</span>
            <strong class="mt-1 font-display text-2xl">{{ display(total(d.high), d.high) }}</strong>
            <span class="text-xs text-white/70">all time, your branch</span>
          </RouterLink>
        </li>
        <li>
          <RouterLink
            :to="{ name: 'audit.audit-flags' }"
            class="sv-hero-tile sv-focus-ring flex h-full min-h-sv-touch flex-col rounded-control border border-white/10 bg-white/10 p-3 transition-colors duration-sv-fast hover:bg-white/15 motion-reduce:transition-none"
            data-testid="audit-dashboard-active-reviews"
          >
            <span class="flex items-center gap-1 text-xs font-semibold text-white/80"><SvIconFlag
              aria-hidden="true"
              class="h-4 w-4"
            />Active reviews</span>
            <strong class="mt-1 font-display text-2xl">{{ display(activeReviews, activeReviewSlice) }}</strong>
            <span class="text-xs text-white/70">open, under review, reopened</span>
          </RouterLink>
        </li>
        <li>
          <RouterLink
            :to="{ name: 'audit.audit-integrity' }"
            class="sv-hero-tile sv-focus-ring flex h-full min-h-sv-touch flex-col rounded-control border border-white/10 bg-white/10 p-3 transition-colors duration-sv-fast hover:bg-white/15 motion-reduce:transition-none"
            data-testid="audit-dashboard-integrity"
          >
            <span class="flex items-center gap-1 text-xs font-semibold text-white/80"><SvIconChainLink
              aria-hidden="true"
              class="h-4 w-4"
            />Chain integrity</span>
            <strong class="mt-1 font-display text-xl">{{ store.integrityLoading ? '…' : integrity.label }}</strong>
            <span class="text-xs text-white/70">{{ store.integrity ? 'checked just now by the server' : 'no result yet' }}</span>
          </RouterLink>
        </li>
      </ul>
    </SvOperationalHero>

    <div class="mt-5 grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,0.65fr)]">
      <section
        class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-raised md:p-6"
        aria-labelledby="investigate-heading"
        data-testid="audit-dashboard-investigate"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
              Escalated evidence
            </p>
            <h2
              id="investigate-heading"
              class="mt-1 font-display text-xl font-extrabold text-sv-text-heading md:text-2xl"
            >
              Critical and high events
            </h2>
          </div>
          <RouterLink
            :to="{ name: 'audit.audit', query: { severity: 'critical' } }"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
          >
            See all critical
          </RouterLink>
        </div>
        <div class="mt-4">
          <SvSkeleton
            v-if="escalatedState === 'loading'"
            label="Loading escalated events"
            :lines="4"
          />
          <p
            v-else-if="escalatedState === 'unavailable'"
            class="rounded-control border border-dashed border-sv-border p-4 text-sm text-sv-text-secondary"
          >
            Branch events are not readable by your account.
          </p>
          <p
            v-else-if="escalatedState === 'failed'"
            class="rounded-control border border-sv-error-border bg-sv-error-bg p-4 text-sm text-sv-error-fg"
            role="alert"
          >
            Escalated events could not be loaded, so none are shown. <button
              type="button"
              class="sv-focus-ring min-h-sv-touch font-semibold underline"
              @click="refresh"
            >
              Try again
            </button>
          </p>
          <div
            v-else-if="investigateNext.length === 0"
            class="sv-clear rounded-control border p-5"
            data-testid="audit-dashboard-clear"
          >
            <p class="font-display text-lg font-bold text-sv-text-heading">
              No critical or high events recorded
            </p>
            <p class="mt-1 text-sm text-sv-text-secondary">
              Your branch has no escalated audit events. Warnings and routine activity are in the Branch Audit Log.
            </p>
          </div>
          <ul
            v-else
            class="flex flex-col gap-3"
          >
            <li
              v-for="event in investigateNext"
              :key="event.id"
            >
              <AuditEventCard
                :event="event"
                :to="{ name: 'audit.audit-event-detail', params: { eventUlid: event.id }, query: { from: '/dashboard' } }"
                :can-flag="canFlag"
                compact
                @flag="flagging = $event"
              />
            </li>
          </ul>
        </div>
      </section>

      <section
        class="sv-queue rounded-card border p-5 shadow-card md:p-6"
        aria-labelledby="queue-heading"
        data-testid="audit-dashboard-queue"
      >
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
          Review queue
        </p>
        <h2
          id="queue-heading"
          class="mt-1 font-display text-xl font-extrabold text-sv-text-heading"
        >
          Waiting longest
        </h2>
        <dl class="mt-4 grid grid-cols-[repeat(3,minmax(0,1fr))] gap-2 text-center">
          <div class="rounded-control border border-sv-border bg-sv-surface-raised p-2">
            <dt class="break-words text-xs font-semibold text-sv-text-muted">
              Open
            </dt>
            <dd class="font-display text-xl font-extrabold text-sv-text-heading">
              {{ display(total(d.openFlags), d.openFlags) }}
            </dd>
          </div>
          <div class="rounded-control border border-sv-border bg-sv-surface-raised p-2">
            <dt class="break-words text-xs font-semibold text-sv-text-muted">
              In review
            </dt>
            <dd class="font-display text-xl font-extrabold text-sv-text-heading">
              {{ display(total(d.underReview), d.underReview) }}
            </dd>
          </div>
          <div class="rounded-control border border-sv-border bg-sv-surface-raised p-2">
            <dt class="break-words text-xs font-semibold text-sv-text-muted">
              Reopened
            </dt>
            <dd class="font-display text-xl font-extrabold text-sv-text-heading">
              {{ display(total(d.reopened), d.reopened) }}
            </dd>
          </div>
        </dl>
        <ul
          v-if="waitingReviews.length > 0"
          class="mt-4 flex flex-col gap-2"
        >
          <li
            v-for="flag in waitingReviews"
            :key="flag.id"
          >
            <RouterLink
              :to="{ name: 'audit.flag-detail', params: { flagUlid: flag.id } }"
              class="sv-focus-ring flex min-h-sv-touch items-center gap-3 rounded-control border border-sv-border bg-sv-surface-raised p-3 transition-colors duration-sv-fast hover:bg-sv-surface-subtle motion-reduce:transition-none"
            >
              <span class="sv-age inline-flex min-w-[3.5rem] flex-col items-center rounded-control px-2 py-1 text-center">
                <span class="font-display text-sm font-extrabold text-sv-text-heading">{{ ageLabel(flag.created_at, now) }}</span>
                <span class="text-[0.625rem] font-semibold uppercase text-sv-text-secondary">waiting</span>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-sv-text-heading">{{ flag.audit_event ? humanizeAction(flag.audit_event.action) : 'Flagged event' }}</span>
                <span class="mt-1 flex flex-wrap gap-1">
                  <AuditSeverityBadge
                    v-if="flag.audit_event"
                    :severity="flag.audit_event.severity"
                    size="sm"
                  />
                  <AuditReviewBadge :status="flag.status" />
                </span>
              </span>
              <SvIconChevronRight
                aria-hidden="true"
                class="h-4 w-4 shrink-0 text-sv-text-muted"
              />
            </RouterLink>
          </li>
        </ul>
        <p
          v-else-if="d.openFlags.state === 'ready' && d.reopened.state === 'ready'"
          class="mt-4 text-sm text-sv-text-secondary"
        >
          No flag is waiting for a reviewer.
        </p>
        <RouterLink
          :to="{ name: 'audit.audit-flags' }"
          class="sv-focus-ring mt-4 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
        >
          Open the full review queue
        </RouterLink>
      </section>
    </div>

    <section
      class="mt-5"
      aria-labelledby="slices-heading"
    >
      <h2
        id="slices-heading"
        class="mb-3 font-display text-lg font-bold text-sv-text-heading"
      >
        Audit slices
      </h2>
      <ul class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <li
          v-for="card in slices"
          :key="card.key"
        >
          <article
            class="sv-slice flex h-full flex-col rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            :data-tone="card.tone"
            :data-testid="`audit-dashboard-slice-${card.key}`"
          >
            <div class="flex items-center gap-3">
              <span
                aria-hidden="true"
                class="sv-slice__icon inline-flex h-10 w-10 items-center justify-center rounded-control"
              >
                <component
                  :is="card.icon"
                  class="h-5 w-5"
                />
              </span>
              <div class="min-w-0">
                <h3 class="font-display text-base font-bold text-sv-text-heading">
                  {{ card.title }}
                </h3>
                <p class="text-xs text-sv-text-muted">
                  {{ card.blurb }}
                </p>
              </div>
            </div>
            <p class="mt-4 font-display text-3xl font-extrabold text-sv-text-heading">
              {{ display(total(card.slice), card.slice) }}
            </p>
            <p class="text-xs text-sv-text-muted">
              {{ card.slice.state === 'ready' ? 'events recorded in your branch' : card.slice.state === 'unavailable' ? 'this view needs a permission your account does not hold' : card.slice.state === 'failed' ? 'could not be loaded' : 'loading' }}
            </p>
            <ul
              v-if="card.slice.state === 'ready' && card.slice.items.length > 0"
              class="mt-3 flex flex-col gap-2 border-t border-sv-border pt-3"
            >
              <li
                v-for="event in card.slice.items"
                :key="event.id"
                class="flex items-start justify-between gap-2 text-sm"
              >
                <span class="min-w-0 truncate text-sv-text">{{ humanizeAction(event.action) }}</span>
                <AuditSeverityBadge
                  :severity="event.severity"
                  size="sm"
                />
              </li>
            </ul>
            <RouterLink
              v-if="card.slice.state !== 'unavailable'"
              :to="{ name: card.route }"
              class="sv-focus-ring mt-auto inline-flex min-h-sv-touch items-center gap-1 pt-3 text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
            >
              Open {{ card.title }}<span class="sr-only"> audit view</span>
              <SvIconChevronRight
                aria-hidden="true"
                class="h-4 w-4"
              />
            </RouterLink>
          </article>
        </li>
      </ul>
    </section>

    <div class="mt-5 grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-3">
      <section
        class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card lg:col-span-2"
        aria-labelledby="recent-heading"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h2
            id="recent-heading"
            class="font-display text-lg font-bold text-sv-text-heading"
          >
            Recent branch activity
          </h2>
          <RouterLink
            :to="{ name: 'audit.audit' }"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
          >
            Open the log
          </RouterLink>
        </div>
        <ol
          v-if="d.recent.state === 'ready' && d.recent.items.length > 0"
          class="sv-timeline mt-3 flex flex-col"
          aria-label="Most recent audit events"
        >
          <li
            v-for="event in d.recent.items"
            :key="event.id"
            class="relative flex items-start gap-3 pb-4 pl-5"
          >
            <span
              aria-hidden="true"
              class="sv-timeline__dot absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full"
            />
            <span class="w-12 shrink-0 font-mono text-xs font-semibold text-sv-text-secondary">{{ nairobiParts(event.created_at)?.time ?? '—' }}</span>
            <span class="min-w-0 flex-1">
              <RouterLink
                :to="{ name: 'audit.audit-event-detail', params: { eventUlid: event.id }, query: { from: '/dashboard' } }"
                class="sv-focus-ring flex min-h-sv-touch max-w-full items-center font-semibold text-sv-text-heading underline-offset-4 hover:underline"
              ><span class="truncate">{{ humanizeAction(event.action) }}</span></RouterLink>
              <span class="text-xs text-sv-text-muted">{{ event.actor ?? 'System' }} · {{ nairobiParts(event.created_at)?.day ?? '' }}</span>
            </span>
            <AuditSeverityBadge
              :severity="event.severity"
              size="sm"
            />
          </li>
        </ol>
        <p
          v-else-if="d.recent.state === 'ready'"
          class="mt-3 text-sm text-sv-text-secondary"
        >
          No audit events have been recorded in your branch yet.
        </p>
        <SvSkeleton
          v-else-if="d.recent.state === 'loading'"
          class="mt-3"
          label="Loading recent activity"
          :lines="3"
        />
        <p
          v-else
          class="mt-3 text-sm text-sv-text-secondary"
        >
          Recent activity is not available.
        </p>
      </section>

      <section
        class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
        aria-labelledby="exports-heading"
        data-testid="audit-dashboard-exports"
      >
        <h2
          id="exports-heading"
          class="font-display text-lg font-bold text-sv-text-heading"
        >
          Audit exports
        </h2>
        <template v-if="d.exportsReady.state === 'unavailable'">
          <p class="mt-2 text-sm text-sv-text-secondary">
            Your account does not hold audit export.
          </p>
        </template>
        <template v-else>
          <dl class="mt-3 grid grid-cols-2 gap-2">
            <div class="rounded-control border border-sv-border p-3">
              <dt class="text-xs text-sv-text-muted">
                Ready to download
              </dt>
              <dd class="font-display text-2xl font-extrabold text-sv-text-heading">
                {{ display(total(d.exportsReady), d.exportsReady) }}
              </dd>
            </div>
            <div class="rounded-control border border-sv-border p-3">
              <dt class="text-xs text-sv-text-muted">
                In progress
              </dt>
              <dd class="font-display text-2xl font-extrabold text-sv-text-heading">
                {{ display(exportsInProgress, exportsInProgress === null ? d.exportsInFlight : { state: 'ready', total: exportsInProgress, items: [] }) }}
              </dd>
            </div>
          </dl>
          <p
            v-if="d.exportsReady.state === 'ready' && d.exportsReady.items[0]?.expires_at"
            class="mt-3 flex items-center gap-2 text-xs text-sv-text-secondary"
          >
            <SvIconClock
              aria-hidden="true"
              class="h-4 w-4 shrink-0"
            />
            Newest ready export expires {{ nairobiParts(d.exportsReady.items[0].expires_at)?.day }} at {{ nairobiParts(d.exportsReady.items[0].expires_at)?.time }}
          </p>
          <RouterLink
            :to="{ name: 'audit.exports' }"
            class="sv-focus-ring mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
          >
            Go to Audit Exports
          </RouterLink>
        </template>
      </section>
    </div>

    <AuditFlagDialog
      :event="flagging"
      @close="flagging = null"
      @flagged="() => { flagging = null; refresh(); }"
    />
  </section>
</template>

<style scoped>
.sv-queue {
  border-color: color-mix(in srgb, var(--sv-color-brand-primary) 35%, var(--sv-color-border-default));
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-primary) 14%, transparent), transparent 50%),
    var(--sv-color-surface-raised);
}
.sv-age {
  background: color-mix(in srgb, var(--sv-color-brand-primary) 14%, var(--sv-color-surface-raised));
}
.sv-clear {
  border-color: color-mix(in srgb, var(--sv-color-growth) 40%, var(--sv-color-border-default));
  background: color-mix(in srgb, var(--sv-color-growth) 8%, var(--sv-color-surface-raised));
}
.sv-slice {
  --sv-slice: var(--sv-color-brand-secondary);
  border-top: 4px solid color-mix(in srgb, var(--sv-slice) 70%, var(--sv-color-border-default));
}
.sv-slice[data-tone='warm'] { --sv-slice: var(--sv-color-brand-primary); }
.sv-slice[data-tone='green'] { --sv-slice: var(--sv-color-growth); }
.sv-slice[data-tone='sun'] { --sv-slice: var(--sv-color-accent); }
.sv-slice__icon {
  background: color-mix(in srgb, var(--sv-slice) 16%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
.sv-timeline li:not(:last-child)::before {
  content: '';
  position: absolute;
  left: 0.28rem;
  top: 1.1rem;
  bottom: 0;
  width: 2px;
  background: var(--sv-color-border-default);
}
.sv-timeline__dot {
  background: var(--sv-color-brand-secondary);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--sv-color-brand-secondary) 20%, transparent);
}
</style>
