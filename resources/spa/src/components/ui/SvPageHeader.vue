<script setup lang="ts">
/**
 * SvPageHeader — the single page-title region (Phase UI-04; UI/UX plan §7, §13).
 *
 * One page, one `<h1>`. This component owns it, so a screen must not render another. The shell's
 * header shows the ROUTE label as chrome, not as a page heading — keeping the two separate is
 * what prevents the duplicate-heading defect.
 *
 * `headingLevel` exists because a page header can legitimately appear inside a dialog or a panel,
 * where `h1` would break the document outline. It defaults to `h1` for the page case.
 *
 * Phase UI-16 (UI16-VIS-001): the PAGE case now renders the shaped tonal surface UI-14 introduced
 * (`SvTonalPageHeader`), so the 47 UI-08…UI-12 pages that call this component share the same
 * eyebrow → title → context hierarchy as the UI-13…UI-15 pages instead of a bare title on the
 * page background. Same props, same slots, same `sv-page-header` / `sv-page-title` /
 * `sv-page-actions` hooks; the account accent comes from the shell. An in-panel header (`h2`/`h3`)
 * keeps the plain layout, because a tonal band nested inside a card would compete with the page's.
 *
 * Layout: title and actions sit side by side from tablet up; on mobile the actions stack full
 * width below the title, so the primary action stays reachable with one thumb (UI/UX plan §13.3)
 * rather than being squeezed onto the title row.
 */
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';

withDefaults(
  defineProps<{
    title: string;
    /** Small label above the title, e.g. the section. Never a substitute for the title. */
    eyebrow?: string;
    description?: string;
    /** `h1` for a page; `h2` inside a dialog or panel so the outline stays coherent. */
    headingLevel?: 'h1' | 'h2' | 'h3';
  }>(),
  { eyebrow: undefined, description: undefined, headingLevel: 'h1' },
);
</script>

<template>
  <SvTonalPageHeader
    v-if="headingLevel === 'h1'"
    :title="title"
    :eyebrow="eyebrow"
    :description="description"
  >
    <template
      v-if="$slots.breadcrumbs"
      #breadcrumbs
    >
      <slot name="breadcrumbs" />
    </template>
    <template
      v-if="$slots.description"
      #description
    >
      <slot name="description" />
    </template>
    <template
      v-if="$slots.actions"
      #actions
    >
      <slot name="actions" />
    </template>
  </SvTonalPageHeader>

  <header
    v-else
    class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between"
    data-testid="sv-page-header"
  >
    <div class="min-w-0">
      <slot name="breadcrumbs" />

      <p
        v-if="eyebrow"
        class="mt-1 text-xs font-medium uppercase tracking-wide text-sv-text-muted"
      >
        {{ eyebrow }}
      </p>

      <component
        :is="headingLevel"
        class="mt-1 font-display text-2xl font-extrabold text-sv-text-heading"
        data-testid="sv-page-title"
      >
        {{ title }}
      </component>

      <p
        v-if="description || $slots.description"
        class="mt-1 max-w-sv-readable text-sm text-sv-text-muted"
      >
        <slot name="description">
          {{ description }}
        </slot>
      </p>
    </div>

    <div
      v-if="$slots.actions"
      class="flex flex-col gap-2 md:flex-row md:shrink-0 md:items-center"
      data-testid="sv-page-actions"
    >
      <slot name="actions" />
    </div>
  </header>
</template>
