<script setup lang="ts">
/**
 * SvTimeline — an ordered sequence of events (Phase UI-04; UI/UX plan §10).
 *
 * An ordered list, because the sequence IS the information and `<ol>` conveys that to assistive
 * technology without any ARIA.
 *
 * It renders the order the caller supplied and implies nothing further. No event is described as
 * having CAUSED another, and no gap is filled in: a timeline that invents causality from adjacency
 * would misrepresent an audit trail.
 *
 * Timestamps go through `SvDateTime`, so they are formatted in `Africa/Nairobi` rather than the
 * browser's timezone, and status is carried by a labelled `SvStatusBadge` — never by the colour
 * of the marker alone.
 */
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';

export interface SvTimelineEvent {
  id: string;
  title: string;
  /** ISO-8601 timestamp, or null when the record carries none. */
  at: string | null;
  description?: string;
  /** Status label. Always rendered as text when present. */
  statusLabel?: string;
  statusTone?: SvStatusTone;
}

withDefaults(
  defineProps<{
    events: SvTimelineEvent[];
    /** Accessible name for the sequence. */
    label?: string;
  }>(),
  { label: 'Timeline' },
);
</script>

<template>
  <ol
    :aria-label="label"
    class="flex flex-col gap-4"
    data-testid="sv-timeline"
  >
    <li
      v-for="event in events"
      :key="event.id"
      class="flex gap-3"
      :data-testid="`sv-timeline-${event.id}`"
    >
      <!-- Decorative rail. It marks position, never meaning: the status text carries that. -->
      <div
        aria-hidden="true"
        class="flex flex-col items-center"
      >
        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-pill bg-sv-brand" />
        <span class="mt-1 w-px flex-1 bg-sv-border" />
      </div>

      <div class="min-w-0 flex-1 pb-2">
        <div class="flex flex-wrap items-center gap-2">
          <p class="font-medium text-sv-text">
            {{ event.title }}
          </p>
          <SvStatusBadge
            v-if="event.statusLabel"
            :label="event.statusLabel"
            :tone="event.statusTone ?? 'neutral'"
            size="sm"
          />
        </div>

        <p class="mt-0.5 text-xs text-sv-text-muted">
          <SvDateTime :value="event.at" />
        </p>

        <p
          v-if="event.description"
          class="mt-1 text-sm text-sv-text-secondary"
        >
          {{ event.description }}
        </p>
      </div>
    </li>
  </ol>
</template>
