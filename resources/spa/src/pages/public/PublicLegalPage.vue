<script setup lang="ts">
/**
 * A canonical public legal document (Phase UI-06; closes `UI01-LEGAL-002` in part; §17.1–§17.4).
 *
 * `/legal/data-policy`, `/legal/privacy-policy` and `/legal/terms-of-service` on every approved
 * account host — twenty-four routes in all, each serving its own host's document.
 *
 * The ACCOUNT is host-derived and the DOCUMENT is one of three fixed slugs matched by the route
 * itself, so neither can be steered by a visitor. That is the whole difference from the older
 * `/legal/:role/:doc` shape, where the role came from the path.
 *
 * The text is UI-05's compiled document, byte-identical to `docs/legal/**`, rendered through UI-04's
 * `SvLegalDocument` and the audited markdown renderer. It is never paraphrased, summarised,
 * truncated or reordered, and the rendered element carries the source path and hash so the browser
 * proof can assert provenance rather than trusting the copy.
 */
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import PublicLandingLayout from '@/layouts/PublicLandingLayout.vue';
import SvLegalDocument from '@/components/ui/SvLegalDocument.vue';
import SvLink from '@/components/ui/SvLink.vue';
import SvLogo from '@/components/ui/SvLogo.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { loadGeneratedLegal } from '@/content/generated/index.generated';
import { currentAccountContext } from '@/host/accountHostContext';
import { isPublicLegalDoc, PUBLIC_LEGAL_TITLES, type PublicLegalDoc } from '@/router/publicRoutes';

/** Route slug → the content category the generated loader knows. */
const CATEGORY: Record<PublicLegalDoc, 'data_policy' | 'privacy_policy' | 'terms_of_service'> = {
  'data-policy': 'data_policy',
  'privacy-policy': 'privacy_policy',
  'terms-of-service': 'terms_of_service',
};

const route = useRoute();
const account = computed(() => currentAccountContext());
const doc = computed<PublicLegalDoc | null>(() =>
  isPublicLegalDoc(route.params['doc']) ? route.params['doc'] : null,
);

type ViewState = 'loading' | 'error' | 'success';
const state = ref<ViewState>('loading');
const markdown = ref('');
const sourcePath = ref('');
const sourceSha = ref('');

onMounted(async () => {
  const resolved = account.value;
  const slug = doc.value;

  if (resolved === null || slug === null) {
    state.value = 'error';

    return;
  }

  try {
    const document = await loadGeneratedLegal(resolved.legalContentKey, CATEGORY[slug]);
    markdown.value = document.markdown;
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
        data-testid="public-legal-header"
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

    <div class="mx-auto max-w-sv-content px-4 py-10 md:px-6 lg:px-8">
      <SvStateBoundary
        :state="state"
        error-message="That legal document could not be found."
      >
        <SvLegalDocument
          :title="doc ? PUBLIC_LEGAL_TITLES[doc] : ''"
          :markdown="markdown"
          :data-legal-account-key="account?.legalContentKey ?? ''"
          :data-content-source="sourcePath"
          :data-content-sha256="sourceSha"
        />

        <p class="mx-auto mt-10 max-w-sv-readable">
          <SvLink
            :to="{ name: 'home' }"
            data-testid="public-legal-back"
          >
            Back to the {{ account?.displayName ?? 'Servana' }} home page
          </SvLink>
        </p>
      </SvStateBoundary>
    </div>
  </PublicLandingLayout>
</template>
