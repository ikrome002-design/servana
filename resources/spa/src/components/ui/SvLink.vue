<script setup lang="ts">
/**
 * SvLink — navigation (Phase UI-04; UI/UX plan §11.4, §14.1; ADR-024).
 *
 * A LINK navigates; a button acts. Keeping them separate preserves middle-click, "open in new
 * tab", "copy link address" and the correct screen-reader role — all of which a `<button>` styled
 * as a link silently destroys.
 *
 * Internal links render `<RouterLink>`; external links render a plain anchor and ALWAYS carry
 * `rel="noopener noreferrer"` when they open a new tab. That pairing is a security control, not
 * styling: `noopener` closes reverse-tabnabbing (the opened page can otherwise reach back through
 * `window.opener`) and `noreferrer` stops the Servana URL leaking to third-party social
 * properties. ADR-024 requires it on every footer link, and this component makes it structural
 * rather than something each caller has to remember.
 *
 * A DISABLED link renders a `<span>`, never an anchor with its handler removed: an anchor still
 * sits in the tab order and still announces as a link, so it would promise navigation that cannot
 * happen.
 *
 * ONE root node, resolved through `<component :is>`. A `v-if`/`v-else` chain at the template root
 * makes the component a fragment, and a fragment has no attributes for a caller — or a test — to
 * inspect.
 */
import { computed } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { SvIconExternal } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    /** Internal destination. Mutually exclusive with `href`. */
    to?: RouteLocationRaw;
    /** External destination. Mutually exclusive with `to`. */
    href?: string;
    /** Open in a new tab. Forces the safe `rel` below. */
    newTab?: boolean;
    variant?: 'default' | 'subtle' | 'quiet';
    disabled?: boolean;
    /**
     * Show the external-link affordance. Defaults to true for a new-tab link, because a link that
     * leaves the application should say so before it is followed.
     */
    showExternalIcon?: boolean;
  }>(),
  {
    to: undefined,
    href: undefined,
    newTab: false,
    variant: 'default',
    disabled: false,
    showExternalIcon: undefined,
  },
);

const isExternal = computed(() => typeof props.href === 'string' && props.href !== '');
const opensNewTab = computed(() => props.newTab && isExternal.value);
const showsExternalIcon = computed(() => props.showExternalIcon ?? opensNewTab.value);

const tag = computed(() => {
  if (props.disabled) {
    return 'span';
  }

  return isExternal.value ? 'a' : RouterLink;
});

/** Always BOTH tokens together — `noopener` alone still leaks the referrer. */
const linkAttrs = computed<Record<string, unknown>>(() => {
  if (props.disabled) {
    return { 'aria-disabled': 'true' };
  }
  if (isExternal.value) {
    return {
      href: props.href,
      target: opensNewTab.value ? '_blank' : undefined,
      rel: opensNewTab.value ? 'noopener noreferrer' : undefined,
    };
  }

  return { to: props.to ?? '' };
});

const classes = computed(() => [
  'sv-focus-ring inline-flex items-center gap-1 rounded-control underline-offset-2',
  {
    default: 'text-sv-link underline hover:text-sv-link-hover',
    subtle: 'text-sv-text hover:text-sv-link hover:underline',
    quiet: 'text-sv-text-muted hover:text-sv-text',
  }[props.variant],
  props.disabled ? 'cursor-not-allowed text-sv-disabled-fg no-underline' : '',
]);
</script>

<template>
  <component
    :is="tag"
    v-bind="linkAttrs"
    :class="classes"
    data-testid="sv-link"
  >
    <slot />
    <SvIconExternal
      v-if="showsExternalIcon && !disabled"
      aria-hidden="true"
      class="h-4 w-4 shrink-0"
    />
    <!--
      The icon is decorative, so the fact that this opens a new tab is stated in text for screen
      readers instead of being left to a glyph nobody hears.
    -->
    <span
      v-if="opensNewTab && !disabled"
      class="sr-only"
    >(opens in a new tab)</span>
  </component>
</template>
