<script setup lang="ts">
import { renderMarkdown, type FaqItem } from '@/content/markdown';

/**
 * Accessible FAQ disclosure (Plan §27.2; Scope §3.3). Built on native
 * <details>/<summary>: keyboard operable, expanded/collapsed state exposed to
 * assistive technology, and content is directly readable without pointer-only
 * interaction. Answers are approved verbatim markdown rendered to safe HTML.
 */
defineProps<{ items: FaqItem[] }>();
</script>

<template>
  <div class="space-y-2">
    <details
      v-for="item in items"
      :key="item.id"
      class="group rounded-card border border-border bg-surface"
    >
      <summary
        class="flex min-h-[44px] cursor-pointer list-none items-center justify-between gap-3 rounded-card px-4 py-3 text-sm font-medium text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        <span>{{ item.question }}</span>
        <span
          aria-hidden="true"
          class="text-text-muted transition-transform group-open:rotate-45 motion-reduce:transition-none"
        >+</span>
      </summary>
      <!-- eslint-disable-next-line vue/no-v-html -- trusted, version-controlled FAQ content -->
      <div
        class="prose-faq border-t border-border px-4 py-3 text-sm text-text-muted"
        v-html="renderMarkdown(item.answer)"
      />
    </details>
  </div>
</template>
