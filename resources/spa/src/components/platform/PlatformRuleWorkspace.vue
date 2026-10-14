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
 *
 * COR-UI08-UI12-VIS-001: the rail became a layered guidance card (tone wash, icon emblem, the rule
 * set as the card's body) with an optional `rail` slot beneath it for a page's own context — a
 * scope legend, an effective-date reminder — so each rule page can carry its own side story.
 * Only Super Administrator pages render this component.
 */
import { SvIconInfo } from '@/design-system/icons';

withDefaults(defineProps<{
  /** Heading of the guidance rail. */
  ruleTitle?: string;
}>(), { ruleTitle: 'How this rule applies' });
</script>

<template>
  <div class="sv-r-grid sv-r-split">
    <div class="sv-r-stack min-w-0">
      <slot />
    </div>
    <aside
      class="sv-r-stack-sm sv-r-sticky"
      :aria-label="ruleTitle"
    >
      <div class="sv-r-note sv-r-tone-teal">
        <div class="flex items-center gap-3">
          <span
            aria-hidden="true"
            class="sv-r-icon sv-r-tone-teal"
          >
            <SvIconInfo />
          </span>
          <p class="sv-r-note__title">
            {{ ruleTitle }}
          </p>
        </div>
        <div class="sv-r-note__body mt-3">
          <slot name="rule" />
        </div>
      </div>
      <slot name="rail" />
    </aside>
  </div>
</template>
