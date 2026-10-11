<script setup lang="ts">
/**
 * AuditReviewBadge — the MUTABLE review status of a flag (Phase UI-15; Appendix A §12.4.5).
 *
 * Review status belongs to the Audit team's review metadata, not to the event. It is always a
 * rounded pill with a flag glyph and an explicit "Review:" prefix for assistive technology, so it
 * can sit beside `AuditSeverityBadge` without either being read as the other.
 */
import { computed } from 'vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { SvIconFlag } from '@/design-system/icons';
import { reviewPresentation } from '@/utils/auditPresentation';

const props = withDefaults(defineProps<{ status: string | null | undefined; count?: number; size?: 'sm' | 'md' }>(), {
  count: 1,
  size: 'sm',
});

const presentation = computed(() => reviewPresentation(props.status));
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center gap-1"
    data-testid="audit-review-badge"
    :data-review="presentation.known ? status : 'unknown'"
  >
    <SvIconFlag
      aria-hidden="true"
      class="h-3.5 w-3.5 shrink-0 text-sv-text-muted"
    />
    <SvStatusBadge
      :label="count > 1 ? `${presentation.label} · ${count} flags` : presentation.label"
      :tone="presentation.tone"
      :size="size"
      sr-prefix="Review:"
    />
  </span>
</template>
