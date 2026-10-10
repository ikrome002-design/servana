<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PersonnelEarningsNav from '@/components/personnel/PersonnelEarningsNav.vue';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { usePersonnelEarningsStore } from '@/stores/personnelEarningsStore';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';
import { formatMoney } from '@/utils/money';
import { humanizeStatus, nairobiDate, personnelStatusTone } from '@/utils/personnelStatus';

// Own append-only commission ledger (UI-14 read seam). Amounts, basis and rate are frozen by the
// server when Finance validated the payment; the browser never calculates a commission figure and
// offers no edit, reversal or approval control. Queries go to Finance as a separate record.
const experience = usePersonnelExperienceStore();
const earnings = usePersonnelEarningsStore();
const page = ref(1);
const applicable = computed(() => earnings.overview.tab_visibility.commission_tab);
const meta = computed(() => experience.meta.commissions);
const state = computed(() => experience.loading.commissions || earnings.overviewLoading ? 'loading' : experience.errors.commissions ? 'error' : !applicable.value || experience.commissions.length === 0 ? 'empty' : 'success');
const day = (value: string | null) => value ? new Intl.DateTimeFormat('en-KE', { day: '2-digit', month: 'short', timeZone: 'Africa/Nairobi' }).format(new Date(value)) : '—';
function rate(entry: typeof experience.commissions[number]): string {
  if (entry.rate_basis_points != null) return `${entry.rate_basis_points / 100}% of basis`;
  if (entry.fixed_rate_minor != null) return `${formatMoney(entry.fixed_rate_minor, entry.currency)} fixed`;
  return 'Rule recorded by server';
}
function goTo(next: number): void { page.value = next; void experience.fetchCommissions({ page: next }); }
onMounted(async () => { await earnings.fetchOverview(); if (earnings.overview.tab_visibility.commission_tab) await experience.fetchCommissions(); });
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="personnel-commission"
  >
    <SvTonalPageHeader
      title="Commission"
      eyebrow="My earnings"
      tone="green"
      description="Commission facts frozen by Servana when Finance validated each client payment. The browser never calculates an authoritative commission amount."
    />
    <PersonnelEarningsNav />
    <SvStateBoundary
      :state="state"
      :error-message="experience.errors.commissions ?? undefined"
      :empty-message="applicable ? 'No commission entries have been recorded yet.' : earnings.overview.tab_visibility.has_current_plan ? 'Commission does not apply to your current compensation model.' : 'No current compensation plan is on file. Ask Human Resource for help.'"
      @retry="experience.fetchCommissions()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="Commission entries"
      >
        <li
          v-for="entry in experience.commissions"
          :key="entry.id"
        >
          <SvLeadRecord
            :lead-value="day(entry.earned_at ?? entry.created_at)"
            :lead-label="entry.earned_at ? 'Earned' : 'Recorded'"
            :tone="entry.status === 'reversed' || entry.status === 'cancelled' ? 'clay' : entry.status === 'paid' ? 'green' : 'sun'"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
              {{ humanizeStatus(entry.entry_type) }}
            </p>
            <p class="mt-1 font-display text-base font-bold text-sv-text-heading">
              {{ entry.service?.name ?? 'Commission entry' }}
            </p>
            <div class="mt-2">
              <SvMaskedIdentity
                size="sm"
                :name="entry.client?.full_name"
                :phone-masked="entry.client?.phone_masked"
              />
            </div>
            <template #status>
              <div class="flex flex-col items-end gap-2">
                <p class="font-display text-xl font-extrabold text-sv-text-heading">
                  {{ formatMoney(entry.amount_minor, entry.currency) }}
                </p>
                <SvStatusBadge
                  size="sm"
                  :label="humanizeStatus(entry.status)"
                  :tone="personnelStatusTone(entry.status)"
                />
              </div>
            </template>
            <template #meta>
              <dl class="grid grid-cols-1 gap-x-4 gap-y-2 md:grid-cols-4">
                <div>
                  <dt class="text-xs text-sv-text-muted">
                    Calculation basis
                  </dt>
                  <dd class="sv-numeric font-medium text-sv-text">
                    {{ formatMoney(entry.calculation_basis_minor, entry.currency) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-sv-text-muted">
                    Rate
                  </dt>
                  <dd class="font-medium text-sv-text">
                    {{ rate(entry) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-sv-text-muted">
                    Invoice
                  </dt>
                  <dd class="font-medium text-sv-text">
                    {{ entry.invoice?.number ?? '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-sv-text-muted">
                    Earned after validation
                  </dt>
                  <dd class="font-medium text-sv-text">
                    {{ entry.earned_at ? nairobiDate(entry.earned_at) : 'Not earned' }}
                  </dd>
                </div>
              </dl>
              <p
                v-if="entry.reversal_reason"
                class="mt-3 rounded-control bg-sv-error-bg p-3 text-sm text-sv-error-fg"
              >
                <strong>Reversed:</strong> {{ entry.reversal_reason }}
              </p>
            </template>
            <template #actions>
              <RouterLink
                :to="{ name: 'personnel.earnings-queries', query: { subject_type: 'commission_ledger', subject: entry.id } }"
                class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
              >
                Ask Finance about this entry
              </RouterLink>
            </template>
          </SvLeadRecord>
        </li>
      </ul>
      <SvPagination
        v-if="meta && meta.last_page > 1"
        class="mt-6"
        :current-page="meta.current_page"
        :last-page="meta.last_page"
        :total="meta.total"
        :per-page="meta.per_page"
        label="Commission pages"
        @change="goTo"
      />
    </SvStateBoundary>
  </section>
</template>
