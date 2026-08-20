<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvMoney from '@/components/ui/SvMoney.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import { useFrontOfficeWorkspaceStore } from '@/stores/frontOfficeWorkspaceStore';

const store = useFrontOfficeWorkspaceStore();
const status = ref('');
const options = [
  { value: '', label: 'All recorded payments' },
  { value: 'pending_validation', label: 'Awaiting Finance validation' },
  { value: 'validated', label: 'Validated' },
  { value: 'correction_required', label: 'Correction required' },
  { value: 'rejected', label: 'Rejected' },
];
const state = computed(() => (store.paymentLoading ? 'loading' : store.paymentError ? 'error' : store.paymentStatuses.length ? 'success' : 'empty'));

function load(): void {
  void store.fetchPaymentStatus({ status: status.value || undefined });
}
function humanize(value: string): string {
  return value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}
function tone(value: string): SvStatusTone {
  if (value === 'validated') return 'success';
  if (value === 'rejected') return 'error';
  if (value === 'correction_required') return 'warning';
  return 'info';
}

onMounted(load);
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="front-office-payment-status"
  >
    <SvOperationalHero
      eyebrow="Billing handoff"
      title="Payment and receipt status"
      description="Track what the service desk recorded, what Finance has decided, and whether the automatic original receipt is ready. Recorded never means paid."
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-bold text-brand-deep"
          :to="{ name: 'front-office.invoices' }"
        >
          Open client invoices
        </RouterLink>
      </template>
    </SvOperationalHero>

    <SvCard
      class="mt-5"
      padding="lg"
    >
      <form
        class="flex flex-wrap items-end gap-3"
        @submit.prevent="load"
      >
        <div class="min-w-64 flex-1">
          <SvSelect
            id="payment-status"
            v-model="status"
            label="Finance decision"
            :options="options"
          />
        </div>
        <SvButton type="submit">
          Apply filter
        </SvButton>
      </form>

      <div class="mt-5">
        <SvStateBoundary
          :state="state"
          :error-message="store.paymentError ?? undefined"
          empty-message="No recorded payment groups match this filter."
          @retry="load"
        >
          <ul class="grid gap-3">
            <li
              v-for="payment in store.paymentStatuses"
              :key="payment.id"
            >
              <article class="grid gap-4 rounded-card border border-sv-border bg-sv-surface-subtle p-4 md:grid-cols-[1.2fr_0.8fr_auto] md:items-center">
                <div>
                  <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                    Invoice
                  </p>
                  <h2 class="mt-1 font-display text-lg font-bold text-heading">
                    {{ payment.invoice.number ?? 'Number pending' }}
                  </h2>
                  <p class="mt-1 text-sm text-text-muted">
                    Recorded
                    <time
                      v-if="payment.recorded_at"
                      :datetime="payment.recorded_at"
                    >{{ new Intl.DateTimeFormat('en-KE', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Nairobi' }).format(new Date(payment.recorded_at)) }}</time>
                  </p>
                </div>
                <div>
                  <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                    Recorded evidence
                  </p>
                  <SvMoney
                    class="mt-1 text-lg font-bold text-heading"
                    :amount-minor="payment.total.amount"
                    :currency="payment.total.currency"
                  />
                  <SvStatusBadge
                    class="mt-2"
                    :label="humanize(payment.status)"
                    :tone="tone(payment.status)"
                    sr-prefix="Finance status:"
                  />
                </div>
                <div class="rounded-control border border-sv-border bg-sv-surface-raised p-3 md:min-w-48">
                  <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                    Original receipt
                  </p>
                  <p
                    v-if="payment.receipt.ready"
                    class="mt-1 font-semibold text-sv-success-fg"
                  >
                    Ready · #{{ payment.receipt.number }}
                  </p>
                  <p
                    v-else
                    class="mt-1 text-sm font-medium text-heading"
                  >
                    Not available yet
                  </p>
                  <p class="mt-1 text-xs text-text-muted">
                    {{ payment.receipt.ready ? 'Issued automatically after validation.' : 'No manual issue control exists.' }}
                  </p>
                </div>
              </article>
            </li>
          </ul>
        </SvStateBoundary>
      </div>
    </SvCard>
  </section>
</template>
