<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import {
  PAYMENT_REFERENCE_PENDING_TEXT,
  useSubscriptionInvoiceStore,
  type SubscriptionInvoice,
} from '@/stores/subscriptionInvoiceStore';
import { useSubscriptionStore } from '@/stores/subscriptionStore';
import { formatMoney } from '@/utils/money';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

/**
 * Subscription invoices (Plan §49; Phase 20B). List + detail of the immutable invoice financial
 * snapshots. GENERATION (new PDF) is a durable mutation blocked in billing read-only states;
 * DOWNLOAD of an EXISTING PDF is a read allowed even in read-only. `payment_reference_pending`
 * shows the exact pending-reference copy. No Wallet payment / STK / PayBill-Till / provider UI.
 */
const store = useSubscriptionInvoiceStore();
const subscriptionStore = useSubscriptionStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canDownload = computed(() => can('merchant.subscription.invoice.download'));
const selected = ref<SubscriptionInvoice | null>(null);
const working = ref(false);
const actionError = ref<string | null>(null);
const billingReadOnly = computed(() => subscriptionStore.subscription?.billing_read_only === true);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.invoices.length === 0) return 'empty';
  return 'success';
});

onMounted(async () => {
  if (!can('merchant.subscription.invoice.view')) return;
  await store.fetchInvoices();
  if (store.invoices.length > 0) selected.value = store.invoices[0] ?? null;
  try {
    await subscriptionStore.fetchSubscription();
  } catch {
    // The invoices list still renders; the generate gate falls back to the server 403.
  }
});

function select(invoice: SubscriptionInvoice): void {
  selected.value = invoice;
  actionError.value = null;
}

async function generatePdf(): Promise<void> {
  if (working.value || selected.value === null || !canDownload.value) return;
  working.value = true;
  actionError.value = null;
  try {
    const updated = await store.generatePdf(selected.value.id);
    selected.value = updated;
    notifications.addToast({ type: 'success', message: 'Invoice PDF generated.' });
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError?.code === 'billing_read_only') {
      actionError.value = 'New invoice PDFs are paused while billing is in read-only mode.';
    } else if (axios.isAxiosError(err) && err.apiError) {
      actionError.value = err.apiError.message ?? 'The PDF could not be generated.';
    } else {
      actionError.value = 'The PDF could not be generated.';
    }
  } finally {
    working.value = false;
  }
}

async function download(): Promise<void> {
  if (working.value || selected.value === null || !canDownload.value) return;
  working.value = true;
  actionError.value = null;
  try {
    const { url } = await store.downloadLink(selected.value.id);
    window.open(url, '_blank', 'noopener');
  } catch (err) {
    actionError.value = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'The download link could not be issued.';
  } finally {
    working.value = false;
  }
}
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — billing history as a density-calibrated LEDGER (the Billings reference's
    history table): invoice, period, amount and a status pill per row, each row selecting the
    invoice; the selected invoice opens as a statement card beside it with its document actions.
    Generation stays blocked in billing read-only; downloading an existing PDF does not.
  -->
  <div class="sv-r-page sv-r-stack">
    <SvPageHeader
      class="!mb-0"
      title="Subscription invoices"
      eyebrow="Subscription & Billing"
      description="Your Servana subscription invoices and PDFs."
    />

    <p
      v-if="!can('merchant.subscription.invoice.view')"
      class="rounded-control bg-surface-alt px-4 py-3 text-sm text-text-muted"
      role="note"
    >
      You do not have access to subscription invoices.
    </p>

    <SvStateBoundary
      v-else
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      empty-message="No subscription invoices have been issued yet."
      @retry="store.fetchInvoices()"
    >
      <div class="sv-r-grid lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] lg:items-start">
        <!-- Ledger -->
        <section
          class="sv-r-ledger"
          aria-labelledby="invoice-ledger-heading"
        >
          <div class="sv-r-ledger__head">
            <h2
              id="invoice-ledger-heading"
              class="sv-r-title"
            >
              Billing history
            </h2>
            <span class="sv-r-chip">{{ store.invoices.length }} on this page</span>
          </div>
          <div
            class="sv-r-ledger__colhead"
            style="--sv-r-ledger-cols: minmax(0, 1.2fr) minmax(0, 1fr) auto"
            aria-hidden="true"
          >
            <span>Invoice</span><span>Amount</span><span>Status</span>
          </div>
          <h2 class="sr-only">
            Invoice list
          </h2>
          <ul role="list">
            <li
              v-for="invoice in store.invoices"
              :key="invoice.id"
            >
              <button
                type="button"
                class="sv-r-ledger__row sv-focus-ring w-full text-left"
                :class="{ 'bg-sv-surface-warm': selected?.id === invoice.id }"
                style="--sv-r-ledger-cols: minmax(0, 1.2fr) minmax(0, 1fr) auto"
                :aria-current="selected?.id === invoice.id ? 'true' : undefined"
                :data-testid="`invoice-row-${invoice.id}`"
                @click="select(invoice)"
              >
                <span class="min-w-0">
                  <span class="block font-semibold text-sv-text-heading">{{ invoice.invoice_number ?? 'Draft' }}</span>
                  <span class="block text-xs text-text-muted">{{ invoice.period_start }} → {{ invoice.period_end }}</span>
                </span>
                <span class="sv-numeric text-sm font-semibold text-text">{{ formatMoney(invoice.total_minor, invoice.currency) }}</span>
                <span class="sv-r-chip sv-r-chip--tone sv-r-chip--dot sv-r-tone-teal justify-self-start">{{ invoice.status }}</span>
              </button>
            </li>
          </ul>
        </section>

        <!-- Statement -->
        <section
          v-if="selected"
          class="sv-r-panel sv-r-panel--tonal sv-r-tone-teal sv-r-sticky"
          aria-label="Selected invoice"
        >
          <div class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="sv-r-eyebrow">
                  Statement
                </p>
                <h2
                  class="sv-r-title-lg mt-1"
                  data-testid="invoice-number"
                >
                  {{ selected.invoice_number ?? 'Draft invoice' }}
                </h2>
                <p class="sv-r-muted">
                  {{ selected.period_start }} → {{ selected.period_end }}
                </p>
              </div>
              <span class="sv-r-chip sv-r-chip--tone sv-r-chip--dot sv-r-tone-teal">
                {{ selected.status }}
              </span>
            </div>

            <dl class="sv-r-facts">
              <div class="flex items-baseline justify-between gap-3">
                <dt>Subtotal</dt>
                <dd class="sv-numeric !mt-0">
                  {{ formatMoney(selected.subtotal_minor, selected.currency) }}
                </dd>
              </div>
              <div class="flex items-baseline justify-between gap-3">
                <dt>Discount</dt>
                <dd class="sv-numeric !mt-0">
                  {{ formatMoney(selected.discount_minor, selected.currency) }}
                </dd>
              </div>
              <div class="flex items-baseline justify-between gap-3">
                <dt>Total</dt>
                <dd class="sv-numeric !mt-0 text-sv-text-heading">
                  {{ formatMoney(selected.total_minor, selected.currency) }}
                </dd>
              </div>
              <div class="flex items-baseline justify-between gap-3">
                <dt>Balance</dt>
                <dd class="sv-r-figure-md !mt-0">
                  {{ formatMoney(selected.balance_minor, selected.currency) }}
                </dd>
              </div>
              <div class="flex items-baseline justify-between gap-3">
                <dt>Issued</dt>
                <dd class="!mt-0">
                  {{ selected.issued_at?.slice(0, 10) ?? '—' }}
                </dd>
              </div>
              <div class="flex items-baseline justify-between gap-3">
                <dt>Due</dt>
                <dd class="!mt-0">
                  {{ selected.due_at?.slice(0, 10) ?? '—' }}
                </dd>
              </div>
            </dl>

            <p
              v-if="selected.payment_reference_pending"
              class="rounded-control bg-sv-surface-subtle px-3 py-2 text-sm text-text-muted"
              data-testid="payment-reference-pending"
            >
              {{ PAYMENT_REFERENCE_PENDING_TEXT }}
            </p>
            <p
              v-else-if="selected.account_reference"
              class="text-sm text-text"
            >
              Account reference: <strong>{{ selected.account_reference }}</strong>
            </p>

            <p
              v-if="actionError"
              class="text-sm text-error"
              role="alert"
            >
              {{ actionError }}
            </p>

            <div
              v-if="canDownload"
              class="flex flex-wrap gap-3 border-t border-sv-border pt-4"
            >
              <RouterLink
                :to="{ name: 'merchant.subscription-invoice-detail', params: { invoiceUlid: selected.id } }"
                class="sv-r-action sv-r-action--quiet sv-focus-ring"
              >
                Open invoice detail
              </RouterLink>
              <!-- Generate: mutation; disabled in billing read-only (backend also blocks). -->
              <SvButton
                :loading="working"
                :disabled="billingReadOnly"
                data-testid="generate-pdf"
                @click="generatePdf"
              >
                {{ selected.has_pdf ? 'Regenerate PDF' : 'Generate PDF' }}
              </SvButton>
              <!-- Download existing: read; allowed even in billing read-only. -->
              <SvButton
                v-if="selected.has_pdf"
                variant="secondary"
                :loading="working"
                data-testid="download-pdf"
                @click="download"
              >
                Download PDF
              </SvButton>
            </div>
            <p
              v-if="canDownload && billingReadOnly"
              class="text-xs text-text-muted"
            >
              New PDF generation is paused while billing is in read-only mode; existing PDFs remain
              downloadable.
            </p>
          </div>
        </section>
      </div>
    </SvStateBoundary>
  </div>
</template>