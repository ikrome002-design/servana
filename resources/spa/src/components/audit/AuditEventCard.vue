<script setup lang="ts">
/**
 * AuditEventCard — one immutable audit event as an evidence record (Phase UI-15; §12.4.3).
 *
 * Time leads (when it happened is the first investigative question), then the readable action
 * beside its exact machine token, then two visibly different facts: the event's own severity and,
 * only if a flag exists, the review status. Every value arrives masked from the server; the card
 * renders text only and offers exactly two explicit actions — open the evidence, or raise a
 * review flag. There is no edit, delete or copy-raw control because none may exist.
 */
import { computed } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import AuditReviewBadge from '@/components/audit/AuditReviewBadge.vue';
import AuditSeverityBadge from '@/components/audit/AuditSeverityBadge.vue';
import { SvIconChevronRight, SvIconFlag, SvIconLocked } from '@/design-system/icons';
import type { AuditEventView } from '@/stores/auditEventStore';
import {
  actionModule,
  humanizeAction,
  humanizeSubject,
  isActiveReview,
  isMaskedValue,
  nairobiParts,
  severityPresentation,
  shortReference,
} from '@/utils/auditPresentation';

const props = withDefaults(defineProps<{
  event: AuditEventView;
  to: RouteLocationRaw;
  canFlag?: boolean;
  compact?: boolean;
}>(), { canFlag: false, compact: false });

const emit = defineEmits<{ flag: [event: AuditEventView] }>();

const when = computed(() => nairobiParts(props.event.created_at));
const tone = computed(() => {
  const rank = severityPresentation(props.event.severity).rank;
  if (rank >= 3) return 'alarm';
  if (rank === 2) return 'caution';
  return 'calm';
});
const subject = computed(() => humanizeSubject(props.event.subject_type));
const actorMasked = computed(() => props.event.actor !== null && isMaskedValue(props.event.actor));
const activeReview = computed(() => isActiveReview(props.event.review?.status));
</script>

<template>
  <article
    class="sv-event-card grid grid-cols-[auto_minmax(0,1fr)] gap-3 rounded-card border border-sv-border bg-sv-surface-raised shadow-card md:gap-4"
    :class="compact ? 'p-3' : 'p-4 md:p-5'"
    :data-tone="tone"
    data-testid="audit-event-row"
  >
    <div
      class="sv-event-card__lead flex flex-col items-center justify-center rounded-control px-2 py-2 text-center"
      :class="compact ? 'min-w-[3.75rem]' : 'min-w-[4.25rem] md:min-w-[5rem]'"
    >
      <template v-if="when">
        <span class="font-display text-lg font-extrabold leading-none text-sv-text-heading">{{ when.time }}</span>
        <span class="mt-1 text-[0.6875rem] font-semibold uppercase tracking-wide text-sv-text-secondary">{{ when.day }}</span>
      </template>
      <span
        v-else
        class="text-xs font-semibold text-sv-text-secondary"
      >Time not recorded</span>
    </div>

    <div class="min-w-0">
      <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.14em] text-sv-text-muted">
        {{ actionModule(event.action) }}
      </p>
      <h3
        class="mt-0.5 break-words font-display font-bold text-sv-text-heading"
        :class="compact ? 'text-sm' : 'text-base md:text-lg'"
      >
        {{ humanizeAction(event.action) }}
      </h3>
      <p class="mt-0.5 break-all font-mono text-xs text-sv-text-muted">
        <span class="sr-only">Action token: </span>{{ event.action }}
      </p>

      <div class="mt-2 flex flex-wrap items-center gap-2">
        <AuditSeverityBadge
          :severity="event.severity"
          size="sm"
        />
        <AuditReviewBadge
          v-if="event.review"
          :status="event.review.status"
          :count="event.review.flag_count"
        />
      </div>

      <dl
        v-if="!compact"
        class="mt-3 grid grid-cols-1 gap-x-5 gap-y-1 text-xs md:grid-cols-3"
      >
        <div class="min-w-0">
          <dt class="text-sv-text-muted">
            Actor
          </dt>
          <dd class="flex min-w-0 items-center gap-1 font-medium text-sv-text">
            <SvIconLocked
              v-if="actorMasked"
              aria-hidden="true"
              class="h-3.5 w-3.5 shrink-0 text-sv-text-muted"
            />
            <span
              v-if="actorMasked"
              class="sr-only"
            >Masked:</span>
            <span class="truncate">{{ event.actor ?? 'System' }}</span>
          </dd>
        </div>
        <div class="min-w-0">
          <dt class="text-sv-text-muted">
            Subject
          </dt>
          <dd class="truncate font-medium text-sv-text">
            {{ subject ?? 'Not recorded' }}
          </dd>
        </div>
        <div class="min-w-0">
          <dt class="text-sv-text-muted">
            Reference
          </dt>
          <dd class="font-mono font-medium text-sv-text">
            {{ shortReference(event.id) }}
          </dd>
        </div>
      </dl>

      <div class="mt-3 flex flex-wrap items-center gap-2">
        <RouterLink
          :to="to"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-1 rounded-control px-3 text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
          data-testid="audit-event-open"
        >
          Open evidence<span class="sr-only">: {{ humanizeAction(event.action) }}</span>
          <SvIconChevronRight
            aria-hidden="true"
            class="h-4 w-4"
          />
        </RouterLink>
        <button
          v-if="canFlag && !activeReview"
          type="button"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-1 rounded-control border border-sv-border px-3 text-sm font-semibold text-sv-text transition-colors duration-sv-fast hover:bg-sv-surface-subtle motion-reduce:transition-none"
          data-testid="audit-event-flag"
          @click="emit('flag', event)"
        >
          <SvIconFlag
            aria-hidden="true"
            class="h-4 w-4"
          />Flag for review<span class="sr-only">: {{ humanizeAction(event.action) }}</span>
        </button>
      </div>
    </div>
  </article>
</template>

<style scoped>
.sv-event-card {
  --sv-event: var(--sv-color-brand-secondary);
  border-left: 4px solid color-mix(in srgb, var(--sv-event) 72%, var(--sv-color-border-default));
  transition: box-shadow var(--sv-motion-duration-fast) var(--sv-motion-ease-standard);
}
.sv-event-card:hover { box-shadow: var(--sv-shadow-raised); }
.sv-event-card[data-tone='caution'] { --sv-event: var(--sv-color-accent); }
.sv-event-card[data-tone='alarm'] { --sv-event: var(--sv-color-status-error-border); }
.sv-event-card__lead {
  background: color-mix(in srgb, var(--sv-event) 12%, var(--sv-color-surface-raised));
}
@media (prefers-reduced-motion: reduce) {
  .sv-event-card { transition: none; }
}
</style>
