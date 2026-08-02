<script setup lang="ts">
/**
 * SvButton — the canonical action control (Phase UI-04; UI/UX plan §9.5, §14.1).
 *
 * This is a BUTTON: it performs an action. Navigation is `SvLink`, which renders an anchor. They
 * are deliberately separate components rather than one `as` prop, because "a link that acts like
 * a button" and "a button that navigates" are the two ways this distinction gets lost, and both
 * break middle-click, copy-link and screen-reader expectations.
 *
 * Contract:
 *  - variants and sizes are a closed typed vocabulary, all built from semantic tokens;
 *  - loading BLOCKS activation (the element is genuinely disabled), so a double-click cannot
 *    submit twice — a visual-only spinner would not prevent the second submit;
 *  - `aria-busy` announces the loading state; the spinner is `aria-hidden` so nothing is
 *    announced twice;
 *  - leading/trailing icon slots, decorative — the label carries the meaning;
 *  - 44×44 minimum target on every size (UI/UX plan §9.5);
 *  - visible focus in both themes via the shared `.sv-focus-ring`;
 *  - motion honours the global reduced-motion rule in `style.css`.
 *
 * Phase UI-04 replaced this component's hard-coded `bg-red-700` / `hover:bg-orange-400` classes
 * with semantic tokens; those were the last raw palette references in a shared control.
 */
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'destructive';
    size?: 'sm' | 'md' | 'lg';
    type?: 'button' | 'submit' | 'reset';
    loading?: boolean;
    disabled?: boolean;
    /** Stretch to the container. Used for the mobile primary action. */
    block?: boolean;
    /** Announced while loading. Short — the button label already says what is happening. */
    loadingLabel?: string;
  }>(),
  {
    variant: 'primary',
    size: 'md',
    type: 'button',
    loading: false,
    disabled: false,
    block: false,
    loadingLabel: 'Working…',
  },
);

defineEmits<{ click: [event: MouseEvent] }>();
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-disabled="disabled || loading"
    :aria-busy="loading || undefined"
    class="sv-focus-ring inline-flex items-center justify-center gap-2 rounded-control font-semibold transition-colors duration-sv-fast ease-sv-standard disabled:pointer-events-none disabled:border-sv-disabled-border disabled:bg-sv-disabled-bg disabled:text-sv-disabled-fg"
    :class="[
      {
        'min-h-sv-touch min-w-sv-touch px-3 text-xs': size === 'sm',
        'min-h-sv-touch min-w-sv-touch px-4 py-2 text-sm': size === 'md',
        'min-h-sv-touch min-w-sv-touch px-6 py-3 text-base': size === 'lg',
      },
      {
        'bg-sv-brand text-sv-text-on-brand hover:bg-sv-brand-hover': variant === 'primary',
        'border border-sv-border-input bg-transparent text-sv-text hover:bg-sv-surface-subtle': variant === 'secondary',
        'bg-transparent text-sv-text hover:bg-sv-surface-subtle': variant === 'ghost',
        'bg-sv-error-border text-sv-text-inverse hover:bg-sv-error-fg': variant === 'destructive',
      },
      block ? 'w-full' : '',
    ]"
    data-testid="sv-button"
    @click="$emit('click', $event)"
  >
    <!--
      The spinner is decorative: `aria-busy` on the button already announces the state, so
      exposing the spinner as well would produce a duplicate announcement.
    -->
    <span
      v-if="loading"
      aria-hidden="true"
      class="h-4 w-4 shrink-0 animate-spin rounded-pill border-2 border-current border-t-transparent"
    />
    <span
      v-else
      aria-hidden="true"
      class="empty:hidden"
    ><slot name="icon-leading" /></span>

    <span><slot /></span>

    <span
      aria-hidden="true"
      class="empty:hidden"
    ><slot name="icon-trailing" /></span>

    <span
      v-if="loading"
      class="sr-only"
    >{{ loadingLabel }}</span>
  </button>
</template>
