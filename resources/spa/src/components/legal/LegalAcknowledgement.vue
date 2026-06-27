<script setup lang="ts">
import { computed, ref } from 'vue';
import { LEGAL_DOCS, type LegalDocType } from '@/content/roleContent';
import type { RoleIdentity } from '@/types/roles';

/**
 * Explicit legal acknowledgement (Plan §27.2; Scope §3, B19; task legal gate).
 * Mandatory platform terms (Terms of Service, Privacy Policy, Data Policy) must
 * each be acknowledged with NO pre-selected checkboxes; optional marketing
 * consent is kept separate and never required. Links open the actual rendered
 * legal pages for this exact role — one role never receives another's documents.
 * Acknowledgement cannot be bypassed: confirm is disabled until all mandatory
 * boxes are checked.
 */
const props = defineProps<{ identity: RoleIdentity }>();
const emit = defineEmits<{ acknowledged: [{ marketingConsent: boolean }] }>();

// No box is pre-checked (binding rule).
const accepted = ref<Record<LegalDocType, boolean>>({
  'terms-of-service': false,
  'privacy-policy': false,
  'data-policy': false,
});
const marketingConsent = ref(false);

const allMandatoryAccepted = computed(() =>
  LEGAL_DOCS.every((doc) => accepted.value[doc.type]),
);

function confirm(): void {
  if (!allMandatoryAccepted.value) return;
  emit('acknowledged', { marketingConsent: marketingConsent.value });
}
</script>

<template>
  <div class="space-y-4">
    <fieldset class="space-y-3">
      <legend class="text-sm font-semibold text-text">
        Please read and accept the documents that govern your account
      </legend>
      <label
        v-for="doc in LEGAL_DOCS"
        :key="doc.type"
        class="flex items-start gap-3 text-sm text-text"
      >
        <input
          v-model="accepted[doc.type]"
          type="checkbox"
          class="mt-1 h-5 w-5 rounded border-border text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :data-testid="`accept-${doc.type}`"
        >
        <span>
          I have read and accept the
          <RouterLink
            :to="{ name: 'legal.document', params: { role: props.identity, doc: doc.type } }"
            class="font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            target="_blank"
          >{{ doc.title }}</RouterLink>.
        </span>
      </label>
    </fieldset>

    <label class="flex items-start gap-3 border-t border-border pt-4 text-sm text-text-muted">
      <input
        v-model="marketingConsent"
        type="checkbox"
        class="mt-1 h-5 w-5 rounded border-border text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        data-testid="accept-marketing"
      >
      <span>Optional: send me Servana product updates and tips. You can change this anytime.</span>
    </label>

    <button
      type="button"
      class="inline-flex min-h-[44px] w-full items-center justify-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep hover:bg-orange-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
      :disabled="!allMandatoryAccepted"
      data-testid="confirm-acknowledgement"
      @click="confirm"
    >
      Acknowledge and continue
    </button>
  </div>
</template>
