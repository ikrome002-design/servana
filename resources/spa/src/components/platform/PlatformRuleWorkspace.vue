<script setup lang="ts">
/**
 * PlatformRuleWorkspace — the Super Administrator's commercial-rule page body (Phase UI-16).
 *
 * UI16-VIS-016: every effective-dated rule page (billing settings, plans, prices, promotions,
 * preferred-personnel fees) put its binding behaviour — "a change never rewrites an issued
 * invoice", "at most one discount applies" — in a small grey footnote BELOW the forms, where it was
 * read last if at all. This layout keeps the working sections dominant and moves that existing
 * statement into a labelled guidance rail beside them (the Dashboard reference's narrow context
 * column), sticky on desktop and stacked after the content below it. It adds no copy of its own:
 * the rule text is the page's, passed in the `rule` slot, with its original test hook.
 */
import { SvIconInfo } from '@/design-system/icons';

withDefaults(defineProps<{
  /** Heading of the guidance rail. */
  ruleTitle?: string;
}>(), { ruleTitle: 'How this rule applies' });
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_17rem] lg:items-start">
    <div class="min-w-0">
      <slot />
    </div>
    <aside
      class="sv-rule-rail rounded-card border border-sv-border p-5 shadow-card lg:sticky lg:top-4"
      :aria-label="ruleTitle"
    >
      <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-sv-text-muted">
        <SvIconInfo
          aria-hidden="true"
          class="h-4 w-4 shrink-0"
        />{{ ruleTitle }}
      </p>
      <div class="mt-3 text-sm leading-6 text-sv-text-secondary">
        <slot name="rule" />
      </div>
    </aside>
  </div>
</template>

<style scoped>
.sv-rule-rail {
  background:
    linear-gradient(170deg, color-mix(in srgb, var(--sv-color-brand-secondary) 10%, var(--sv-color-surface-raised)), var(--sv-color-surface-raised) 70%);
}
</style>
