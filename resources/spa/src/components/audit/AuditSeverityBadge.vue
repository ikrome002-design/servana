<script setup lang="ts">
/**
 * AuditSeverityBadge — the IMMUTABLE source event's severity (Phase UI-15; Appendix A §12.4.5).
 *
 * Severity is a fact about the recorded event and never changes. It is drawn as a squared tag with
 * a glyph so it cannot be mistaken for the rounded review-status pill (`AuditReviewBadge`), and
 * critical uses its own shield glyph so high and critical differ by more than colour.
 */
import { computed } from 'vue';
import { SvIconCritical, SvIconInfo, SvIconWarning } from '@/design-system/icons';
import { severityPresentation } from '@/utils/auditPresentation';

const props = withDefaults(defineProps<{ severity: string | null | undefined; size?: 'sm' | 'md' }>(), { size: 'md' });

const presentation = computed(() => severityPresentation(props.severity));
const glyph = computed(() => {
  if (props.severity === 'critical') return SvIconCritical;
  if (props.severity === 'high' || props.severity === 'warning') return SvIconWarning;
  return SvIconInfo;
});
</script>

<template>
  <span
    class="sv-severity inline-flex shrink-0 items-center gap-1 rounded-control border font-semibold"
    :class="size === 'sm' ? 'px-1.5 py-0.5 text-xs' : 'px-2 py-1 text-xs'"
    :data-severity="presentation.known ? severity : 'unknown'"
    :data-tone="presentation.tone"
    data-testid="audit-severity"
  >
    <component
      :is="glyph"
      aria-hidden="true"
      class="h-3.5 w-3.5 shrink-0"
    />
    <span class="sr-only">Event severity: </span>{{ presentation.label }}
  </span>
</template>

<style scoped>
.sv-severity {
  border-color: var(--sv-color-border-default);
  background: var(--sv-color-surface-subtle);
  color: var(--sv-color-text-secondary);
}
.sv-severity[data-tone='info'] {
  border-color: var(--sv-color-status-info-border);
  background: var(--sv-color-status-info-bg);
  color: var(--sv-color-status-info-fg);
}
.sv-severity[data-tone='warning'] {
  border-color: var(--sv-color-status-warning-border);
  background: var(--sv-color-status-warning-bg);
  color: var(--sv-color-status-warning-fg);
}
.sv-severity[data-tone='error'] {
  border-color: var(--sv-color-status-error-border);
  background: var(--sv-color-status-error-bg);
  color: var(--sv-color-status-error-fg);
}
/* Critical is the one solid tag in the Audit vocabulary: the strongest emphasis, still with text. */
.sv-severity[data-severity='critical'] {
  border-color: var(--sv-color-status-error-fg);
  box-shadow: inset 0 0 0 1px var(--sv-color-status-error-fg);
}
</style>
