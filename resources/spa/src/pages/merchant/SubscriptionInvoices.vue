<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
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
  <div class="mx-auto flex max-w-5xl flex-col gap-6">
    <header>
      <h1 class="font-display text-2xl font-bold text-heading">
        Subscription invoices
      </h1>
      <p class="mt-1 text-sm text-text-muted">
        Your Servana subscription invoices and PDFs.
      </p>
    </header>

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
      <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
        <!-- List -->
        <SvCard>
          <h2 class="sr-only">
            Invoice list
          </h2>
          <ul
            role="list"
            class="flex flex-col divide-y divide-border"
          >
            <li
              v-for="invoice in store.invoices"
              :key="invoice.id"
            >
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 px-1 py-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :class="{ 'font-semibold text-heading': selected?.id === invoice.id }"
                :aria-current="selected?.id === invoice.id ? 'true' : undefined"
                :data-testid="`invoice-row-${invoice.id}`"
                @click="select(invoice)"
              >
                <span>{{ invoice.invoice_number ?? 'Draft' }}</span>
                <span class="text-sm text-text-muted">
                  {{ formatMoney(invoice.total_minor, invoice.currency) }} · {{ invoice.status }}
                </span>
              </button>
            </li>
          </ul>
        </SvCard>

        <!-- Detail -->
        <SvCard v-if="selected">
          <div class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h2
                  class="font-display text-lg font-bold text-heading"
                  data-testid="invoice-number"
                >
                  {{ selected.invoice_number ?? 'Draft invoice' }}
                </h2>
                <p class="text-sm text-text-muted">
                  {{ selected.period_start }} → {{ selected.period_end }}
                </p>
              </div>
              <span class="rounded-full bg-surface-alt px-2 py-0.5 text-xs font-semibold text-text">
                {{ selected.status }}
              </span>
            </div>

            <dl class="grid gap-3 sm:grid-cols-2">
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Subtotal
                </dt>
                <dd class="mt-1 text-text">
                  {{ formatMoney(selected.subtotal_minor, selected.currency) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Discount
                </dt>
                <dd class="mt-1 text-text">
                  {{ formatMoney(selected.discount_minor, selected.currency) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Total
                </dt>
                <dd class="mt-1 font-semibold text-heading">
                  {{ formatMoney(selected.total_minor, selected.currency) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Balance
                </dt>
                <dd class="mt-1 text-text">
                  {{ formatMoney(selected.balance_minor, selected.currency) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Issued
                </dt>
                <dd class="mt-1 text-text">
                  {{ selected.issued_at?.slice(0, 10) ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Due
                </dt>
                <dd class="mt-1 text-text">
                  {{ selected.due_at?.slice(0, 10) ?? '—' }}
                </dd>
              </div>
            </dl>

            <p
              v-if="selected.payment_reference_pending"
              class="rounded-control bg-surface-alt px-3 py-2 text-sm text-text-muted"
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
              class="flex flex-wrap gap-3"
            >
              <RouterLink
                :to="{ name: 'merchant.subscription-invoice-detail', params: { invoiceUlid: selected.id } }"
                class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-border px-4 py-2 text-sm font-semibold text-heading"
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
        </SvCard>
      </div>
    </SvStateBoundary>
  </div>
</template>
