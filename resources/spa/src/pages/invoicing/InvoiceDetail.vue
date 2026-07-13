<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useInvoiceStore } from '@/stores/invoiceStore';
import type { Invoice } from '@/types/models';
import { invoiceStatusLabel, isInvoiceReadOnly } from '@/utils/invoice';

// Invoice detail (Plan §40; Phase 17). Shows the immutable snapshot (number, items,
// preferred-personnel fee shown separately, totals, validated/balance) with masked
// client contact. Capability-gated actions come from the backend policy: Front Office
// finalizes a draft; Finance runs the additive void/adjust workflow with a mandatory
// reason and an irreversible-action warning. NO payment or receipt control exists.
const store = useInvoiceStore();
const route = useRoute();

const invoice = ref<Invoice | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const busy = ref(false);
const actionError = ref<string | null>(null);

type ReasonAction = 'void' | 'adjust';
const reasonAction = ref<ReasonAction | null>(null);
const reason = ref('');
const confirmFinalize = ref(false);
const confirmExecute = ref(false);

const id = computed(() => String(route.params.id));
const readOnly = computed(() => (invoice.value ? isInvoiceReadOnly(invoice.value.status) : false));

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (loading.value) return 'loading';
  if (error.value) return 'error';
  if (invoice.value === null) return 'empty';
  return 'success';
});

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    invoice.value = await store.fetchInvoice(id.value);
  } catch {
    error.value = 'Unable to load this invoice.';
  } finally {
    loading.value = false;
  }
}

async function run(fn: () => Promise<Invoice>): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    invoice.value = await fn();
    reasonAction.value = null;
    confirmFinalize.value = false;
    confirmExecute.value = false;
    reason.value = '';
  } catch {
    actionError.value = 'The action could not be completed. The period may be locked or the state may have changed.';
  } finally {
    busy.value = false;
  }
}

const reasonTitle = computed(() => (reasonAction.value === 'void' ? 'Void invoice' : 'Adjust invoice'));

async function submitReason(): Promise<void> {
  if (invoice.value === null || reason.value.trim() === '') return;
  const action = reasonAction.value;
  const invoiceId = invoice.value.id;
  if (action === 'void') await run(() => store.requestVoid(invoiceId, reason.value.trim()));
  else if (action === 'adjust') await run(() => store.adjust(invoiceId, reason.value.trim()));
}

onMounted(() => {
  void load();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <SvStateBoundary
      :state="boundaryState"
      empty-message="Invoice not found."
      error-message="We couldn’t load this invoice."
      @retry="() => load()"
    >
      <div v-if="invoice">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 class="font-display text-2xl font-bold text-heading">
              {{ invoice.invoice_number ?? 'Draft' }}
            </h1>
            <p class="mt-1 text-sm text-text-muted">
              {{ invoice.client?.full_name ?? 'Client' }}
              <span aria-hidden="true"> · </span>{{ invoice.client?.phone_masked }}
            </p>
          </div>
          <span
            class="rounded-full bg-surface-alt px-3 py-1 text-xs font-semibold text-text"
            data-testid="invoice-status-badge"
          >{{ invoiceStatusLabel(invoice.status) }}</span>
        </div>

        <p
          v-if="readOnly"
          class="mt-3 rounded-control border border-border bg-surface-alt px-3 py-2 text-xs text-text-muted"
          data-testid="readonly-note"
        >
          This invoice is finalized. Its amounts and number are a permanent, read-only snapshot.
        </p>

        <!-- Line items -->
        <SvCard
          class="mt-6"
          padding="md"
        >
          <h2 class="font-display text-base font-semibold text-heading">
            Items
          </h2>
          <ul
            class="mt-3 flex flex-col gap-3"
            aria-label="Invoice items"
          >
            <li
              v-for="item in invoice.items"
              :key="item.id"
              class="flex flex-wrap items-start justify-between gap-2 border-b border-border pb-3 last:border-0 last:pb-0"
            >
              <div>
                <p class="text-sm font-semibold text-text">
                  {{ item.description }}
                </p>
                <p
                  v-if="item.personnel"
                  class="text-xs text-text-muted"
                >
                  {{ item.personnel.display_name }}
                </p>
                <p
                  v-if="item.preferred_personnel_fee"
                  class="mt-0.5 text-xs text-text-muted"
                  data-testid="item-preferred-fee"
                >
                  Preferred-personnel fee: {{ item.preferred_personnel_fee.formatted }}
                </p>
              </div>
              <p class="text-sm font-semibold text-text">
                {{ item.line_total.formatted }}
              </p>
            </li>
          </ul>
        </SvCard>

        <!-- Totals -->
        <SvCard
          class="mt-4"
          padding="md"
        >
          <dl class="flex flex-col gap-1.5 text-sm">
            <div class="flex justify-between">
              <dt class="text-text-muted">
                Subtotal
              </dt>
              <dd class="text-text">
                {{ invoice.subtotal.formatted }}
              </dd>
            </div>
            <div
              v-if="invoice.preferred_personnel_fee"
              class="flex justify-between"
            >
              <dt class="text-text-muted">
                Preferred-personnel fee
              </dt>
              <dd class="text-text">
                {{ invoice.preferred_personnel_fee.formatted }}
              </dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-text-muted">
                Discount
              </dt>
              <dd class="text-text">
                {{ invoice.discount.formatted }}
              </dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-text-muted">
                Tax
              </dt>
              <dd class="text-text">
                {{ invoice.tax.formatted }}
              </dd>
            </div>
            <!-- Phase 20E — client-facing platform-fee line: the portion of the Servana platform fee
                 shifted onto this invoice (shared / business-centric tiers). Absent for customer-centric
                 and fixed-only invoices, where the server returns null. -->
            <div
              v-if="invoice.platform_fee_client_shifted"
              class="flex justify-between"
              data-testid="invoice-platform-fee-line"
            >
              <dt class="text-text-muted">
                Platform fee
              </dt>
              <dd class="text-text">
                {{ invoice.platform_fee_client_shifted.formatted }}
              </dd>
            </div>
            <div class="flex justify-between border-t border-border pt-1.5 font-display text-base font-bold">
              <dt class="text-heading">
                Total
              </dt>
              <dd
                class="text-heading"
                data-testid="invoice-total"
              >
                {{ invoice.total.formatted }}
              </dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-text-muted">
                Validated paid
              </dt>
              <dd class="text-text">
                {{ invoice.validated_paid.formatted }}
              </dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-text-muted">
                Balance
              </dt>
              <dd class="text-text">
                {{ invoice.balance.formatted }}
              </dd>
            </div>
          </dl>
        </SvCard>

        <p
          v-if="invoice.void_reason"
          class="mt-4 text-sm text-text-muted"
        >
          Void reason: {{ invoice.void_reason }}
        </p>
        <p
          v-if="invoice.adjustment_reason"
          class="mt-1 text-sm text-text-muted"
        >
          Adjustment reason: {{ invoice.adjustment_reason }}
        </p>

        <p
          v-if="actionError"
          class="mt-4 text-sm text-danger"
          role="alert"
        >
          {{ actionError }}
        </p>

        <!-- Capability-gated actions -->
        <div class="mt-6 flex flex-wrap gap-2">
          <SvButton
            v-if="invoice.can?.finalize"
            data-testid="finalize"
            @click="confirmFinalize = true"
          >
            Finalize invoice
          </SvButton>
          <SvButton
            v-if="invoice.can?.void"
            data-testid="void"
            variant="destructive"
            @click="reasonAction = 'void'; reason = ''"
          >
            Void
          </SvButton>
          <SvButton
            v-if="invoice.can?.adjust"
            data-testid="adjust"
            variant="secondary"
            @click="reasonAction = 'adjust'; reason = ''"
          >
            Adjust
          </SvButton>
          <SvButton
            v-if="invoice.can?.void_execute"
            data-testid="void-execute"
            variant="destructive"
            @click="confirmExecute = true"
          >
            Confirm void
          </SvButton>
          <SvButton
            v-if="invoice.can?.void_reject"
            data-testid="void-reject"
            variant="ghost"
            @click="run(() => store.rejectVoid(invoice!.id))"
          >
            Reject void
          </SvButton>
        </div>
      </div>
    </SvStateBoundary>

    <!-- Finalize confirmation -->
    <SvModal
      :open="confirmFinalize"
      title="Finalize this invoice?"
      description="Finalizing allocates the invoice number and locks the amounts permanently. This cannot be undone."
      @close="confirmFinalize = false"
    >
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="confirmFinalize = false"
        >
          Keep as draft
        </SvButton>
        <SvButton
          data-testid="finalize-confirm"
          :loading="busy"
          @click="run(() => store.finalize(invoice!.id))"
        >
          Finalize
        </SvButton>
      </div>
    </SvModal>

    <!-- Void execute confirmation -->
    <SvModal
      :open="confirmExecute"
      title="Void this invoice?"
      description="Voiding is irreversible. The invoice number is retained and the original amounts are preserved."
      @close="confirmExecute = false"
    >
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="confirmExecute = false"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="void-execute-confirm"
          variant="destructive"
          :loading="busy"
          @click="run(() => store.executeVoid(invoice!.id))"
        >
          Void invoice
        </SvButton>
      </div>
    </SvModal>

    <!-- Void / adjust reason -->
    <SvModal
      :open="reasonAction !== null"
      :title="reasonTitle"
      description="A reason is required and is recorded in the audit trail. This is an irreversible financial action."
      @close="reasonAction = null"
    >
      <SvTextarea
        id="invoice-reason"
        v-model="reason"
        label="Reason"
        :rows="3"
        required
      />
      <p
        v-if="actionError"
        class="mt-2 text-sm text-danger"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="reasonAction = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="reason-submit"
          :loading="busy"
          :disabled="reason.trim() === ''"
          @click="submitReason"
        >
          {{ reasonTitle }}
        </SvButton>
      </div>
    </SvModal>
  </section>
</template>
