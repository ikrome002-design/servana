<script setup lang="ts">
/**
 * SvMaskedIdentity — a person shown with deliberately masked contact (Phase UI-14).
 *
 * Privacy is a visible design decision, not an absence: the masked number carries a lock glyph and
 * an assistive-technology prefix so every user understands the value is intentionally partial.
 *
 * The component only ever receives the server's already-masked string. It has no prop for a full
 * number, no copy control and no link (`tel:`/`sms:`), so it cannot become a contact-export path.
 */
import { computed } from 'vue';
import { SvIconLocked } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    name: string | null | undefined;
    phoneMasked?: string | null;
    size?: 'sm' | 'md';
  }>(),
  { phoneMasked: null, size: 'md' },
);

const initials = computed(() => {
  const parts = (props.name ?? '').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '·';
  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
});
</script>

<template>
  <div
    class="flex min-w-0 items-center gap-3"
    data-testid="sv-masked-identity"
  >
    <span
      aria-hidden="true"
      class="sv-masked-identity__avatar inline-flex shrink-0 items-center justify-center rounded-full font-display font-bold"
      :class="size === 'sm' ? 'h-9 w-9 text-xs' : 'h-11 w-11 text-sm'"
    >{{ initials }}</span>
    <span class="min-w-0">
      <span
        class="block truncate font-semibold text-sv-text-heading"
        :class="size === 'sm' ? 'text-sm' : 'text-base'"
      >{{ name ?? 'Client' }}</span>
      <span
        v-if="phoneMasked"
        class="mt-0.5 inline-flex items-center gap-1 text-xs text-sv-text-muted"
      >
        <SvIconLocked
          aria-hidden="true"
          class="h-3.5 w-3.5 shrink-0"
        />
        <span class="sr-only">Masked contact: </span>{{ phoneMasked }}
      </span>
    </span>
  </div>
</template>

<style scoped>
.sv-masked-identity__avatar {
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 16%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--sv-color-brand-secondary) 30%, transparent);
}
</style>
