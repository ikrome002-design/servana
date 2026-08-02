<script setup lang="ts">
/**
 * SvIconButton — an icon-only control (Phase UI-04; UI/UX plan §9.4, §9.5, §14.1).
 *
 * The whole reason this component exists is that `label` is REQUIRED. An icon-only button with no
 * accessible name is invisible to a screen reader, and UI-01 found exactly that pattern in the
 * shell. Making the name a required prop moves it from "remember to add aria-label" to "will not
 * compile without one".
 *
 * The icon itself is `aria-hidden` — the name lives on the control, never on the glyph, so the
 * name is announced once rather than twice.
 *
 * Target size is the 44×44 minimum from UI/UX plan §9.5 in every variant; a smaller icon-only
 * control is not offered, because "just this one is small" is how the rule erodes.
 */
import type { Component } from 'vue';
import { SV_ICON_SIZE, type SvIconSize } from '@/design-system/icons';

withDefaults(
  defineProps<{
    /** The Heroicons component to render. Imported individually by the caller (tree-shaking). */
    icon: Component;
    /** REQUIRED accessible name describing the ACTION, e.g. "Close dialog", not "X". */
    label: string;
    variant?: 'ghost' | 'subtle' | 'danger';
    size?: SvIconSize;
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    loading?: boolean;
    /** Reflected as `aria-expanded` for disclosure triggers. Omit for plain actions. */
    expanded?: boolean;
    /** Reflected as `aria-controls` for disclosure triggers. */
    controls?: string;
    /** Reflected as `aria-pressed` for toggle buttons. */
    pressed?: boolean;
  }>(),
  {
    variant: 'ghost',
    size: 'lg',
    type: 'button',
    disabled: false,
    loading: false,
    expanded: undefined,
    controls: undefined,
    pressed: undefined,
  },
);

defineEmits<{ click: [event: MouseEvent] }>();
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-label="label"
    :aria-disabled="disabled || loading"
    :aria-busy="loading || undefined"
    :aria-expanded="expanded"
    :aria-controls="controls"
    :aria-pressed="pressed"
    :title="label"
    class="sv-focus-ring inline-flex min-h-sv-touch min-w-sv-touch items-center justify-center rounded-control transition-colors duration-sv-fast ease-sv-standard disabled:pointer-events-none disabled:text-sv-disabled-fg"
    :class="{
      'text-sv-text hover:bg-sv-surface-subtle': variant === 'ghost',
      'bg-sv-surface-subtle text-sv-text hover:bg-sv-selected-bg': variant === 'subtle',
      'text-sv-error-fg hover:bg-sv-error-bg': variant === 'danger',
    }"
    @click="$emit('click', $event)"
  >
    <span
      v-if="loading"
      aria-hidden="true"
      class="h-5 w-5 animate-spin rounded-pill border-2 border-current border-t-transparent"
    />
    <component
      :is="icon"
      v-else
      aria-hidden="true"
      :class="SV_ICON_SIZE[size]"
    />
  </button>
</template>
