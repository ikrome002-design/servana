<script setup lang="ts">
/**
 * SvFaq — accessible FAQ disclosure (Phase UI-04; UI/UX plan §8.3).
 *
 * STRUCTURE ONLY. UI-04 owns the disclosure pattern and its keyboard behaviour; the questions and
 * answers are passed in from the approved `docs/support/faq/**` content and are never invented,
 * paraphrased or reordered here. Final role-aware FAQ routes are UI-05/UI-06.
 *
 * Built on native `<details>`/`<summary>`, which the browser already makes keyboard operable and
 * already exposes an expanded/collapsed state for. Reimplementing that with ARIA would be more
 * code and strictly worse — it is the "use semantic native elements before reimplementing them"
 * rule in practice.
 *
 * The `+ / −` affordance is a Heroicon, `aria-hidden`, because `<summary>` already announces the
 * state. Each item carries a deterministic id so an FAQ can be deep-linked.
 */
import { computed } from 'vue';
import { renderMarkdown, slugify, type FaqItem } from '@/content/markdown';
import { SvIconChevronDown } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    items: FaqItem[];
    /** Accessible name for the list region. */
    label?: string;
    /** Open the first item. Off by default: a collapsed list is scannable. */
    openFirst?: boolean;
  }>(),
  { label: 'Frequently asked questions', openFirst: false },
);

/** Answers are rendered once, through the audited escaping renderer. */
const rendered = computed(() =>
  props.items.map((item) => ({
    id: item.id !== '' ? item.id : slugify(item.question),
    question: item.question,
    answerHtml: renderMarkdown(item.answer),
  })),
);
</script>

<template>
  <section
    :aria-label="label"
    class="space-y-2"
    data-testid="sv-faq"
  >
    <details
      v-for="(item, index) in rendered"
      :id="item.id"
      :key="item.id"
      class="group rounded-card border border-sv-border bg-sv-surface-raised"
      :open="openFirst && index === 0"
    >
      <summary
        class="sv-focus-ring flex min-h-sv-touch cursor-pointer list-none items-center justify-between gap-3 rounded-card px-4 py-3 text-sm font-medium text-sv-text"
      >
        <span>{{ item.question }}</span>
        <!-- Decorative: <summary> already exposes the expanded state to assistive technology. -->
        <SvIconChevronDown
          aria-hidden="true"
          class="h-5 w-5 shrink-0 text-sv-text-muted transition-transform duration-sv-fast group-open:rotate-180 motion-reduce:transition-none"
        />
      </summary>

      <!--
        eslint-disable-next-line vue/no-v-html -- APPROVED, version-controlled FAQ content passed
        through `renderMarkdown`, which escapes all HTML and constrains link schemes.
      -->
      <div
        class="prose-faq border-t border-sv-border px-4 py-3 text-sm text-sv-text-muted"
        v-html="item.answerHtml"
      />
    </details>
  </section>
</template>
