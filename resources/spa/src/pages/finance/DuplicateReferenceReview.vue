<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useFinanceWorkspaceStore, type FinanceDuplicateReview } from '@/stores/financeWorkspaceStore';
import { PAYMENT_METHODS, usePaymentStore } from '@/stores/paymentStore';
import { formatDateTime } from '@/utils/dates';

const store = useFinanceWorkspaceStore();
const payments = usePaymentStore();
const filters = reactive({ method: '', page: 1 });
const selected = ref<FinanceDuplicateReview | null>(null);
const reason = ref('');
const busy = ref(false);
const actionError = ref<string | null>(null);
const state = computed(() => (store.duplicatesLoading ? 'loading' : store.duplicatesError ? 'error' : store.duplicates.length ? 'success' : 'empty'));
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
  <section
    class="mx-auto max-w-6xl"
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
      <div
        class="space-y-4"
        aria-label="Duplicate reference reviews"
      >
        <SvCard
          v-for="item in store.duplicates"
          :key="item.id"
          as="article"
          class="border-l-4 border-l-sv-warning-border"
        >
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-sv-warning-fg">
                Exact normalized-reference match
              </p>
              <h2 class="mt-1 font-display text-lg font-bold text-heading">
                {{ item.reference_masked ?? 'Masked reference unavailable' }}
              </h2>
              <p class="mt-1 text-sm text-text-muted">
                {{ item.method }} · checked {{ formatDateTime(item.checked_at) }}
              </p>
            </div>
            <p class="font-display text-xl font-bold text-heading">
              {{ item.amount.formatted }}
            </p>
          </div>

          <div class="mt-4 grid gap-3 md:grid-cols-2">
            <div class="rounded-control border border-sv-border bg-surface-alt p-3">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Held recording
              </p>
              <p class="mt-1 font-semibold text-heading">
                Invoice {{ item.current.invoice_number ?? '—' }}
              </p>
              <p class="mt-1 text-sm text-text-muted">
                {{ item.current.recorded_by }} · {{ item.current.group_status }}
              </p>
            </div>
            <div class="rounded-control border border-sv-border bg-surface-alt p-3">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Matched record
              </p>
              <template v-if="item.conflict">
                <p class="mt-1 font-semibold text-heading">
                  Invoice {{ item.conflict.invoice_number ?? '—' }}
                </p>
                <p class="mt-1 text-sm text-text-muted">
                  {{ item.conflict.amount.formatted }} · {{ item.conflict.group_status }}
                </p>
              </template>
              <p
                v-else
                class="mt-1 text-sm text-text-muted"
              >
                Conflict detail is unavailable in this authorized branch scope.
              </p>
            </div>
          </div>

          <div class="mt-4 flex flex-wrap justify-end gap-2">
            <RouterLink
              class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-3 py-2 text-sm font-semibold text-heading underline"
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
          </div>
        </SvCard>
      </div>

      <SvPagination
        v-if="store.duplicateMeta && store.duplicateMeta.last_page > 1"
        class="mt-5"
        :current-page="store.duplicateMeta.current_page"
        :last-page="store.duplicateMeta.last_page"
        @change="(page) => { filters.page = page; load(); }"
      />
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
