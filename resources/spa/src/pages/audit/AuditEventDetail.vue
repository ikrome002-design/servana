<script setup lang="ts">
/**
 * Audit Event Detail — Appendix A §12.4.4 (Phase 19 read; Phase UI-15 experience).
 *
 * Explains one immutable event: identity and time, actor and subject, its own severity, a
 * structured before/after comparison, the remaining recorded context, its review state, and how
 * its integrity is protected. Works as a direct deep link. Everything shown arrives masked from
 * the server; hash columns are deliberately not part of the payload. The only actions are opening
 * related evidence, copying the event's non-secret reference, and raising a review flag.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import AuditDiff from '@/components/audit/AuditDiff.vue';
import AuditFlagDialog from '@/components/audit/AuditFlagDialog.vue';
import AuditReviewBadge from '@/components/audit/AuditReviewBadge.vue';
import AuditSeverityBadge from '@/components/audit/AuditSeverityBadge.vue';
import SvBreadcrumbs from '@/components/ui/SvBreadcrumbs.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvEmptyState from '@/components/ui/SvEmptyState.vue';
import SvErrorState from '@/components/ui/SvErrorState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import { SvIconChainLink, SvIconFlag, SvIconLocked, SvIconSearch } from '@/design-system/icons';
import { safeReturnPath } from '@/composables/useAuditRouteFilters';
import { useAuditEventStore, type AuditEventView } from '@/stores/auditEventStore';
import type { FlaggedEventView } from '@/stores/flaggedEventStore';
import { usePermissionStore } from '@/stores/permissionStore';
import {
  actionModule,
  buildContextBreakdown,
  humanizeAction,
  humanizeSubject,
  isActiveReview,
  isMaskedValue,
  severityPresentation,
} from '@/utils/auditPresentation';

const route = useRoute();
const store = useAuditEventStore();
const permissions = usePermissionStore();

const id = computed(() => String(route.params.eventUlid));
const event = computed(() => store.current);
const breakdown = computed(() => buildContextBreakdown(event.value?.context));
const backPath = computed(() => safeReturnPath(route.query.from, '/audit'));
const backLabel = computed(() => (backPath.value.startsWith('/audit/') && backPath.value !== '/audit' ? 'Back to the view' : 'Branch Audit Log'));
const canFlag = computed(() => permissions.can('audit.flagged_event.create'));
const alarm = computed(() => severityPresentation(event.value?.severity).rank >= 3);

const flagging = ref<AuditEventView | null>(null);
const copyState = ref<'idle' | 'copied' | 'failed'>('idle');
const flaggedNotice = ref<FlaggedEventView | null>(null);

const crumbs = computed(() => [
  { label: 'Branch Audit Log', to: { name: 'audit.audit' } },
  { label: event.value ? humanizeAction(event.value.action) : 'Audit event' },
]);

function load(): void {
  copyState.value = 'idle';
  void store.fetchEvent(id.value);
}

async function copyReference(): Promise<void> {
  if (!event.value) return;
  try {
    await navigator.clipboard.writeText(event.value.id);
    copyState.value = 'copied';
  } catch {
    copyState.value = 'failed';
  }
}

function onFlagged(flag: FlaggedEventView): void {
  flagging.value = null;
  flaggedNotice.value = flag;
  load();
}

onMounted(load);
watch(id, load);
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="audit-event-detail"
  >
    <SvBreadcrumbs
      :items="crumbs"
      class="mb-3"
    />

    <div
      v-if="store.loading && !event"
      class="flex flex-col gap-4"
    >
      <SvSkeleton
        label="Loading the audit event"
        shape="block"
        class="h-40"
      />
      <SvSkeleton
        label="Loading the recorded change"
        :lines="4"
      />
    </div>

    <SvEmptyState
      v-else-if="store.currentError === 'not_found' || store.currentError === 'forbidden'"
      title="This event is not available"
      description="It does not exist in your assigned branch, or your account cannot read it. Audit events are only shown inside your branch scope."
      action-label="Open the Branch Audit Log"
      :icon="SvIconSearch"
      data-testid="audit-detail-unavailable"
      @action="$router.push({ name: 'audit.audit' })"
    />

    <SvErrorState
      v-else-if="store.currentError === 'failed'"
      title="We couldn’t load this audit event"
      message="The request did not complete. Reading it again is safe."
      retryable
      @retry="load"
    />

    <template v-else-if="event">
      <header
        class="sv-evidence-hero relative isolate mb-5 overflow-hidden rounded-card border border-sv-border p-5 shadow-raised md:p-7"
        :data-alarm="alarm"
      >
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">{{ actionModule(event.action) }}</span>
          <span class="inline-flex items-center gap-1 rounded-pill border border-sv-border bg-sv-surface-raised px-3 py-1 text-xs font-semibold text-sv-text-secondary">
            <SvIconLocked
              aria-hidden="true"
              class="h-3.5 w-3.5"
            />Immutable record
          </span>
        </div>
        <h1
          class="mt-3 break-words font-display text-2xl font-extrabold tracking-tight text-sv-text-heading md:text-3xl"
          data-testid="audit-detail-action"
        >
          {{ humanizeAction(event.action) }}
        </h1>
        <p class="mt-1 break-all font-mono text-sm text-sv-text-muted">
          <span class="sr-only">Action token: </span>{{ event.action }}
        </p>
        <div class="mt-4 flex flex-wrap items-center gap-3">
          <AuditSeverityBadge :severity="event.severity" />
          <AuditReviewBadge
            v-if="event.review"
            :status="event.review.status"
            :count="event.review.flag_count"
            size="md"
          />
          <span class="text-sm text-sv-text-secondary">
            Recorded <SvDateTime :value="event.created_at" />
          </span>
        </div>
        <div class="mt-5 flex flex-wrap gap-2">
          <RouterLink
            :to="backPath"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-sv-border bg-sv-surface-raised px-4 text-sm font-semibold text-sv-text"
          >
            {{ backLabel }}
          </RouterLink>
          <SvButton
            v-if="canFlag && !isActiveReview(event.review?.status)"
            data-testid="audit-detail-flag"
            @click="flagging = event"
          >
            <template #icon-leading>
              <SvIconFlag
                aria-hidden="true"
                class="h-4 w-4"
              />
            </template>
            Flag for review
          </SvButton>
          <RouterLink
            v-if="event.review"
            :to="{ name: 'audit.flag-detail', params: { flagUlid: event.review.flag } }"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
            data-testid="audit-detail-open-review"
          >
            Open the review
          </RouterLink>
        </div>
        <p
          v-if="flaggedNotice"
          class="mt-3 text-sm font-semibold text-sv-success-fg"
          role="status"
        >
          Flag created — the event is now in the review queue.
        </p>
      </header>

      <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="flex min-w-0 flex-col gap-5">
          <section
            class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            aria-labelledby="audit-change-heading"
          >
            <h2
              id="audit-change-heading"
              class="font-display text-lg font-bold text-sv-text-heading"
            >
              What changed
            </h2>
            <p class="mt-1 text-sm text-sv-text-secondary">
              Before and after values exactly as recorded, with protected values masked by the server.
            </p>
            <div class="mt-4">
              <AuditDiff
                v-if="breakdown.changes.length > 0"
                :rows="breakdown.changes"
              />
              <p
                v-else
                class="rounded-control border border-dashed border-sv-border p-4 text-sm text-sv-text-secondary"
                data-testid="audit-detail-no-diff"
              >
                This event records an action, not a field change, so there is no before/after to compare.
              </p>
            </div>
          </section>

          <section
            class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            aria-labelledby="audit-context-heading"
          >
            <h2
              id="audit-context-heading"
              class="font-display text-lg font-bold text-sv-text-heading"
            >
              Recorded context
            </h2>
            <p
              v-if="breakdown.facts.length === 0"
              class="mt-2 text-sm text-sv-text-secondary"
            >
              No further context was recorded with this event.
            </p>
            <dl
              v-else
              class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2"
              data-testid="audit-detail-context"
            >
              <div
                v-for="fact in breakdown.facts"
                :key="fact.key"
                class="min-w-0 rounded-control border border-sv-border bg-sv-surface-subtle p-3"
              >
                <dt class="text-xs font-semibold text-sv-text-muted">
                  {{ fact.label }}
                </dt>
                <dd class="mt-1 flex items-start gap-1 break-words text-sm font-medium text-sv-text">
                  <SvIconLocked
                    v-if="isMaskedValue(fact.value)"
                    aria-hidden="true"
                    class="mt-0.5 h-3.5 w-3.5 shrink-0 text-sv-text-muted"
                  /><span
                    v-if="isMaskedValue(fact.value)"
                    class="sr-only"
                  >Masked:</span><span class="min-w-0">{{ fact.value }}</span>
                </dd>
              </div>
            </dl>
          </section>
        </div>

        <aside class="flex flex-col gap-5">
          <section
            class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            aria-labelledby="audit-identity-heading"
          >
            <h2
              id="audit-identity-heading"
              class="font-display text-base font-bold text-sv-text-heading"
            >
              Event identity
            </h2>
            <dl class="mt-3 flex flex-col gap-3 text-sm">
              <div>
                <dt class="text-xs font-semibold text-sv-text-muted">
                  Audit reference
                </dt>
                <dd class="mt-0.5 break-all font-mono text-sv-text">
                  {{ event.id }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold text-sv-text-muted">
                  Actor
                </dt>
                <dd class="mt-0.5 flex items-center gap-1 text-sv-text">
                  <SvIconLocked
                    v-if="event.actor && isMaskedValue(event.actor)"
                    aria-hidden="true"
                    class="h-3.5 w-3.5 text-sv-text-muted"
                  />{{ event.actor ?? 'System (no signed-in actor)' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold text-sv-text-muted">
                  Subject
                </dt>
                <dd class="mt-0.5 text-sv-text">
                  {{ humanizeSubject(event.subject_type) ?? 'Not recorded' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold text-sv-text-muted">
                  Correlation reference
                </dt>
                <dd class="mt-0.5 break-all font-mono text-sv-text">
                  {{ event.correlation_id ?? 'Not recorded' }}
                </dd>
              </div>
            </dl>
            <div class="mt-4 flex flex-col gap-2">
              <SvButton
                variant="secondary"
                block
                data-testid="audit-detail-copy"
                @click="copyReference"
              >
                Copy audit reference
              </SvButton>
              <p
                class="min-h-5 text-xs text-sv-text-muted"
                role="status"
                aria-live="polite"
              >
                <template v-if="copyState === 'copied'">
                  Reference copied. It identifies the event and carries no protected value.
                </template>
                <template v-else-if="copyState === 'failed'">
                  Copying is blocked by this browser — select the reference above instead.
                </template>
              </p>
              <RouterLink
                v-if="event.correlation_id"
                :to="{ name: 'audit.audit', query: { correlation_id: event.correlation_id } }"
                class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-2 text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
                data-testid="audit-detail-related"
              >
                <SvIconSearch
                  aria-hidden="true"
                  class="h-4 w-4"
                />Find events from the same request
              </RouterLink>
            </div>
          </section>

          <section
            class="sv-integrity-note rounded-card border p-5"
            aria-labelledby="audit-integrity-heading"
          >
            <h2
              id="audit-integrity-heading"
              class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading"
            >
              <SvIconChainLink
                aria-hidden="true"
                class="h-4 w-4"
              />Integrity
            </h2>
            <p class="mt-2 text-sm text-sv-text-secondary">
              This record is chained to the one before it. Its hash is checked by the server and never displayed or editable here.
            </p>
            <RouterLink
              :to="{ name: 'audit.audit-integrity' }"
              class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
            >
              Check integrity status
            </RouterLink>
          </section>
        </aside>
      </div>
    </template>

    <AuditFlagDialog
      :event="flagging"
      @close="flagging = null"
      @flagged="onFlagged"
    />
  </section>
</template>

<style scoped>
.sv-evidence-hero {
  --sv-hero: var(--sv-color-brand-secondary);
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-hero) 18%, transparent), transparent 42%),
    var(--sv-color-surface-raised);
  border-top: 4px solid color-mix(in srgb, var(--sv-hero) 75%, var(--sv-color-border-default));
}
.sv-evidence-hero[data-alarm='true'] { --sv-hero: var(--sv-color-status-error-border); }
.sv-integrity-note {
  border-color: color-mix(in srgb, var(--sv-color-brand-secondary) 35%, var(--sv-color-border-default));
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 7%, var(--sv-color-surface-raised));
}
</style>
