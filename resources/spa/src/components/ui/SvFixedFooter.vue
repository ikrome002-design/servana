<script setup lang="ts">
/**
 * SvFixedFooter — the viewport-fixed footer on every page (Phase UI-04; ADR-024; plan §11).
 *
 * ## The obstruction contract
 *
 * A fixed element is removed from normal flow, so unless the page reserves its block size it sits
 * ON TOP of whatever is at the bottom of the scroll — the submit button on a form, the last row
 * of a table, the safe-area inset on mobile. ADR-024 calls that list a defect catalogue, not a
 * wish list.
 *
 * ONE token per breakpoint drives both this footer's height and the space the page reserves
 * (`.sv-footer-reserve` in `style.css`). Two values that can drift apart is precisely how the
 * obstruction defects appear, so there is only ever one.
 *
 * ## Link safety
 *
 * Every external link opens in a new tab with `rel="noopener noreferrer"` — a security control,
 * not styling. `SvLink` makes that structural, so it cannot be forgotten per-link.
 *
 * ## Content boundary
 *
 * UI-04 owns the component, the layout and the link contract. It owns NO content: the legal
 * routes are the ones that already exist, and the FAQ link renders ONLY when the caller supplies
 * a route. Shipping a dead FAQ link to satisfy a label is exactly what §11.4 forbids — activating
 * the real role-aware FAQ route belongs to UI-05/UI-06.
 */
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import SvLink from '@/components/ui/SvLink.vue';
import SvThemeToggle from '@/components/ui/SvThemeToggle.vue';
import { PUBLIC_LEGAL_DOCS, PUBLIC_LEGAL_TITLES, publicLegalLocation } from '@/router/publicRoutes';
import type { RoleIdentity } from '@/types/roles';

const props = withDefaults(
  defineProps<{
    /**
     * The account whose documents these are.
     *
     * Phase UI-06 made the legal routes HOST-DERIVED: the destination is `/legal/data-policy` on
     * the current host, and the server-resolved account decides which document that is. The role
     * is therefore no longer a path segment, and this prop's remaining job is to say whether there
     * is an account at all — a public surface with no resolved context has no documents to link,
     * and linking some account's would be the cross-role leak §17.1 forbids.
     */
    legalRole?: RoleIdentity | null;
    /**
     * The FAQ destination. Supplied by the public layout now that `/faq` exists; omitted (and so
     * not rendered) anywhere it does not, rather than shipped dead.
     */
    faqTo?: RouteLocationRaw | null;
  }>(),
  { legalRole: null, faqTo: null },
);

/** Citrus Labs properties. External, new tab, safe rel — enumerated by ADR-024 / plan §11.1. */
const SOCIAL_LINKS = [
  { key: 'instagram', name: 'Instagram', href: 'https://www.instagram.com/@citruske' },
  { key: 'x', name: 'X', href: 'https://x.com/LabsCitrus' },
  { key: 'facebook', name: 'Facebook', href: 'https://www.facebook.com/profile.php?id=100063778943426' },
  { key: 'youtube', name: 'YouTube', href: 'https://www.youtube.com/@citrus-labs' },
  { key: 'linkedin', name: 'LinkedIn', href: 'https://linkedin.com/company/citrus-labs' },
] as const;

const CORPORATE_SITE = 'https://citruslabs.co.ke/';

/** Verbatim from plan §11.1. Never reworded. */
const COPYRIGHT = '© 2026 Citrus Labs. All Rights Reserved.';

/** The three legal documents, at the canonical role-free paths on the current host. */
const legalLinks = computed(() => {
  if (props.legalRole === null) {
    return [];
  }

  return PUBLIC_LEGAL_DOCS.map((doc) => ({
    key: doc,
    label: PUBLIC_LEGAL_TITLES[doc],
    to: publicLegalLocation(doc) as RouteLocationRaw,
  }));
});
</script>

<template>
  <footer
    class="sv-fixed-footer"
    data-testid="sv-fixed-footer"
  >
    <div
      class="mx-auto flex h-full max-w-sv-content flex-col justify-center gap-2 py-2 md:flex-row md:items-center md:justify-between md:gap-4"
    >
      <!-- Row one: identity and legal. -->
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
        <span data-testid="sv-footer-copyright">{{ COPYRIGHT }}</span>

        <SvLink
          :href="CORPORATE_SITE"
          new-tab
          variant="quiet"
          data-testid="sv-footer-corporate"
        >
          Citrus Labs
        </SvLink>

        <SvLink
          v-for="link in legalLinks"
          :key="link.key"
          :to="link.to"
          variant="quiet"
          :data-testid="`sv-footer-${link.key}`"
        >
          {{ link.label }}
        </SvLink>

        <!--
          Rendered only when a real destination exists. A dead FAQ link would be worse than no
          link: it promises content the product cannot yet serve.
        -->
        <SvLink
          v-if="faqTo !== null"
          :to="faqTo"
          variant="quiet"
          data-testid="sv-footer-faq"
        >
          FAQ
        </SvLink>
      </div>

      <!-- Row two: social and the theme control. -->
      <div class="flex items-center gap-1">
        <SvLink
          v-for="social in SOCIAL_LINKS"
          :key="social.key"
          :href="social.href"
          new-tab
          variant="quiet"
          :show-external-icon="false"
          class="min-h-sv-touch min-w-sv-touch justify-center text-xs"
          :data-testid="`sv-footer-${social.key}`"
        >
          <!-- Named in text, so the control is identifiable to a screen reader and at a glance. -->
          {{ social.name }}
        </SvLink>

        <SvThemeToggle />
      </div>
    </div>
  </footer>
</template>
