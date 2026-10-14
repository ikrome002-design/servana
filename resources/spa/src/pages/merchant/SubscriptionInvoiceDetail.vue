<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useSubscriptionInvoiceStore, type SubscriptionInvoice, PAYMENT_REFERENCE_PENDING_TEXT } from '@/stores/subscriptionInvoiceStore';
import { useSubscriptionStore } from '@/stores/subscriptionStore';
import { formatMoney } from '@/utils/money';

const route = useRoute();
const store = useSubscriptionInvoiceStore();
const subscription = useSubscriptionStore();
const { can } = useCan();
const invoice = ref<SubscriptionInvoice | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const working = ref(false);
const invoiceUlid = computed(() => String(route.params.invoiceUlid ?? ''));
const state = computed(() => loading.value ? 'loading' : error.value ? 'error' : invoice.value ? 'success' : 'empty');
const billingReadOnly = computed(() => subscription.subscription?.billing_read_only === true);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    invoice.value = await store.fetchInvoice(invoiceUlid.value);
    await subscription.fetchSubscription();
  } catch {
    error.value = 'We couldn’t load this subscription invoice.';
  } finally {
    loading.value = false;
  }
}
async function generatePdf(): Promise<void> {
  if (!invoice.value || working.value) return;
  working.value = true;
  error.value = null;
  try { invoice.value = await store.generatePdf(invoice.value.id); }
  catch (err) { error.value = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'The PDF could not be generated.'; }
  finally { working.value = false; }
}
async function download(): Promise<void> {
  if (!invoice.value || working.value) return;
  working.value = true;
  try { const { url } = await store.downloadLink(invoice.value.id); window.open(url, '_blank', 'noopener'); }
  catch { error.value = 'The download link could not be issued.'; }
  finally { working.value = false; }
}
onMounted(() => { void load(); });
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the invoice as a legible STATEMENT document: an issuer band (Servana's
    approved logo, the invoice number and status), the billed period, the server's statement lines
    set right-aligned like a printed invoice, then the document actions the viewer's permission
    allows and the truthfully-gated M-Pesa payment. The snapshot is immutable and never recomputed.
  -->
  <section
    class="sv-r-page"
    data-testid="merchant-subscription-invoice-detail"
  >
    <SvPageHeader
      :title="invoice?.invoice_number ?? 'Subscription invoice'"
      eyebrow="Subscription & billing"
      description="An immutable merchant-to-Servana invoice snapshot and its authorized billing document."
    >
      <template #breadcrumbs>
        <nav
          aria-label="Breadcrumb"
          class="mb-3 text-sm text-text-muted"
        >
          <RouterLink
            class="sv-focus-ring rounded-control underline"
            :to="{ name: 'merchant.subscription-invoices' }"
          >
            Subscription invoices
          </RouterLink><span aria-hidden="true"> / </span><span>Invoice detail</span>
        </nav>
      </template>
    </SvPageHeader>
    <SvStateBoundary
      :state="state"
      :error-message="error ?? undefined"
      empty-message="This invoice is unavailable."
      @retry="load"
    >
      <div
        v-if="invoice"
        class="sv-r-grid sv-r-split"
      >
        <article class="sv-r-panel sv-r-panel--flush">
          <!-- Issuer band -->
          <div class="flex flex-wrap items-start justify-between gap-4 border-b border-sv-border bg-sv-surface-warm px-6 py-5">
            <div class="flex items-center gap-3">
              <img
                :src="'/assets/brand/Logo.png'"
                alt=""
                aria-hidden="true"
                class="h-11 w-11 rounded-xl bg-white object-contain p-1 shadow-card"
              >
              <div>
                <h2 class="sv-r-title">
                  {{ invoice.invoice_number ?? 'Draft invoice' }}
                </h2><p class="text-sm text-text-muted">
                  {{ invoice.period_start }} → {{ invoice.period_end }}
                </p>
              </div>
            </div><span class="sv-r-chip sv-r-chip--tone sv-r-chip--dot sv-r-tone-teal">{{ invoice.status }}</span>
          </div>
          <!-- Statement lines -->
          <dl class="px-6 pt-5">
            <div class="flex items-baseline justify-between gap-4 border-b border-dashed border-sv-border py-3">
              <dt class="text-sm text-text-muted">
                Subtotal
              </dt><dd class="sv-numeric text-sm font-semibold text-text">
                {{ formatMoney(invoice.subtotal_minor, invoice.currency) }}
              </dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 border-b border-dashed border-sv-border py-3">
              <dt class="text-sm text-text-muted">
                Discount
              </dt><dd class="sv-numeric text-sm font-semibold text-text">
                {{ formatMoney(invoice.discount_minor, invoice.currency) }}
              </dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 border-b border-sv-border py-3">
              <dt class="text-sm font-semibold text-heading">
                Total
              </dt><dd class="sv-numeric font-bold text-heading">
                {{ formatMoney(invoice.total_minor, invoice.currency) }}
              </dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 py-4">
              <dt class="text-sm font-semibold text-heading">
                Balance
              </dt><dd class="sv-numeric sv-r-figure-md">
                {{ formatMoney(invoice.balance_minor, invoice.currency) }}
              </dd>
            </div>
          </dl>
          <!-- Its own list: a grid nested inside the totals list broke dl > div > dt/dd (axe definition-list). -->
          <dl class="mx-6 mb-5 grid grid-cols-2 gap-3 rounded-card bg-sv-surface-subtle p-4">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Issued
              </dt><dd class="mt-1 text-text">
                {{ invoice.issued_at?.slice(0, 10) ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Due
              </dt><dd class="mt-1 text-text">
                {{ invoice.due_at?.slice(0, 10) ?? '—' }}
              </dd>
            </div>
          </dl>
          <div class="border-t border-sv-border px-6 py-5">
            <p
              v-if="invoice.payment_reference_pending"
              class="rounded-control bg-sv-surface-subtle px-3 py-2 text-sm text-text-muted"
            >
              {{ PAYMENT_REFERENCE_PENDING_TEXT }}
            </p>
            <p
              v-else-if="invoice.account_reference"
              class="text-sm text-text"
            >
              Account reference: <strong>{{ invoice.account_reference }}</strong>
            </p>
          </div>
        </article>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Invoice document and payment"
        >
          <div
            v-if="can('merchant.subscription.invoice.download')"
            class="sv-r-panel"
          >
            <p class="sv-r-eyebrow">
              Billing document
            </p>
            <div class="mt-3 flex flex-col gap-2">
              <SvButton
                block
                :loading="working"
                :disabled="billingReadOnly"
                @click="generatePdf"
              >
                {{ invoice.has_pdf ? 'Regenerate PDF' : 'Generate PDF' }}
              </SvButton><SvButton
                v-if="invoice.has_pdf"
                block
                variant="secondary"
                :loading="working"
                @click="download"
              >
                Download PDF
              </SvButton>
            </div>
          </div>
          <SvAlert
            severity="info"
            title="M-Pesa payment is not available"
          >
            External Gate W — Wallet by Citrus collections readiness. This page does not simulate payment initiation, an attempt, or success.
          </SvAlert>
        </aside>
      </div>
    </SvStateBoundary>
  </section>
</template>