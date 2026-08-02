<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useFinanceDisputeStore } from '@/stores/financeDisputeStore';
import { usePermissionStore } from '@/stores/permissionStore';

/**
 * Finance dispute detail (Plan §44; Phase 18B). Move the dispute open → under_review →
 * resolved/rejected; resolution and rejection require a mandatory note. The disputed
 * source invoice/payment is READ-ONLY here — the investigation never mutates it.
 * Evidence is private (downloaded via an authorized signed link, never a stored URL).
 */
const route = useRoute();
const store = useFinanceDisputeStore();
const permissions = usePermissionStore();

const busy = ref(false);
const actionError = ref<string | null>(null);
const note = ref('');
type Verb = 'resolve' | 'reject';
const deciding = ref<Verb | null>(null);

const canManage = computed(() => permissions.can('finance_dispute.manage'));
const status = computed(() => store.current?.status);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && store.current === null) return 'loading';
  if (store.error) return 'error';
  if (store.current === null) return 'empty';
  return 'success';
});

async function startReview(): Promise<void> {
  if (store.current === null) return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.startReview(store.current.id);
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'Could not start the review.';
  } finally {
    busy.value = false;
  }
}

async function confirmDecision(): Promise<void> {
  if (deciding.value === null || store.current === null || note.value.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.decide(store.current.id, deciding.value, note.value.trim());
    deciding.value = null;
    note.value = '';
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The decision could not be recorded.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchDispute(String(route.params.id));
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Dispute
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load this dispute."
      empty-message="Dispute not found."
    >
      <SvCard
        as="section"
        padding="md"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-display text-lg font-semibold text-heading">
              {{ store.current?.reason }}
            </p>
            <p class="text-sm text-text-muted">
              Invoice {{ store.current?.invoice?.invoice_number ?? '—' }} · Status: {{ status }}
            </p>
            <p
              v-if="store.current?.resolution_note"
              class="mt-1 text-sm text-text-muted"
            >
              Resolution: {{ store.current?.resolution_note }}
            </p>
          </div>
          <div
            v-if="canManage"
            class="flex flex-wrap gap-2"
          >
            <SvButton
              v-if="status === 'open'"
              data-testid="dispute-start-review"
              :loading="busy"
              @click="startReview"
            >
              Start review
            </SvButton>
            <SvButton
              v-if="status === 'under_review'"
              data-testid="dispute-resolve"
              @click="deciding = 'resolve'"
            >
              Resolve
            </SvButton>
            <SvButton
              v-if="status === 'under_review'"
              variant="secondary"
              data-testid="dispute-reject"
              @click="deciding = 'reject'"
            >
              Reject
            </SvButton>
          </div>
        </div>

        <p
          class="mt-4 rounded-lg bg-surface-alt px-3 py-2 text-sm text-text-muted"
        >
          The linked invoice / payment is read-only here — investigating a dispute never
          changes the underlying financial record.
        </p>

        <p
          v-if="actionError"
          class="mt-3 text-sm text-sv-error-fg"
          role="alert"
        >
          {{ actionError }}
        </p>
      </SvCard>
    </SvStateBoundary>

    <SvDialog
      :open="deciding !== null"
      :title="deciding === 'resolve' ? 'Resolve dispute' : 'Reject dispute'"
      description="A resolution note is required and is recorded on the dispute."
      @close="deciding = null"
    >
      <SvTextArea
        id="dispute-note"
        v-model="note"
        label="Resolution note"
        class="mt-2"
      />
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="deciding = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="dispute-decision-confirm"
          :loading="busy"
          :disabled="note.trim() === ''"
          @click="confirmDecision"
        >
          Confirm
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
