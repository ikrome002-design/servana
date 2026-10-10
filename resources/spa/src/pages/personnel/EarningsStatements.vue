<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PersonnelEarningsNav from '@/components/personnel/PersonnelEarningsNav.vue';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconDocument, SvIconLocked } from '@/design-system/icons';
import { usePersonnelEarningsStore } from '@/stores/personnelEarningsStore';
import { formatMoney } from '@/utils/money';
import { nairobiDate } from '@/utils/personnelStatus';

// Own statements from payout items Finance recorded as paid. Generation is idempotent on the
// server; the download is a short-lived signed URL with no storage path, opened in a new tab.
const store = usePersonnelEarningsStore();
const message = ref('');
const failed = ref(false);
const eligible = computed(() => store.payouts.filter((item) => item.status === 'paid'));
const waiting = computed(() => store.payouts.length - eligible.value.length);
const state = computed(() => store.payoutsLoading ? 'loading' : store.payoutsError ? 'error' : eligible.value.length ? 'success' : 'empty');
const month = (value: string | null | undefined) => value ? new Intl.DateTimeFormat('en-KE', { month: 'short', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)) : '—';
async function download(id: string): Promise<void> {
  message.value = '';
  failed.value = false;
  try {
    const result = await store.generateStatement(id);
    window.open(result.download.url, '_blank', 'noopener');
    message.value = `Your private statement is ready in a new tab. The link expires ${nairobiDate(result.download.expires_at, true)}.`;
  } catch {
    failed.value = true;
    message.value = 'The statement could not be generated. Only paid payout items are eligible.';
  }
}
onMounted(() => { void store.fetchPayouts(1); });
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="personnel-earnings-statements"
  >
    <SvTonalPageHeader
      title="Earnings statements"
      eyebrow="My earnings"
      tone="green"
      description="Generate a private PDF for any payout Finance has recorded as paid. Download links expire and never reveal where the file is stored."
    />
    <PersonnelEarningsNav />
    <p
      role="status"
      class="mb-4 empty:hidden rounded-control p-3 text-sm font-semibold"
      :class="failed ? 'bg-sv-error-bg text-sv-error-fg' : 'bg-sv-success-bg text-sv-success-fg'"
    >
      {{ message }}
    </p>
    <div class="grid gap-6 lg:grid-cols-[1.3fr_0.7fr]">
      <SvStateBoundary
        :state="state"
        :error-message="store.payoutsError ?? undefined"
        empty-message="No paid payout item is ready for a statement."
        @retry="store.fetchPayouts(1)"
      >
        <ul
          class="flex flex-col gap-3"
          aria-label="Statement-ready payouts"
        >
          <li
            v-for="item in eligible"
            :key="item.id"
          >
            <SvLeadRecord
              :lead-value="month(item.period_start)"
              :lead-label="item.period_start ? item.period_start.slice(0, 4) : 'Period'"
              tone="green"
            >
              <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                {{ nairobiDate(item.period_start) }} – {{ nairobiDate(item.period_end) }}
              </p>
              <p class="mt-1 font-display text-xl font-extrabold text-sv-text-heading">
                {{ formatMoney(item.gross_amount_minor, item.currency) }}
              </p>
              <template #status>
                <SvStatusBadge
                  size="sm"
                  :label="item.has_statement ? 'Statement ready' : 'Paid'"
                  tone="success"
                />
              </template>
              <template #meta>
                Reference {{ item.external_reference_masked ?? 'not recorded' }}
              </template>
              <template #actions>
                <button
                  type="button"
                  :disabled="store.generating"
                  class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-2 rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover disabled:opacity-60"
                  @click="download(item.id)"
                >
                  <SvIconDocument
                    aria-hidden="true"
                    class="h-4 w-4"
                  />{{ item.has_statement ? 'Download statement' : 'Generate statement' }}
                </button>
              </template>
            </SvLeadRecord>
          </li>
        </ul>
      </SvStateBoundary>
      <aside class="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
        <div class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
          <p class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
            <SvIconLocked
              aria-hidden="true"
              class="h-5 w-5"
            />Private by design
          </p>
          <ul class="mt-3 flex flex-col gap-2 text-sm text-sv-text-secondary">
            <li>Only your own paid payouts can produce a statement.</li>
            <li>Each download link is signed and expires shortly.</li>
            <li>Statements contain no client contacts and no other team member’s pay.</li>
          </ul>
        </div>
        <div
          v-if="waiting > 0"
          class="rounded-card border border-sv-warning-border bg-sv-warning-bg p-5 text-sv-warning-fg"
        >
          <p class="font-semibold">
            {{ waiting }} {{ waiting === 1 ? 'payout is' : 'payouts are' }} not yet paid
          </p>
          <p class="mt-1 text-sm">
            A statement becomes available once Finance records the payment.
          </p>
        </div>
      </aside>
    </div>
  </section>
</template>
