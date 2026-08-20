<script setup lang="ts">
/**
 * A reusable operational page hero for role command centres. It carries context,
 * observation time and one dominant action without computing business truth.
 */
defineProps<{
  eyebrow: string;
  title: string;
  description: string;
  context?: string;
  observedAt?: string;
}>();
</script>

<template>
  <header class="sv-operational-hero relative isolate overflow-hidden rounded-card border border-white/10 px-5 py-6 text-white shadow-raised md:px-7 md:py-8">
    <div
      aria-hidden="true"
      class="absolute -right-16 -top-20 h-64 w-64 rounded-full border-[32px] border-white/5"
    />
    <div class="relative grid gap-6 lg:grid-cols-[1fr_auto] lg:items-end">
      <div class="max-w-3xl">
        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-white/70">
          <span>{{ eyebrow }}</span>
          <span
            v-if="context"
            class="rounded-full bg-white/10 px-3 py-1 normal-case tracking-normal text-white/90"
          >{{ context }}</span>
        </div>
        <h1 class="mt-3 font-display text-3xl font-extrabold tracking-tight md:text-4xl">
          {{ title }}
        </h1>
        <p class="mt-3 max-w-2xl text-sm leading-6 text-white/75 md:text-base">
          {{ description }}
        </p>
        <p
          v-if="observedAt"
          class="mt-4 text-xs text-white/60"
        >
          Observed {{ observedAt }}
        </p>
      </div>
      <div
        v-if="$slots.actions"
        class="relative flex flex-wrap gap-2"
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
.sv-operational-hero {
  background:
    radial-gradient(circle at 88% 8%, color-mix(in srgb, var(--sv-color-brand-primary) 38%, transparent), transparent 34%),
    linear-gradient(135deg, var(--sv-color-text-on-brand), color-mix(in srgb, var(--sv-color-text-on-brand) 72%, var(--sv-color-brand-secondary)));
}
</style>
