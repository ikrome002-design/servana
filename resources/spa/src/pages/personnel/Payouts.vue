<script setup lang="ts">
import { computed, onMounted } from 'vue';
import PersonnelEarningsNav from '@/components/personnel/PersonnelEarningsNav.vue';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconInfo } from '@/design-system/icons';
import { usePersonnelEarningsStore } from '@/stores/personnelEarningsStore';
import { formatMoney } from '@/utils/money';
import { humanizeStatus, nairobiDate, personnelStatusTone } from '@/utils/personnelStatus';

// Own payout items only. Servana does not transfer Personnel pay: Finance records that the
// employer paid externally. No verify, approve or mark-paid control exists here, and the external
// reference is the server's masked last four characters.
const store = usePersonnelEarningsStore();
const state = computed(() => store.payoutsLoading ? 'loading' : store.payoutsError ? 'error' : store.payouts.length ? 'success' : 'empty');
const month = (value: string | null | undefined) => value ? new Intl.DateTimeFormat('en-KE', { month: 'short', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)) : '—';
onMounted(() => { void store.fetchPayouts(1); });
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="personnel-payouts"
  >
    <SvTonalPageHeader
      title="Payouts"
      eyebrow="My earnings"
      tone="green"
      description="Payments Finance recorded after your employer paid you outside Servana. Servana keeps the evidence; it does not move these funds."
    />
    <PersonnelEarningsNav />
    <p class="mb-5 flex items-start gap-2 rounded-card border border-sv-info-border bg-sv-info-bg p-4 text-sm text-sv-info-fg">
      <SvIconInfo
        aria-hidden="true"
        class="mt-0.5 h-5 w-5 shrink-0"
      />
      A payout shows as paid only when Finance records the external payment. If a period looks wrong, ask Finance from the payout below.
    </p>
    <SvStateBoundary
      :state="state"
      :error-message="store.payoutsError ?? undefined"
      empty-message="No payout items have been recorded yet."
      @retry="store.fetchPayouts(1)"
    >
      <ul
        class="grid gap-4 lg:grid-cols-2"
        aria-label="Payout items"
      >
        <li
          v-for="item in store.payouts"
          :key="item.id"
        >
          <SvLeadRecord
            :lead-value="month(item.period_start)"
            :lead-label="item.period_start ? item.period_start.slice(0, 4) : 'Period'"
            :tone="item.status === 'paid' ? 'green' : 'sun'"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
              {{ nairobiDate(item.period_start) }} – {{ nairobiDate(item.period_end) }}
            </p>
            <p class="mt-1 font-display text-2xl font-extrabold text-sv-text-heading">
              {{ formatMoney(item.gross_amount_minor, item.currency) }}
            </p>
            <p class="text-xs text-sv-text-muted">
              Gross for the period
            </p>
            <template #status>
              <SvStatusBadge
                size="sm"
                :label="humanizeStatus(item.status)"
                :tone="personnelStatusTone(item.status)"
              />
            </template>
            <template #meta>
              <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
                <div>
                  <dt class="text-xs text-sv-text-muted">
                    Salary
                  </dt>
                  <dd class="sv-numeric font-medium text-sv-text">
                    {{ formatMoney(item.salary_amount_minor, item.currency) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-sv-text-muted">
                    Commission
                  </dt>
                  <dd class="sv-numeric font-medium text-sv-text">
                    {{ formatMoney(item.commission_amount_minor, item.currency) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-sv-text-muted">
                    Adjustments
                  </dt>
                  <dd class="sv-numeric font-medium text-sv-text">
                    {{ formatMoney(item.adjustment_amount_minor, item.currency) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-sv-text-muted">
                    External reference
                  </dt>
                  <dd class="font-medium text-sv-text">
                    {{ item.external_reference_masked ?? 'Not recorded' }}
                  </dd>
                </div>
              </dl>
              <p class="mt-3 text-xs text-sv-text-muted">
                {{ item.paid_at ? `Finance recorded payment on ${nairobiDate(item.paid_at, true)}.` : 'Not yet recorded as externally paid.' }}
              </p>
            </template>
            <template #actions>
              <RouterLink
                v-if="item.status === 'paid'"
                :to="{ name: 'personnel.earnings-statements' }"
                class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
              >
                Statement
              </RouterLink>
              <RouterLink
                :to="{ name: 'personnel.earnings-queries', query: { subject_type: 'payout_item', subject: item.id } }"
                class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
              >
                Ask about this payout
              </RouterLink>
            </template>
          </SvLeadRecord>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
