<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PersonnelEarningsNav from '@/components/personnel/PersonnelEarningsNav.vue';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { usePersonnelEarningsStore } from '@/stores/personnelEarningsStore';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';
import { formatMoney } from '@/utils/money';
import { humanizeStatus, nairobiDate, personnelStatusTone } from '@/utils/personnelStatus';

// Own append-only salary ledger (UI-14 read seam). Each row is a server-recorded accrual or
// adjustment with its own status; the browser never estimates or edits salary. Corrections appear
// as new adjustment rows, and HR/Finance internal notes are never part of this payload.
const experience = usePersonnelExperienceStore();
const earnings = usePersonnelEarningsStore();
const page = ref(1);
const applicable = computed(() => earnings.overview.tab_visibility.salary_tab);
const meta = computed(() => experience.meta.salary);
const state = computed(() => experience.loading.salary || earnings.overviewLoading ? 'loading' : experience.errors.salary ? 'error' : !applicable.value || experience.salary.length === 0 ? 'empty' : 'success');
const month = (value: string) => new Intl.DateTimeFormat('en-KE', { month: 'short', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`));
const year = (value: string) => value.slice(0, 4);
function goTo(next: number): void { page.value = next; void experience.fetchSalary({ page: next }); }
onMounted(async () => { await earnings.fetchOverview(); if (earnings.overview.tab_visibility.salary_tab) await experience.fetchSalary(); });
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="personnel-salary"
  >
    <SvTonalPageHeader
      title="Salary"
      eyebrow="My earnings"
      tone="green"
      description="Your own salary accruals and their status. Servana records these facts; your employer makes the payment outside Servana."
    />
    <PersonnelEarningsNav />
    <SvStateBoundary
      :state="state"
      :error-message="experience.errors.salary ?? undefined"
      :empty-message="applicable ? 'No salary entries have been recorded yet.' : earnings.overview.tab_visibility.has_current_plan ? 'Salary does not apply to your current compensation model.' : 'No current compensation plan is on file. Ask Human Resource for help.'"
      @retry="experience.fetchSalary()"
    >
      <div class="grid gap-6 lg:grid-cols-[1.35fr_0.65fr]">
        <ul
          class="flex flex-col gap-3"
          aria-label="Salary entries"
        >
          <li
            v-for="entry in experience.salary"
            :key="entry.id"
          >
            <SvLeadRecord
              :lead-value="month(entry.pay_period_start)"
              :lead-label="year(entry.pay_period_start)"
              :tone="entry.status === 'paid' ? 'green' : entry.status === 'reversed' ? 'clay' : 'sun'"
            >
              <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                {{ humanizeStatus(entry.entry_type) }}
              </p>
              <p class="mt-1 font-display text-base font-bold text-sv-text-heading">
                {{ nairobiDate(entry.pay_period_start) }} – {{ nairobiDate(entry.pay_period_end) }}
              </p>
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
                Recorded {{ nairobiDate(entry.created_at) }}
              </template>
              <template #actions>
                <RouterLink
                  :to="{ name: 'personnel.earnings-queries', query: { subject_type: 'salary_ledger', subject: entry.id } }"
                  class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
                >
                  Ask about this period
                </RouterLink>
              </template>
            </SvLeadRecord>
          </li>
        </ul>
        <aside class="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
          <div class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
            <h2 class="font-display text-base font-bold text-sv-text-heading">
              Reading your salary
            </h2>
            <ul class="mt-3 flex flex-col gap-3 text-sm text-sv-text-secondary">
              <li><strong class="text-sv-text-heading">Accrued / due</strong> — recorded for the period, not yet paid.</li>
              <li><strong class="text-sv-text-heading">Paid</strong> — Finance recorded that your employer paid it.</li>
              <li><strong class="text-sv-text-heading">Adjustment</strong> — a separate correction; original rows never change.</li>
            </ul>
          </div>
          <RouterLink
            :to="{ name: 'personnel.earnings-terms' }"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center justify-center rounded-control border border-sv-border-input bg-sv-surface-raised px-4 text-sm font-semibold text-sv-text hover:bg-sv-surface-subtle"
          >
            View my salary terms
          </RouterLink>
        </aside>
      </div>
      <SvPagination
        v-if="meta && meta.last_page > 1"
        class="mt-6"
        :current-page="meta.current_page"
        :last-page="meta.last_page"
        :total="meta.total"
        :per-page="meta.per_page"
        label="Salary pages"
        @change="goTo"
      />
    </SvStateBoundary>
  </section>
</template>
