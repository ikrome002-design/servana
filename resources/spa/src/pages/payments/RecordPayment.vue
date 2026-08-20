<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useInvoiceStore } from '@/stores/invoiceStore';
import {
  PAYMENT_METHODS,
  REFERENCE_METHODS,
  usePaymentStore,
  type PaymentComponentInput,
  type PaymentRecordingGroupView,
} from '@/stores/paymentStore';
import type { Invoice } from '@/types/models';
import SvMoney from '@/components/ui/SvMoney.vue';

/**
 * Front Office payment recording (Plan §41; Phase 18A). Records a single or
 * split/multi-method payment against an issued/partially-paid invoice. The maker
 * sees the invoice total, validated amount and the amount available to record;
 * builds one or more concrete-method components with method-aware evidence fields;
 * confirms; and submits with an idempotency key. Recording is NOT validation and
 * creates NO receipt — the success state says "pending validation". A suspected
 * duplicate shows a warning; only Finance may override. The server is the security
 * boundary; this form is UX only.
 */
interface ComponentRow {
  method: string;
  amount: string;
  reference: string;
}

const route = useRoute();
const router = useRouter();
const invoiceStore = useInvoiceStore();
const paymentStore = usePaymentStore();

const invoiceId = computed(() => String(route.params.invoiceUlid ?? route.params.id));
const invoice = ref<Invoice | null>(null);
const loadError = ref(false);
const submitting = ref(false);
const submitError = ref<string | null>(null);
const recorded = ref<PaymentRecordingGroupView | null>(null);
const confirming = ref(false);

const rows = reactive<ComponentRow[]>([{ method: 'cash', amount: '', reference: '' }]);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (invoice.value === null && !loadError.value) return 'loading';
  if (loadError.value) return 'error';
  return 'success';
});

/**
 * The amount still recordable, in minor units, or null when the payload carried no balance.
 *
 * NOT defaulted to 0: zero means "fully paid" and would silently forbid recording a legitimate
 * payment. Unknown is a different fact and the UI must say so (UI01-RENDER-001).
 */
const availableMinor = computed<number | null>(() => invoice.value?.balance?.amount ?? null);

const groupTotalMinor = computed(() =>
  rows.reduce((sum, r) => sum + toMinor(r.amount), 0),
);

const formattedGroupTotal = computed(() => formatKes(groupTotalMinor.value, invoice.value?.currency ?? 'KES'));

/**
 * True only when the balance is KNOWN and the entered total exceeds it.
 *
 * An unknown balance is not "not over" — it is simply unknown, and claiming otherwise would let
 * the form present an over-payment as acceptable.
 */
const overAvailable = computed(
  () => availableMinor.value !== null && groupTotalMinor.value > availableMinor.value,
);

/** The balance did not arrive. Recording against an unknown balance is refused, fail-safe. */
const balanceUnknown = computed(() => invoice.value !== null && availableMinor.value === null);

const canSubmit = computed(
  () =>
    invoice.value !== null &&
    rows.length > 0 &&
    rows.every((r) => toMinor(r.amount) > 0 && (!requiresReference(r.method) || r.reference.trim() !== '')) &&
    !overAvailable.value &&
    // Fail-safe: never record a payment when the amount owed is unknown (UI01-RENDER-001).
    !balanceUnknown.value &&
    !submitting.value,
);

function requiresReference(method: string): boolean {
  return REFERENCE_METHODS.includes(method);
}

function toMinor(value: string): number {
  const n = Math.round(parseFloat(value) * 100);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

function formatKes(minor: number, currency: string): string {
  return `${currency} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function addComponent(): void {
  rows.push({ method: 'cash', amount: '', reference: '' });
}

function removeComponent(index: number): void {
  if (rows.length > 1) rows.splice(index, 1);
}

function openConfirm(): void {
  submitError.value = null;
  if (canSubmit.value) confirming.value = true;
}

async function submit(): Promise<void> {
  if (invoice.value === null) return;
  submitting.value = true;
  submitError.value = null;
  confirming.value = false;
  const components: PaymentComponentInput[] = rows.map((r) => ({
    method: r.method,
    amount_minor: toMinor(r.amount),
    ...(requiresReference(r.method) || r.reference.trim() !== '' ? { reference: r.reference.trim() } : {}),
  }));
  try {
    recorded.value = await paymentStore.recordPayment(invoiceId.value, components);
  } catch (e: unknown) {
    if (paymentStore.duplicate !== null) {
      submitError.value = null; // duplicate warning shown separately
    } else {
      const err = e as { response?: { data?: { error?: { message?: string } } } };
      submitError.value = err.response?.data?.error?.message ?? 'We could not record this payment.';
    }
  } finally {
    submitting.value = false;
  }
}

function goToInvoice(): void {
  void router.push({ name: 'front-office.invoice-detail', params: { invoiceUlid: invoiceId.value } });
}

onMounted(async () => {
  try {
    invoice.value = await invoiceStore.fetchInvoice(invoiceId.value);
  } catch {
    loadError.value = true;
  }
});
</script>

<template>
  <section class="mx-auto max-w-6xl">
    <SvOperationalHero
      eyebrow="Maker workflow"
      title="Record a payment"
      description="Capture offline payment evidence against this invoice. The result is recorded and awaiting Finance—it is never validation, settlement or receipt issuance."
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold text-white"
          :to="{ name: 'front-office.invoices' }"
        >
          Back to invoices
        </RouterLink>
      </template>
      <div class="grid gap-2 md:grid-cols-4">
        <div class="rounded-control bg-white/10 p-3 text-sm">
          <strong class="block">1 · Evidence</strong><span class="text-white/70">Choose method</span>
        </div>
        <div class="rounded-control bg-white/10 p-3 text-sm">
          <strong class="block">2 · Allocation</strong><span class="text-white/70">Respect balance</span>
        </div>
        <div class="rounded-control bg-white/10 p-3 text-sm">
          <strong class="block">3 · Record</strong><span class="text-white/70">Idempotent submit</span>
        </div>
        <div class="rounded-control bg-white/10 p-3 text-sm">
          <strong class="block">4 · Finance</strong><span class="text-white/70">Checker decides</span>
        </div>
      </div>
    </SvOperationalHero>

    <div class="mt-5">
      <SvStateBoundary
        :state="boundaryState"
        error-message="We couldn’t load this invoice."
        empty-message="Invoice not found."
      >
        <!-- Success: pending validation, no receipt -->
        <SvCard
          v-if="recorded"
          as="section"
          padding="md"
          data-testid="record-success"
          class="border-l-4 border-l-sv-success-border"
        >
          <h2 class="font-display text-lg font-semibold text-heading">
            Payment recorded — pending validation
          </h2>
          <p class="mt-1 text-sm text-text-muted">
            <SvMoney :formatted="recorded.total?.formatted ?? null" /> recorded against invoice
            {{ invoice?.invoice_number }}. Finance must validate it before any receipt is issued.
            This is not a validation and no receipt has been created.
          </p>
          <div class="mt-4 flex gap-2">
            <SvButton
              variant="secondary"
              @click="goToInvoice"
            >
              Back to invoice
            </SvButton>
          </div>
        </SvCard>

        <!-- Duplicate-suspected warning -->
        <SvCard
          v-else-if="paymentStore.duplicate"
          as="section"
          padding="md"
          role="alert"
          data-testid="duplicate-warning"
          class="border-l-4 border-l-sv-warning-border"
        >
          <h2 class="font-display text-lg font-semibold text-heading">
            Duplicate reference needs Finance review
          </h2>
          <p class="mt-1 text-sm text-text-muted">
            The reference ending {{ paymentStore.duplicate.masked_reference ?? '—' }}
            ({{ paymentStore.duplicate.method }}) matches an existing payment. It has been held and
            cannot proceed until Finance reviews and overrides it.
          </p>
          <div class="mt-4">
            <SvButton
              variant="secondary"
              @click="goToInvoice"
            >
              Back to invoice
            </SvButton>
          </div>
        </SvCard>

        <template v-else>
          <!-- Invoice + balance context -->
          <SvCard
            as="section"
            padding="md"
            class="mt-2"
          >
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 md:grid-cols-4">
              <div>
                <dt class="text-xs text-text-muted">
                  Invoice
                </dt>
                <dd class="font-display font-semibold text-heading">
                  {{ invoice?.invoice_number }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Client
                </dt>
                <dd class="font-semibold text-heading">
                  {{ invoice?.client?.phone_masked ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Invoice total
                </dt>
                <dd class="font-semibold text-heading">
                  <SvMoney :formatted="invoice?.total?.formatted ?? null" />
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Available to record
                </dt>
                <dd
                  class="font-semibold text-heading"
                  data-testid="available-amount"
                >
                  <SvMoney
                    :formatted="invoice?.balance?.formatted ?? null"
                    :minor-units="invoice?.balance?.amount ?? null"
                  />
                </dd>
              </div>
            </dl>
          </SvCard>

          <form
            class="mt-4 flex flex-col gap-4"
            novalidate
            @submit.prevent="openConfirm"
          >
            <fieldset
              v-for="(row, index) in rows"
              :key="index"
              class="rounded-lg border border-border p-4"
            >
              <legend class="px-1 text-sm font-semibold text-heading">
                Payment {{ index + 1 }}
              </legend>
              <div class="grid gap-3 md:grid-cols-2">
                <SvSelect
                  :id="`method-${index}`"
                  v-model="row.method"
                  label="Method"
                  :options="PAYMENT_METHODS"
                />
                <SvTextInput
                  :id="`amount-${index}`"
                  v-model="row.amount"
                  label="Amount (KES)"
                  type="number"
                />
                <SvTextInput
                  v-if="requiresReference(row.method)"
                  :id="`reference-${index}`"
                  v-model="row.reference"
                  label="Reference / evidence"
                  class="md:col-span-2"
                />
              </div>
              <div
                v-if="rows.length > 1"
                class="mt-3"
              >
                <SvButton
                  type="button"
                  variant="ghost"
                  :aria-label="`Remove payment ${index + 1}`"
                  @click="removeComponent(index)"
                >
                  Remove
                </SvButton>
              </div>
            </fieldset>

            <div class="flex flex-wrap items-center justify-between gap-3">
              <SvButton
                type="button"
                variant="secondary"
                data-testid="add-component"
                @click="addComponent"
              >
                Add another payment
              </SvButton>
              <p
                class="font-display text-base font-semibold text-heading"
                data-testid="group-total"
              >
                Total: {{ formattedGroupTotal }}
              </p>
            </div>

            <p
              v-if="overAvailable"
              class="text-sm text-sv-error-fg"
              role="alert"
            >
              The total exceeds the amount available to record on this invoice.
            </p>
            <p
              v-if="balanceUnknown"
              class="text-sm text-sv-error-fg"
              role="alert"
              data-testid="balance-unknown"
            >
              We couldn’t read the amount outstanding on this invoice, so a payment can’t be recorded
              yet. Reload the page and try again.
            </p>
            <p
              v-if="submitError"
              class="text-sm text-sv-error-fg"
              role="alert"
            >
              {{ submitError }}
            </p>

            <div>
              <SvButton
                type="submit"
                data-testid="review-payment"
                :disabled="!canSubmit"
              >
                Review and record
              </SvButton>
            </div>
          </form>
        </template>
      </SvStateBoundary>
    </div>

    <!-- Confirmation modal -->
    <SvDialog
      :open="confirming"
      title="Confirm payment recording"
      description="This creates a pending recording for Finance to validate — it is not a validation and issues no receipt."
      @close="confirming = false"
    >
      <p class="mt-2 text-sm text-text-muted">
        Record {{ formattedGroupTotal }} across {{ rows.length }} payment(s) against invoice
        {{ invoice?.invoice_number }}?
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="confirming = false"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="confirm-record"
          :loading="submitting"
          @click="submit"
        >
          Record payment
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
