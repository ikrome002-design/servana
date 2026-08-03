<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvLegalDocument from '@/components/ui/SvLegalDocument.vue';
import { LEGAL_DOCS, type LegalDocType } from '@/content/roleContent';
import { loadLegalDocument } from '@/content/legalContent';
import { currentAccountContext } from '@/host/accountHostContext';
import { ROLE_IDENTITIES, type RoleIdentity } from '@/types/roles';
import { SvIconBack } from '@/design-system/icons';

/**
 * Rendered role-specific legal document.
 *
 * The approved text is sourced verbatim from `docs/legal/**` through the Phase UI-05 generated
 * content contract — one document per role and type, hashed at generation time, never hand-copied
 * and never cross-mapped: an unknown role or type renders the not-found boundary rather than
 * falling back to another account's document (UI/UX plan §17.1).
 *
 * Rendering goes through UI-04's `SvLegalDocument`, so the escaping markdown renderer, the readable
 * measure and the document heading semantics are the shared audited ones rather than a second
 * implementation here.
 */
const route = useRoute();

/**
 * The role named in the PATH.
 *
 * Phase UI-06 made `/legal/data-policy` the canonical, host-derived route and kept this shape only
 * for compatibility. `routes/public.ts` redirects the account's own documents here to the canonical
 * path; anything else must fail closed, which is what the account cross-check below does. It is
 * deliberately not a redirect: sending a visitor to another account's document would be a worse
 * defect than the one being closed.
 *
 * With no resolved account context — the standalone Vite preview origin embeds none — there is no
 * host account to compare against, so behaviour is unchanged from before this phase.
 */
const identity = computed<RoleIdentity | null>(() => {
  const role = route.params.role;
  if (typeof role !== 'string' || !ROLE_IDENTITIES.includes(role as RoleIdentity)) {
    return null;
  }

  const context = currentAccountContext();
  if (context !== null && context.legalContentKey !== role) {
    return null;
  }

  return role as RoleIdentity;
});
const docType = computed<LegalDocType | null>(() => {
  const doc = route.params.doc;
  return LEGAL_DOCS.some((d) => d.type === doc) ? (doc as LegalDocType) : null;
});
const meta = computed(() => LEGAL_DOCS.find((d) => d.type === docType.value) ?? null);

type ViewState = 'loading' | 'error' | 'success';
const state = ref<ViewState>('loading');
const markdown = ref('');
/** Provenance of the document actually rendered — proves which source file produced it. */
const sourcePath = ref('');
const sourceSha256 = ref('');

watchEffect(async () => {
  if (!identity.value || !docType.value) {
    state.value = 'error';
    return;
  }
  state.value = 'loading';
  try {
    const document = await loadLegalDocument(identity.value, docType.value);
    markdown.value = document.markdown;
    sourcePath.value = document.meta.sourcePath;
    sourceSha256.value = document.meta.sourceSha256;
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
        <SvLegalDocument
          :title="meta?.title ?? ''"
          :markdown="markdown"
          :data-content-source="sourcePath"
          :data-content-sha256="sourceSha256"
        />
      </SvStateBoundary>
    </div>
  </main>
</template>
