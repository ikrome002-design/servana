<script setup lang="ts">
/**
 * SvTonalPageHeader — the light companion to `SvOperationalHero` (Phase UI-14).
 *
 * UI-13 introduced the dark operational hero for a role's command centre. Every other authenticated
 * page still needs the same hierarchy — eyebrow, one `<h1>`, a context line, a dominant action and
 * an optional row of facts — without competing with the dashboard. This header carries that on a
 * shaped, tonal surface (the Landing/Billings reference lesson) built only from Servana tokens.
 *
 * It owns the page's single `<h1>` and keeps the `sv-page-header` / `sv-page-title` test hooks of
 * `SvPageHeader`, so it is a drop-in replacement. It computes nothing: every fact rendered in the
 * default slot arrives already decided by the server.
 *
 * `tone` selects the accent wash only; it never carries meaning on its own.
 */
withDefaults(
  defineProps<{
    title: string;
    eyebrow?: string;
    description?: string;
    /** Short context pill beside the eyebrow, e.g. a branch or period. */
    context?: string;
    tone?: 'warm' | 'teal' | 'green';
  }>(),
  { eyebrow: undefined, description: undefined, context: undefined, tone: 'warm' },
);
</script>

<template>
  <header
    class="sv-tonal-header relative isolate mb-6 overflow-hidden rounded-card border border-sv-border px-5 py-6 shadow-card md:px-8 md:py-7"
    :data-tone="tone"
    data-testid="sv-page-header"
  >
    <span
      aria-hidden="true"
      class="sv-tonal-header__orb pointer-events-none absolute -right-10 -top-16 h-56 w-56 rounded-full"
    />
    <span
      aria-hidden="true"
      class="sv-tonal-header__ring pointer-events-none absolute -bottom-24 right-24 hidden h-48 w-48 rounded-full md:block"
    />
    <div class="relative flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
      <div class="min-w-0 max-w-3xl">
        <slot name="breadcrumbs" />
        <div
          v-if="eyebrow || context"
          class="flex flex-wrap items-center gap-2"
        >
          <span
            v-if="eyebrow"
            class="sv-tonal-header__eyebrow inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em]"
          >{{ eyebrow }}</span>
          <span
            v-if="context"
            class="rounded-full border border-sv-border bg-sv-surface-raised px-3 py-1 text-xs font-medium text-sv-text-secondary"
          >{{ context }}</span>
        </div>
        <h1
          class="mt-3 font-display text-2xl font-extrabold tracking-tight text-sv-text-heading md:text-3xl"
          data-testid="sv-page-title"
        >
          {{ title }}
        </h1>
        <p
          v-if="description"
          class="mt-2 max-w-2xl text-sm leading-6 text-sv-text-secondary md:text-base"
        >
          {{ description }}
        </p>
      </div>
      <div
        v-if="$slots.actions"
        class="flex flex-col gap-2 md:flex-row md:flex-wrap md:items-center lg:shrink-0"
        data-testid="sv-page-actions"
      >
        <slot name="actions" />
      </div>
    </div>
    <div
      v-if="$slots.default"
      class="relative mt-6"
    >
      <slot />
    </div>
  </header>
</template>

<style scoped>
.sv-tonal-header {
  --sv-tonal: var(--sv-color-brand-primary);
  background:
    radial-gradient(circle at 96% 0%, color-mix(in srgb, var(--sv-tonal) 20%, transparent), transparent 46%),
    radial-gradient(circle at 0% 100%, color-mix(in srgb, var(--sv-color-accent) 14%, transparent), transparent 40%),
    var(--sv-color-surface-raised);
}
.sv-tonal-header[data-tone='teal'] { --sv-tonal: var(--sv-color-brand-secondary); }
.sv-tonal-header[data-tone='green'] { --sv-tonal: var(--sv-color-growth); }
.sv-tonal-header__orb {
  background: color-mix(in srgb, var(--sv-tonal) 10%, transparent);
}
.sv-tonal-header__ring {
  border: 28px solid color-mix(in srgb, var(--sv-color-brand-secondary) 8%, transparent);
}
.sv-tonal-header__eyebrow {
  background: color-mix(in srgb, var(--sv-tonal) 14%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
</style>
