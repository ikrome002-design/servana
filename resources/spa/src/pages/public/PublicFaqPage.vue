<script setup lang="ts">
/**
 * The public FAQ page (Phase UI-06; closes `UI01-LEGAL-001`; UI/UX plan §17.1, §17.4).
 *
 * `/faq` on every approved account host, serving THAT account's compiled FAQ and no other's. The
 * account is host-derived: there is no path segment, query parameter or stored value a visitor
 * could change to reach a different account's questions.
 *
 * The data is UI-05's compiled FAQ — 1,264 items across the eight accounts, including the sixty
 * Merchant Administrator questions the old runtime parser silently dropped (`UI05-FAQ-001`). No
 * second parser is written here and no second accordion: rendering goes through UI-04's `SvFaq`,
 * which is built on native `<details>`/`<summary>` and is therefore keyboard-operable and
 * state-announcing without any ARIA of its own.
 *
 * Category dividers from the source are preserved as headings, so a 196-question document stays
 * navigable rather than becoming one flat list. No item is ever hidden: every compiled question is
 * on the page.
 */
import { computed, onMounted, ref } from 'vue';
import PublicLandingLayout from '@/layouts/PublicLandingLayout.vue';
import SvFaq from '@/components/ui/SvFaq.vue';
import SvLink from '@/components/ui/SvLink.vue';
import SvLogo from '@/components/ui/SvLogo.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { loadGeneratedFaq } from '@/content/generated/index.generated';
import type { GeneratedFaqItem } from '@/content/generated/contentTypes.generated';
import { currentAccountContext } from '@/host/accountHostContext';

const account = computed(() => currentAccountContext());

type ViewState = 'loading' | 'error' | 'success';
const state = ref<ViewState>('loading');
const items = ref<readonly GeneratedFaqItem[]>([]);
const sourcePath = ref('');
const sourceSha = ref('');

/** Group by the source's own category divider, keeping source order within each group. */
const groups = computed(() => {
  const ordered: { category: string | null; items: GeneratedFaqItem[] }[] = [];
  for (const item of items.value) {
    const last = ordered[ordered.length - 1];
    if (last !== undefined && last.category === item.category) {
      last.items.push(item);
      continue;
    }
    ordered.push({ category: item.category, items: [item] });
  }

  return ordered;
});

onMounted(async () => {
  const resolved = account.value;
  if (resolved === null) {
    // The layout is already rendering its boundary. Loading a document here would mean choosing an
    // account, and there is no safe way to choose one.
    state.value = 'error';

    return;
  }

  try {
    const document = await loadGeneratedFaq(resolved.publicContentKey);
    items.value = document.items;
    sourcePath.value = document.meta.sourcePath;
    sourceSha.value = document.meta.sourceSha256;
    state.value = 'success';
  } catch {
    state.value = 'error';
  }
});
</script>

<template>
  <PublicLandingLayout>
    <template #header>
      <header
        class="border-b border-sv-border bg-sv-surface-page"
        data-testid="public-faq-header"
      >
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
          <span
            v-if="account"
            class="text-sm text-sv-text-muted"
          >{{ account.displayName }}</span>
        </div>
      </header>
    </template>

    <div
      class="mx-auto max-w-sv-content px-4 py-10 md:px-6 lg:px-8"
      :data-faq-account-key="account?.publicContentKey ?? ''"
      :data-content-source="sourcePath"
      :data-content-sha256="sourceSha"
      data-testid="public-faq"
    >
      <h1 class="font-display text-3xl font-extrabold text-sv-text-heading">
        Frequently asked questions
      </h1>
      <p
        v-if="account"
        class="mt-2 text-sv-text-secondary"
      >
        Answers for the {{ account.displayName }} account.
      </p>

      <SvStateBoundary
        :state="state"
        error-message="These questions could not be loaded. Refresh to try again."
        class="mt-8"
      >
        <div class="space-y-8">
          <section
            v-for="(group, index) in groups"
            :key="`${group.category ?? 'general'}-${index}`"
            :aria-labelledby="`faq-group-${index}`"
          >
            <h2
              :id="`faq-group-${index}`"
              class="font-display text-lg font-bold text-sv-text-heading"
              :class="group.category === null ? 'sr-only' : ''"
            >
              {{ group.category ?? 'Questions' }}
            </h2>
            <div class="mt-4">
              <SvFaq
                :items="group.items.map((item) => ({ id: item.id, question: item.question, answer: item.answer }))"
                :label="group.category ?? 'Frequently asked questions'"
              />
            </div>
          </section>
        </div>

        <p class="mt-10">
          <SvLink
            :to="{ name: 'home' }"
            data-testid="public-faq-back"
          >
            Back to the {{ account?.displayName ?? 'Servana' }} home page
          </SvLink>
        </p>
      </SvStateBoundary>
    </div>
  </PublicLandingLayout>
</template>
