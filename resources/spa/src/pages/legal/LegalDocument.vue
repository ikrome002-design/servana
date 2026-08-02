<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { renderMarkdown } from '@/content/markdown';
import { LEGAL_DOCS, type LegalDocType } from '@/content/roleContent';
import { loadLegalDoc } from '@/content/legalContent';
import { ROLE_IDENTITIES, type RoleIdentity } from '@/types/roles';
import { SvIconBack } from '@/design-system/icons';

/**
 * Rendered role-specific legal document (Plan §27.2; task legal requirements).
 * Sources the approved document verbatim from `docs/legal/**` (loaded lazily,
 * never hand-copied into source) for the exact role + document in the route —
 * one role never receives another role's documents. Linked from each role's
 * landing footer and the final acknowledgement step.
 */
const route = useRoute();

const identity = computed<RoleIdentity | null>(() => {
  const role = route.params.role;
  return typeof role === 'string' && ROLE_IDENTITIES.includes(role as RoleIdentity)
    ? (role as RoleIdentity)
    : null;
});
const docType = computed<LegalDocType | null>(() => {
  const doc = route.params.doc;
  return LEGAL_DOCS.some((d) => d.type === doc) ? (doc as LegalDocType) : null;
});
const meta = computed(() => LEGAL_DOCS.find((d) => d.type === docType.value) ?? null);

type ViewState = 'loading' | 'error' | 'success';
const state = ref<ViewState>('loading');
const html = ref('');

watchEffect(async () => {
  if (!identity.value || !docType.value) {
    state.value = 'error';
    return;
  }
  state.value = 'loading';
  try {
    const raw = await loadLegalDoc(identity.value, docType.value);
    html.value = renderMarkdown(raw);
    state.value = 'success';
  } catch {
    state.value = 'error';
  }
});
</script>

<template>
  <main class="min-h-screen bg-bg text-text">
    <div class="mx-auto max-w-3xl px-4 py-10">
      <RouterLink
        :to="{ name: 'home' }"
        class="text-sm font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        <SvIconBack
          aria-hidden="true"
          class="mr-1 inline-block h-4 w-4 align-text-bottom"
        />Back to Servana
      </RouterLink>
      <SvStateBoundary
        :state="state"
        error-message="That legal document could not be found."
        class="mt-4"
      >
        <h1 class="sr-only">
          {{ meta?.title }}
        </h1>
        <!-- eslint-disable-next-line vue/no-v-html -- trusted, version-controlled legal content -->
        <article
          class="prose-legal"
          v-html="html"
        />
      </SvStateBoundary>
    </div>
  </main>
</template>
