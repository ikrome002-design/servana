<script setup lang="ts">
/**
 * The public not-found boundary (Phase UI-06).
 *
 * Before this phase the catch-all rendered the account entry surface, so an unknown path looked
 * like a working page. It now says plainly that the address does not exist and offers the two
 * destinations that always do on this host — home and the account's FAQ.
 *
 * It names no other account and links to no other host.
 */
import { computed } from 'vue';
import PublicLandingLayout from '@/layouts/PublicLandingLayout.vue';
import SvLink from '@/components/ui/SvLink.vue';
import SvLogo from '@/components/ui/SvLogo.vue';
import { currentAccountContext } from '@/host/accountHostContext';
import { publicFaqLocation } from '@/router/publicRoutes';

const account = computed(() => currentAccountContext());
</script>

<template>
  <PublicLandingLayout>
    <template #header>
      <header class="border-b border-sv-border bg-sv-surface-page">
        <div class="mx-auto flex max-w-sv-content items-center gap-3 px-4 py-3 md:px-6 lg:px-8">
          <RouterLink
            :to="{ name: 'home' }"
            class="sv-focus-ring flex min-h-sv-touch items-center gap-2 rounded-control"
          >
            <SvLogo
              size="md"
              decorative
            />
            <span class="font-display text-base font-extrabold text-sv-text-heading">Servana</span>
          </RouterLink>
        </div>
      </header>
    </template>

    <div
      class="mx-auto max-w-sv-readable px-4 py-16"
      data-testid="public-not-found"
    >
      <h1 class="font-display text-3xl font-extrabold text-sv-text-heading">
        We couldn't find that page
      </h1>
      <p class="mt-3 text-sv-text-secondary">
        The address you followed doesn't exist on this Servana account. It may have moved, or the
        link may be incomplete.
      </p>
      <ul class="mt-6 space-y-2">
        <li>
          <SvLink :to="{ name: 'home' }">
            Go to the {{ account?.displayName ?? 'Servana' }} home page
          </SvLink>
        </li>
        <li v-if="account">
          <SvLink :to="publicFaqLocation()">
            Read the frequently asked questions
          </SvLink>
        </li>
      </ul>
    </div>
  </PublicLandingLayout>
</template>
