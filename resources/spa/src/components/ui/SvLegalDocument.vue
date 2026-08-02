<script setup lang="ts">
/**
 * SvLegalDocument — structural shell for an approved legal document (Phase UI-04).
 *
 * STRUCTURE ONLY. UI-04 owns the container, the heading semantics, the readable measure and the
 * safe-rendering contract. It owns NO content: the text is passed in, sourced verbatim from
 * `docs/legal/**` by the existing content layer, and is never paraphrased, summarised or edited.
 * Wiring the final role-aware content routes is UI-05/UI-06.
 *
 * Safety: markdown is rendered by the repository's own `renderMarkdown`, which escapes all HTML
 * before applying inline formatting and (as of this phase) rejects non-http(s)/mailto link
 * schemes. The component therefore never accepts arbitrary caller HTML — it accepts markdown and
 * renders it through the audited path.
 *
 * The visible `<h1>` is the document title, so the page has a real heading rather than a
 * screen-reader-only one; `article` gives the document its own boundary in the accessibility tree.
 */
import { computed } from 'vue';
import { renderMarkdown } from '@/content/markdown';

const props = defineProps<{
  /** Document title, e.g. "Terms of Service". Supplied by the content layer. */
  title: string;
  /** The approved document, verbatim markdown. Never edited here. */
  markdown: string;
  /** Optional effective/last-updated line, when the source document carries one. */
  meta?: string;
}>();

const html = computed(() => renderMarkdown(props.markdown));
</script>

<template>
  <article
    class="mx-auto max-w-sv-readable"
    data-testid="sv-legal-document"
  >
    <header class="mb-6">
      <h1 class="font-display text-2xl font-extrabold text-sv-text-heading">
        {{ title }}
      </h1>
      <p
        v-if="meta"
        class="mt-1 text-sm text-sv-text-muted"
      >
        {{ meta }}
      </p>
    </header>

    <!--
      eslint-disable-next-line vue/no-v-html -- The input is APPROVED, version-controlled legal
      text rendered through `renderMarkdown`, which escapes every HTML character before applying
      inline formatting and constrains link schemes. No caller-supplied HTML reaches this node.
    -->
    <div
      class="prose-legal"
      v-html="html"
    />
  </article>
</template>
