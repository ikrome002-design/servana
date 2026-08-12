<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
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
  <section class="mx-auto max-w-4xl" data-testid="merchant-subscription-invoice-detail">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm text-text-muted"><RouterLink class="sv-focus-ring rounded-control underline" :to="{ name: 'merchant.subscription-invoices' }">Subscription invoices</RouterLink><span aria-hidden="true"> / </span><span>Invoice detail</span></nav>
    <SvPageHeader :title="invoice?.invoice_number ?? 'Subscription invoice'" eyebrow="Subscription & billing" description="An immutable merchant-to-Servana invoice snapshot and its authorized billing document." />
    <SvStateBoundary class="mt-6" :state="state" :error-message="error ?? undefined" empty-message="This invoice is unavailable." @retry="load">
      <SvCard v-if="invoice" as="article">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="font-display text-lg font-bold text-heading">{{ invoice.invoice_number ?? 'Draft invoice' }}</h2><p class="text-sm text-text-muted">{{ invoice.period_start }} → {{ invoice.period_end }}</p></div><span class="rounded-full bg-surface-alt px-3 py-1 text-sm font-semibold text-text">{{ invoice.status }}</span></div>
        <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div><dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Subtotal</dt><dd class="mt-1 text-text">{{ formatMoney(invoice.subtotal_minor, invoice.currency) }}</dd></div>
          <div><dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Discount</dt><dd class="mt-1 text-text">{{ formatMoney(invoice.discount_minor, invoice.currency) }}</dd></div>
          <div><dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Total</dt><dd class="mt-1 font-bold text-heading">{{ formatMoney(invoice.total_minor, invoice.currency) }}</dd></div>
          <div><dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Balance</dt><dd class="mt-1 font-bold text-heading">{{ formatMoney(invoice.balance_minor, invoice.currency) }}</dd></div>
          <div><dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Issued</dt><dd class="mt-1 text-text">{{ invoice.issued_at?.slice(0, 10) ?? '—' }}</dd></div>
          <div><dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">Due</dt><dd class="mt-1 text-text">{{ invoice.due_at?.slice(0, 10) ?? '—' }}</dd></div>
        </dl>
        <p v-if="invoice.payment_reference_pending" class="mt-6 rounded-control bg-surface-alt px-3 py-2 text-sm text-text-muted">{{ PAYMENT_REFERENCE_PENDING_TEXT }}</p>
        <p v-else-if="invoice.account_reference" class="mt-6 text-sm text-text">Account reference: <strong>{{ invoice.account_reference }}</strong></p>
        <div v-if="can('merchant.subscription.invoice.download')" class="mt-6 flex flex-wrap gap-3"><SvButton :loading="working" :disabled="billingReadOnly" @click="generatePdf">{{ invoice.has_pdf ? 'Regenerate PDF' : 'Generate PDF' }}</SvButton><SvButton v-if="invoice.has_pdf" variant="secondary" :loading="working" @click="download">Download PDF</SvButton></div>
        <SvAlert severity="info" title="M-Pesa payment is not available" class="mt-6">External Gate W — Wallet by Citrus collections readiness. This page does not simulate payment initiation, an attempt, or success.</SvAlert>
      </SvCard>
    </SvStateBoundary>
  </section>
</template>
