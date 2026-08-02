<script setup lang="ts">
/**
 * SvLogo — the approved Servana wordmark (Phase UI-04; UI-00 asset contract).
 *
 * Exactly one source: `/assets/brand/Logo.png`, exact case. `Logo.svg` was deleted under
 * product-owner authority (commit `49160cd`) and must never be restored or referenced;
 * `WebAppManifestContractTest` fails the build if a reference reappears.
 *
 * Two properties worth stating because both have caused real defects elsewhere:
 *
 *  - **Explicit dimensions.** The intrinsic file is 500×500. Declaring width and height lets the
 *    browser reserve the box before the bitmap arrives, so the header does not jump — a layout
 *    shift in the shell moves every page.
 *  - **Alt text depends on context.** When adjacent text already says "Servana", a second
 *    announcement is noise, so the image is marked decorative. `decorative` makes that an
 *    explicit caller decision rather than an accident.
 *
 * The bitmap is never recoloured or filtered: it is an approved brand asset in both themes.
 */
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    size?: 'sm' | 'md' | 'lg';
    /**
     * True when adjacent visible text already names Servana. Renders `alt=""` so the logo is not
     * announced twice. False (the default) gives it a real accessible name.
     */
    decorative?: boolean;
  }>(),
  { size: 'md', decorative: false },
);

/**
 * The approved asset, served by Laravel's public root (nginx `/assets/`) and copied into the
 * preview origin by the Vite build.
 *
 * BOUND, not a static `src` attribute: a literal path in `src` is treated by Vite as a module
 * import and fails to resolve, because this file is served at runtime rather than bundled. The
 * pre-UI-04 shell bound it for the same reason.
 */
const LOGO_SRC = '/assets/brand/Logo.png';

/** Rendered box per size. The source is square, so width === height and the aspect is preserved. */
const dimension = computed(() => ({ sm: 24, md: 32, lg: 48 })[props.size]);
</script>

<template>
  <img
    :src="LOGO_SRC"
    :alt="decorative ? '' : 'Servana by Citrus'"
    :width="dimension"
    :height="dimension"
    :class="{ 'h-6 w-6': size === 'sm', 'h-8 w-8': size === 'md', 'h-12 w-12': size === 'lg' }"
    class="shrink-0 object-contain"
    decoding="async"
    data-testid="sv-logo"
  >
</template>
