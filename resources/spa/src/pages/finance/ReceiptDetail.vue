<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useReceiptStore } from '@/stores/receiptStore';
import { usePermissionStore } from '@/stores/permissionStore';
import SvMoney from '@/components/ui/SvMoney.vue';

/**
 * Receipt detail (Plan §43; Phase 18B). View the immutable receipt + its snapshot
 * components. Finance may REISSUE (a new gap-free number referencing the original,
 * `receipt.reissue`); both Finance and Front Office may DOWNLOAD via a short-lived
 * authorized signed link (the URL is used immediately and never stored). No manual
 * issue action.
 */
const route = useRoute();
const store = useReceiptStore();
const permissions = usePermissionStore();

const busy = ref(false);
const reissuing = ref(false);
const reason = ref('');
const actionError = ref<string | null>(null);

const canReissue = computed(() => permissions.can('receipt.reissue'));
const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && store.current === null) return 'loading';
  if (store.error) return 'error';
  if (store.current === null) return 'empty';
  return 'success';
});

async function download(): Promise<void> {
  actionError.value = null;
  try {
    const url = await store.downloadLink(String(route.params.id));
    window.open(url, '_blank', 'noopener');
  } catch {
    actionError.value = 'The receipt is not ready to download yet.';
  }
}

async function confirmReissue(): Promise<void> {
  if (reason.value.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.reissue(String(route.params.id), reason.value.trim());
    reissuing.value = false;
    reason.value = '';
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The receipt could not be reissued.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchReceipt(String(route.params.id));
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Receipt
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load this receipt."
      empty-message="Receipt not found."
    >
      <SvCard
        as="section"
        padding="md"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-display text-lg font-semibold text-heading">
              Receipt #{{ store.current?.receipt_number }}
            </p>
            <p class="text-sm text-text-muted">
              Invoice {{ store.current?.invoice?.invoice_number ?? '—' }} · <SvMoney :formatted="store.current?.amount?.formatted ?? null" />
            </p>
            <p
              v-if="store.current?.is_reissue"
              class="mt-1 text-xs text-text-muted"
            >
              Reissue{{ store.current?.reason ? ` — ${store.current.reason}` : '' }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <SvButton
              data-testid="receipt-download"
              :disabled="!store.current?.downloadable"
              @click="download"
            >
              Download
            </SvButton>
            <SvButton
              v-if="canReissue"
              variant="secondary"
              data-testid="receipt-reissue-open"
              @click="reissuing = true"
            >
              Reissue
            </SvButton>
          </div>
        </div>

        <ul
          class="mt-4 flex flex-col gap-2"
          aria-label="Receipt components"
        >
          <li
            v-for="(component, index) in store.current?.components ?? []"
            :key="index"
            class="flex items-center justify-between rounded-lg bg-surface-alt px-3 py-2 text-sm"
          >
            <span class="font-semibold text-heading">{{ component.method }}</span>
            <span class="font-semibold text-heading"><SvMoney :formatted="component.amount?.formatted ?? null" /></span>
          </li>
        </ul>

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
      :open="reissuing"
      title="Reissue receipt"
      description="A reissue creates a NEW receipt number that references this original. The original is never altered. A reason is required."
      @close="reissuing = false"
    >
      <SvTextArea
        id="reissue-reason"
        v-model="reason"
        label="Reason"
        class="mt-2"
      />
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="reissuing = false"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="receipt-reissue-confirm"
          :loading="busy"
          :disabled="reason.trim() === ''"
          @click="confirmReissue"
        >
          Reissue receipt
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
