<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useFinanceWorkspaceStore, type FinanceDuplicateReview } from '@/stores/financeWorkspaceStore';
import { PAYMENT_METHODS, usePaymentStore } from '@/stores/paymentStore';
import { formatDateTime } from '@/utils/dates';
import { statusLabel } from '@/utils/statusLabel';

const store = useFinanceWorkspaceStore();
const payments = usePaymentStore();
const filters = reactive({ method: '', page: 1 });
const selected = ref<FinanceDuplicateReview | null>(null);
const reason = ref('');
const busy = ref(false);
const actionError = ref<string | null>(null);
const state = computed(() => (store.duplicatesLoading ? 'loading' : store.duplicatesError ? 'error' : store.duplicates.length ? 'success' : 'empty'));
/** UI16-CONTENT-001: the method's own label from the payment-method vocabulary, not its token. */
const methodLabel = (method: string): string => PAYMENT_METHODS.find((m) => m.value === method)?.label ?? statusLabel(method);
const methodOptions = [{ value: '', label: 'All reference methods' }, ...PAYMENT_METHODS.filter((method) => method.value !== 'cash')];

function load(): void {
  const params: Record<string, string | number> = { page: filters.page, per_page: 20, sort: '-checked_at' };
  if (filters.method) params.method = filters.method;
  void store.fetchDuplicates(params);
}

function applyFilters(): void {
  filters.page = 1;
  load();
}

async function confirmOverride(): Promise<void> {
  if (selected.value === null || reason.value.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    await payments.overrideDuplicate(selected.value.id, reason.value.trim());
    selected.value = null;
    reason.value = '';
    load();
  } catch (error: unknown) {
    const response = error as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = response.response?.data?.error?.message ?? 'The override could not be completed.';
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — duplicate references as CASE FILES. Each held match leads with the masked
    reference, its method, the check time and the amount; the held recording and the matched record
    then sit side by side with an explicit "matches" connector so the checker compares like with
    like. The override stays behind the existing step-up dialog; the rail states what an override
    does and never does. No original reference is ever shown.
  -->
  <section
    class="sv-r-page"
    data-testid="finance-duplicate-review"
  >
    <SvPageHeader
      title="Duplicate reference review"
      eyebrow="High-risk payment control"
      description="Investigate exact normalized-reference matches without exposing the original reference. An override releases the held group; it never edits financial history."
    >
      <template #actions>
        <div class="w-64">
          <SvSelect
            id="duplicate-method"
            v-model="filters.method"
            label="Payment method"
            :options="methodOptions"
            @update:model-value="applyFilters"
          />
        </div>
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.duplicatesError ?? undefined"
      empty-message="No duplicate references are held for review."
      @retry="load"
    >
      <div class="sv-r-grid sv-r-split">
        <div
          class="sv-r-stack min-w-0"
          aria-label="Duplicate reference reviews"
        >
          <article
            v-for="item in store.duplicates"
            :key="item.id"
            class="sv-r-panel sv-r-panel--flush"
          >
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-sv-border bg-sv-warning-bg px-6 py-5">
              <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-sv-warning-fg">
                  Exact normalized-reference match
                </p>
                <h2 class="sv-numeric mt-1 font-display text-2xl font-extrabold tracking-wide text-heading">
                  {{ item.reference_masked ?? 'Masked reference unavailable' }}
                </h2>
                <p class="mt-1 text-sm text-text-muted">
                  {{ methodLabel(item.method) }} · checked {{ formatDateTime(item.checked_at) }}
                </p>
              </div>
              <p class="sv-numeric font-display text-2xl font-extrabold text-heading">
                {{ item.amount.formatted }}
              </p>
            </header>

            <div class="grid items-stretch gap-3 p-5 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
              <section class="sv-r-record">
                <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-sun">
                  Held recording
                </p>
                <p class="mt-2 font-semibold text-heading">
                  Invoice {{ item.current.invoice_number ?? '—' }}
                </p>
                <p class="mt-1 text-sm text-text-muted">
                  {{ item.current.recorded_by }} · {{ statusLabel(item.current.group_status) }}
                </p>
              </section>
              <span
                class="self-center justify-self-center rounded-pill border border-sv-warning-border bg-sv-warning-bg px-3 py-1 text-xs font-semibold text-sv-warning-fg"
              >matches</span>
              <section class="sv-r-record">
                <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-neutral">
                  Matched record
                </p>
                <template v-if="item.conflict">
                  <p class="mt-2 font-semibold text-heading">
                    Invoice {{ item.conflict.invoice_number ?? '—' }}
                  </p>
                  <p class="mt-1 text-sm text-text-muted">
                    {{ item.conflict.amount.formatted }} · {{ statusLabel(item.conflict.group_status) }}
                  </p>
                </template>
                <p
                  v-else
                  class="mt-2 text-sm text-text-muted"
                >
                  Conflict detail is unavailable in this authorized branch scope.
                </p>
              </section>
            </div>

            <footer class="flex flex-wrap justify-end gap-2 border-t border-sv-border px-5 py-4">
              <RouterLink
                class="sv-r-action sv-r-action--quiet sv-focus-ring"
                :to="{ name: 'finance.payments-validation-detail', params: { groupUlid: item.current.group_id } }"
              >
                Open validation
              </RouterLink>
              <SvButton
                v-if="item.can_override"
                variant="secondary"
                @click="selected = item"
              >
                Review override
              </SvButton>
            </footer>
          </article>

          <SvPagination
            v-if="store.duplicateMeta && store.duplicateMeta.last_page > 1"
            :current-page="store.duplicateMeta.current_page"
            :last-page="store.duplicateMeta.last_page"
            @change="(page) => { filters.page = page; load(); }"
          />
        </div>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Override rules"
        >
          <section class="sv-r-note sv-r-tone-sun">
            <h2 class="sv-r-note__title">
              What an override does
            </h2>
            <p class="sv-r-note__body">
              It releases the held group into the validation queue with your reason and a high-severity audit event. A fresh step-up may be requested.
            </p>
          </section>
          <section class="sv-r-note sv-r-tone-neutral">
            <h2 class="sv-r-note__title">
              What it never does
            </h2>
            <p class="sv-r-note__body">
              It never edits or reveals the original reference, never validates the payment and never issues a receipt.
            </p>
          </section>
        </aside>
      </div>
    </SvStateBoundary>

    <SvDialog
      :open="selected !== null"
      title="Override duplicate reference"
      description="A fresh step-up may be required. This releases the held recording for validation, records a high-severity audit event and preserves the original reference."
      @close="selected = null"
    >
      <SvTextArea
        id="duplicate-override-reason"
        v-model="reason"
        label="Override reason"
      />
      <p
        v-if="actionError"
        class="mt-2 text-sm text-sv-error-fg"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="selected = null"
        >
          Cancel
        </SvButton>
        <SvButton
          :loading="busy"
          :disabled="reason.trim() === ''"
          @click="confirmOverride"
        >
          Override and release
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
