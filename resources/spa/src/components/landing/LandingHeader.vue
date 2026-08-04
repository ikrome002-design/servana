<script setup lang="ts">
/**
 * LandingHeader — the public landing header (Phase UI-06; UI/UX plan §8.6, §13, §19).
 *
 * Public pages use a HEADER even for the seven accounts whose authenticated experience uses a
 * sidebar (§8.6): a visitor who is not signed in has no navigation tree to place there.
 *
 * ## In-page navigation
 *
 * Links are generated ONLY for regions that actually render, so an anchor can never be dead — the
 * page passes in the set it rendered rather than the set the composition hoped for. Current-section
 * indication uses `IntersectionObserver`, which reports what is on screen; it is not device
 * detection and does not branch on a user agent or a viewport measurement in JavaScript.
 *
 * ## Mobile menu
 *
 * A modal panel built on the shared `useFocusTrap`, NOT a second focus-trap implementation. Two
 * traps in one application drift: one restores focus and the other does not, one handles Shift+Tab
 * and the other does not, and the difference is invisible until a keyboard user meets it.
 * `useFocusTrap` deliberately does not own Escape — closing policy is per-overlay — so this
 * component handles Escape itself, and closing always returns focus to the trigger.
 *
 * ## One theme control, not two
 *
 * The theme control lives in the fixed footer (plan §11.1), which every public page already has
 * through `PublicLandingLayout`. Putting a second one here would give the same setting two
 * switches on one page — ambiguous to a screen-reader user, and a strict-mode ambiguity for any
 * test that asks for "the theme toggle".
 *
 * ## Responsiveness
 *
 * Pure CSS media queries at the two plan boundaries (`md` = 768, `lg` = 1025). The panel is
 * rendered only while open and is removed from the DOM when closed, so nothing sits off-screen
 * waiting to be tabbed into.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import SvLogo from '@/components/ui/SvLogo.vue';
import { useFocusTrap } from '@/composables/useFocusTrap';
import { SvIconClose, SvIconMenu } from '@/design-system/icons';
import type { ResolvedCta } from '@/content/landing/ctaResolver';
import type { LandingNavItem } from '@/content/landing/landingContract';
import { regionAnchorId } from '@/content/landing/landingContract';

const props = defineProps<{
  /** The account this header belongs to. Presentation only — it authorizes nothing. */
  accountName: string;
  navigation: readonly LandingNavItem[];
  ctas: readonly ResolvedCta[];
}>();

const primaryCta = computed(() => props.ctas.find((cta) => cta.emphasis === 'primary') ?? null);
/** Sign-in always has its own place in the header, even when it is not the primary action. */
const signInCta = computed(() => props.ctas.find((cta) => cta.kind === 'sign_in') ?? null);
const headerCtas = computed(() =>
  [primaryCta.value, primaryCta.value?.kind === 'sign_in' ? null : signInCta.value].filter(
    (cta): cta is ResolvedCta => cta !== null,
  ),
);

const open = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);

useFocusTrap({ container: panel, open });

function close(): void {
  open.value = false;
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && open.value) {
    close();
  }
}

/** The region currently on screen, for the `aria-current` marker. */
const activeRegion = ref<string | null>(null);
let observer: IntersectionObserver | null = null;

function observeSections(): void {
  if (typeof IntersectionObserver === 'undefined') {
    return; // jsdom and very old browsers: the header simply carries no current marker.
  }

  observer?.disconnect();
  observer = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (visible !== undefined) {
        activeRegion.value = visible.target.getAttribute('data-landing-region');
      }
    },
    // The band excludes the sticky header at the top and the fixed footer at the bottom, so the
    // "current" section is one the reader can actually see.
    { rootMargin: '-20% 0px -60% 0px', threshold: [0.1, 0.5] },
  );

  for (const item of props.navigation) {
    const element = document.getElementById(regionAnchorId(item.region));
    if (element !== null) {
      observer.observe(element);
    }
  }
}

onMounted(() => {
  document.addEventListener('keydown', onKeydown);
  observeSections();
});

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown);
  observer?.disconnect();
});

watch(() => props.navigation, observeSections);
</script>

<template>
  <header
    class="sticky top-0 z-sv-sticky border-b border-sv-border bg-sv-surface-page/95 backdrop-blur"
    data-testid="landing-header"
  >
    <div class="mx-auto flex max-w-sv-content items-center justify-between gap-4 px-4 py-3 md:px-6 lg:px-8">
      <RouterLink
        :to="{ name: 'home' }"
        class="sv-focus-ring flex min-h-sv-touch items-center gap-2 rounded-control"
        data-testid="landing-home-link"
      >
        <SvLogo
          size="md"
          decorative
        />
        <span class="font-display text-base font-extrabold text-sv-text-heading">Servana</span>
        <span class="sr-only">by Citrus — {{ accountName }} home</span>
      </RouterLink>

      <!-- Desktop in-page navigation. Hidden below the desktop boundary by CSS only. -->
      <nav
        aria-label="On this page"
        class="hidden lg:block"
        data-testid="landing-desktop-nav"
      >
        <ul class="flex items-center gap-1">
          <li
            v-for="item in navigation"
            :key="item.region"
          >
            <a
              :href="`#${regionAnchorId(item.region)}`"
              class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-3 text-sm font-medium text-sv-text-secondary hover:text-sv-text"
              :class="activeRegion === item.region ? 'text-sv-text underline underline-offset-4' : ''"
              :aria-current="activeRegion === item.region ? 'true' : undefined"
            >{{ item.label }}</a>
          </li>
        </ul>
      </nav>

      <div class="flex items-center gap-2">
        <a
          v-for="cta in headerCtas"
          :key="cta.key"
          :href="cta.href"
          class="sv-focus-ring hidden min-h-sv-touch items-center justify-center rounded-control px-4 text-sm font-semibold md:inline-flex"
          :class="cta.emphasis === 'primary'
            ? 'bg-sv-brand text-sv-text-on-brand hover:bg-sv-brand-hover'
            : 'border border-sv-border-input text-sv-text hover:bg-sv-surface-subtle'"
          :data-testid="`landing-header-cta-${cta.key}`"
          :data-cta-kind="cta.kind"
        >{{ cta.label }}</a>

        <button
          ref="trigger"
          type="button"
          class="sv-focus-ring inline-flex h-sv-touch w-sv-touch items-center justify-center rounded-control text-sv-text hover:bg-sv-surface-subtle lg:hidden"
          :aria-expanded="open"
          aria-controls="landing-mobile-menu"
          aria-label="Open menu"
          data-testid="landing-menu-trigger"
          @click="open = true"
        >
          <SvIconMenu
            aria-hidden="true"
            class="h-6 w-6"
          />
        </button>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="open"
        class="fixed inset-0 z-sv-drawer lg:hidden"
      >
        <div
          class="absolute inset-0 bg-sv-scrim"
          aria-hidden="true"
          data-testid="landing-menu-scrim"
          @click="close"
        />
        <div
          id="landing-mobile-menu"
          ref="panel"
          role="dialog"
          aria-modal="true"
          aria-label="Menu"
          tabindex="-1"
          class="absolute inset-y-0 left-0 flex w-sv-drawer max-w-[85%] flex-col overflow-y-auto bg-sv-surface-page p-4 shadow-overlay"
          data-testid="landing-mobile-menu"
        >
          <div class="flex items-center justify-between gap-3">
            <span class="font-display text-sm font-bold text-sv-text-heading">{{ accountName }}</span>
            <button
              type="button"
              class="sv-focus-ring inline-flex h-sv-touch w-sv-touch items-center justify-center rounded-control text-sv-text hover:bg-sv-surface-subtle"
              aria-label="Close menu"
              data-testid="landing-menu-close"
              @click="close"
            >
              <SvIconClose
                aria-hidden="true"
                class="h-5 w-5"
              />
            </button>
          </div>

          <nav
            aria-label="On this page"
            class="mt-4"
          >
            <ul class="space-y-1">
              <li
                v-for="item in navigation"
                :key="item.region"
              >
                <a
                  :href="`#${regionAnchorId(item.region)}`"
                  class="sv-focus-ring flex min-h-sv-touch items-center rounded-control px-3 text-sm font-medium text-sv-text"
                  @click="close"
                >{{ item.label }}</a>
              </li>
            </ul>
          </nav>

          <div class="mt-6 space-y-2">
            <a
              v-for="cta in ctas"
              :key="cta.key"
              :href="cta.href"
              class="sv-focus-ring flex min-h-sv-touch items-center justify-center rounded-control px-4 text-sm font-semibold"
              :class="cta.emphasis === 'primary'
                ? 'bg-sv-brand text-sv-text-on-brand'
                : 'border border-sv-border-input text-sv-text'"
              :data-testid="`landing-menu-cta-${cta.key}`"
              :data-cta-kind="cta.kind"
              @click="close"
            >{{ cta.label }}</a>
          </div>
        </div>
      </div>
    </Teleport>
  </header>
</template>
