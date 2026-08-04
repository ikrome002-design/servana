<script setup lang="ts">
/**
 * LandingBlocks — renders the classified blocks of one compiled landing section (Phase UI-06).
 *
 * The wording is the approved source's, verbatim. This component only decides SHAPE: a paragraph
 * is a paragraph, a run of short unpunctuated lines is a list, a bold term with a sentence under it
 * is a description pair, and a content label keeps its label.
 *
 * Inline markdown goes through the repository's audited `renderMarkdown`, which escapes every HTML
 * character before applying emphasis and constrains link schemes to http(s), mailto, root-relative
 * and fragment. No caller-supplied HTML reaches a node here, and the compiled content itself was
 * already checked for raw HTML at generation time (`content:check` reports zero findings).
 */
import { renderMarkdown } from '@/content/markdown';
import type { LandingBlock } from '@/content/landing/landingSection';

defineProps<{
  blocks: readonly LandingBlock[];
  /** Heading level for a content label, so the page outline stays ordered. */
  labelLevel?: 'h3' | 'h4';
}>();
</script>

<template>
  <div class="space-y-4">
    <template
      v-for="(block, index) in blocks"
      :key="index"
    >
      <!--
        APPROVED, version-controlled landing copy rendered through `renderMarkdown`, which escapes
        every HTML character before applying inline formatting and constrains link schemes to
        http(s), mailto, root-relative and fragment. No caller-supplied HTML reaches this node.

        `vue/no-v-html` reports on the DIRECTIVE's own line, so the suppression has to sit directly
        above THAT line — a comment above the element's opening tag silently misses, and a rule that
        is not actually suppressed is a warning nobody reads. The directive therefore gets its own
        single-attribute element, which is the same shape the list branch below already uses.
      -->
      <div
        v-if="block.kind === 'paragraph'"
        class="prose-landing text-sv-text-secondary"
      >
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div v-html="renderMarkdown(block.markdown)" />
      </div>

      <ul
        v-else-if="block.kind === 'list'"
        class="space-y-2"
      >
        <li
          v-for="item in block.items"
          :key="item"
          class="flex gap-2 text-sv-text-secondary"
        >
          <span
            aria-hidden="true"
            class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-sv-brand"
          />
          <!-- eslint-disable-next-line vue/no-v-html -- see the note above. -->
          <span v-html="renderMarkdown(item).replace(/^<p>|<\/p>$/g, '')" />
        </li>
      </ul>

      <dl
        v-else-if="block.kind === 'definitions'"
        class="grid gap-4 md:grid-cols-2"
      >
        <div
          v-for="entry in block.entries"
          :key="entry.term"
          class="rounded-card border border-sv-border bg-sv-surface-raised p-4"
        >
          <dt class="font-semibold text-sv-text-heading">
            {{ entry.term }}
          </dt>
          <dd class="mt-1 text-sm text-sv-text-secondary">
            {{ entry.description }}
          </dd>
        </div>
      </dl>

      <div v-else-if="block.kind === 'labelled'">
        <component
          :is="labelLevel ?? 'h3'"
          class="font-display text-base font-bold text-sv-text-heading"
        >
          {{ block.label }}
        </component>
        <div class="mt-3">
          <LandingBlocks
            :blocks="block.blocks"
            :label-level="labelLevel === 'h3' ? 'h4' : 'h4'"
          />
        </div>
      </div>
    </template>
  </div>
</template>
