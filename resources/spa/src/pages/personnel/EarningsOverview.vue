<script setup lang="ts">
import { computed, onMounted } from 'vue';
import PersonnelEarningsNav from '@/components/personnel/PersonnelEarningsNav.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconDocument, SvIconInfo, SvIconSecurity } from '@/design-system/icons';
import { usePersonnelEarningsStore } from '@/stores/personnelEarningsStore';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';
import { formatMoney } from '@/utils/money';
import { compensationModelLabel, humanizeStatus, nairobiDate, personnelStatusTone } from '@/utils/personnelStatus';

/*
 * My Earnings — overview (UI-14). A personal earnings statement, not a wallet.
 *
 * Every amount is a server-owned integer in minor units, grouped by currency and never combined
 * or re-added here: the component rows show the server's outstanding and paid figures side by
 * side, and the totals are the server's own `unpaid_minor`, `paid_minor` and `net_minor`.
 * "Outstanding" means recorded by Servana but not yet recorded as paid by Finance; commission is
 * only recorded once Finance validates the client payment, so nothing here is a pending estimate.
 */
const store = usePersonnelEarningsStore();
const experience = usePersonnelExperienceStore();
const state = computed(() => store.overviewLoading ? 'loading' : store.overviewError ? 'error' : store.overview.currencies.length ? 'success' : 'empty');
const visibility = computed(() => store.overview.tab_visibility);
const latestPayout = computed(() => experience.workspace?.earnings.latest_payout ?? null);
const unresolved = computed(() => experience.workspace?.earnings.unresolved_queries ?? null);
onMounted(() => {
  void store.fetchOverview();
  void experience.fetchWorkspace();
});
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="personnel-earnings-overview"
  >
    <SvTonalPageHeader
      title="My earnings"
      eyebrow="Private compensation"
      tone="green"
      :context="compensationModelLabel(visibility.model)"
      description="Salary, commission and adjustments Servana has recorded for you alone. Amounts stay grouped by currency, and Servana does not hold or move payout funds."
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'personnel.earnings-queries' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center justify-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          Ask about my pay
        </RouterLink>
      </template>
    </SvTonalPageHeader>
    <PersonnelEarningsNav />
    <SvStateBoundary
      :state="state"
      :error-message="store.overviewError ?? undefined"
      empty-message="No earnings have been recorded yet."
      @retry="store.fetchOverview()"
    >
      <div class="grid gap-6 lg:grid-cols-[1.4fr_0.6fr]">
        <div class="flex flex-col gap-6">
          <article
            v-for="row in store.overview.currencies"
            :key="row.currency"
            class="sv-earnings-statement overflow-hidden rounded-card border border-sv-border shadow-raised"
            :aria-label="`${row.currency} earnings`"
            data-testid="earnings-currency-card"
          >
            <div class="flex flex-col gap-5 p-5 md:p-7">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
                  {{ row.currency }} · recorded to date
                </p>
                <p class="mt-2 font-display text-4xl font-extrabold tracking-tight text-sv-text-heading">
                  {{ formatMoney(row.net_minor, row.currency) }}
                </p>
                <p class="mt-1 text-sm text-sv-text-secondary">
                  Everything Servana has recorded for you, paid and outstanding.
                </p>
              </div>
              <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <SvStatTile
                  label="Outstanding"
                  tone="sun"
                  hint="Recorded, not yet paid"
                >
                  {{ formatMoney(row.unpaid_minor, row.currency) }}
                </SvStatTile>
                <SvStatTile
                  label="Recorded paid"
                  tone="green"
                  hint="Finance recorded payment"
                >
                  {{ formatMoney(row.paid_minor, row.currency) }}
                </SvStatTile>
              </div>
            </div>
            <table class="w-full table-fixed border-t border-sv-border text-xs md:text-sm">
              <caption class="sr-only">
                {{ row.currency }} earnings by component
              </caption>
              <thead class="bg-sv-table-header text-left text-xs uppercase tracking-wide text-sv-text-muted">
                <tr>
                  <th
                    scope="col"
                    class="px-3 py-3 font-semibold md:px-7"
                  >
                    Component
                  </th>
                  <th
                    scope="col"
                    class="px-3 py-3 text-right font-semibold"
                  >
                    Outstanding
                  </th>
                  <th
                    scope="col"
                    class="px-3 py-3 text-right font-semibold md:px-7"
                  >
                    Paid
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-sv-border bg-sv-surface-raised">
                <tr v-if="visibility.salary_tab">
                  <th
                    scope="row"
                    class="px-3 py-3 text-left font-semibold text-sv-text-heading md:px-7"
                  >
                    Salary
                  </th>
                  <td class="sv-numeric px-3 py-3 text-right">
                    {{ formatMoney(row.salary_unpaid_minor, row.currency) }}
                  </td>
                  <td class="sv-numeric px-3 py-3 text-right md:px-7">
                    {{ formatMoney(row.salary_paid_minor, row.currency) }}
                  </td>
                </tr>
                <tr v-if="visibility.commission_tab">
                  <th
                    scope="row"
                    class="px-3 py-3 text-left font-semibold text-sv-text-heading md:px-7"
                  >
                    Commission
                  </th>
                  <td class="sv-numeric px-3 py-3 text-right">
                    {{ formatMoney(row.commission_unpaid_minor, row.currency) }}
                  </td>
                  <td class="sv-numeric px-3 py-3 text-right md:px-7">
                    {{ formatMoney(row.commission_paid_minor, row.currency) }}
                  </td>
                </tr>
                <tr>
                  <th
                    scope="row"
                    class="px-3 py-3 text-left font-semibold text-sv-text-heading md:px-7"
                  >
                    Adjustments
                  </th>
                  <td class="sv-numeric px-3 py-3 text-right">
                    {{ formatMoney(row.adjustment_unpaid_minor, row.currency) }}
                  </td>
                  <td class="sv-numeric px-3 py-3 text-right md:px-7">
                    {{ formatMoney(row.adjustment_paid_minor, row.currency) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </article>

          <section
            aria-labelledby="pay-lifecycle-heading"
            class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card md:p-6"
          >
            <h2
              id="pay-lifecycle-heading"
              class="font-display text-lg font-bold text-sv-text-heading"
            >
              How your pay moves
            </h2>
            <ol class="mt-4 grid gap-3 md:grid-cols-3">
              <li class="rounded-control border border-sv-border bg-sv-surface-subtle p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                  01 · Service completed
                </p>
                <p class="mt-2 text-sm text-sv-text-secondary">
                  Proves the work. It does not earn commission by itself.
                </p>
              </li>
              <li class="rounded-control border border-sv-warning-border bg-sv-warning-bg p-4 text-sv-warning-fg">
                <p class="text-xs font-semibold uppercase tracking-wide">
                  02 · Finance validates payment
                </p>
                <p class="mt-2 text-sm">
                  Commission is recorded as outstanding. Salary accrues per your terms.
                </p>
              </li>
              <li class="rounded-control border border-sv-success-border bg-sv-success-bg p-4 text-sv-success-fg">
                <p class="text-xs font-semibold uppercase tracking-wide">
                  03 · Payout recorded paid
                </p>
                <p class="mt-2 text-sm">
                  Your employer pays you outside Servana; Finance records it here.
                </p>
              </li>
            </ol>
          </section>
        </div>

        <aside class="flex flex-col gap-4">
          <div class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
              How you are paid
            </p>
            <p class="mt-2 font-display text-xl font-bold text-sv-text-heading">
              {{ compensationModelLabel(visibility.model) }}
            </p>
            <p
              v-if="visibility.conflicting"
              class="mt-2 text-sm text-sv-warning-fg"
            >
              More than one plan is active. Ask Human Resource to confirm your terms.
            </p>
            <RouterLink
              :to="{ name: 'personnel.earnings-terms' }"
              class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
            >
              Read my terms
            </RouterLink>
          </div>

          <div class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
              Latest payout
            </p>
            <template v-if="latestPayout">
              <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p class="font-display text-xl font-bold text-sv-text-heading">
                  {{ formatMoney(latestPayout.gross_amount_minor, latestPayout.currency) }}
                </p>
                <SvStatusBadge
                  size="sm"
                  :label="humanizeStatus(latestPayout.status)"
                  :tone="personnelStatusTone(latestPayout.status)"
                />
              </div>
              <p class="mt-1 text-xs text-sv-text-muted">
                {{ nairobiDate(latestPayout.period_start) }} – {{ nairobiDate(latestPayout.period_end) }}
              </p>
            </template>
            <p
              v-else
              class="mt-2 text-sm text-sv-text-muted"
            >
              No payout has been recorded yet.
            </p>
            <RouterLink
              :to="{ name: 'personnel.earnings-payouts' }"
              class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
            >
              View payouts
            </RouterLink>
          </div>

          <div class="rounded-card border border-sv-border bg-sv-surface-warm p-5">
            <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
              <SvIconDocument
                aria-hidden="true"
                class="h-4 w-4"
              />Open questions
            </p>
            <p class="mt-2 font-display text-xl font-bold text-sv-text-heading">
              {{ unresolved ?? '—' }} unresolved
            </p>
            <RouterLink
              :to="{ name: 'personnel.earnings-queries' }"
              class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
            >
              Earnings queries
            </RouterLink>
          </div>

          <p class="flex items-start gap-2 rounded-card border border-sv-info-border bg-sv-info-bg p-4 text-sm text-sv-info-fg">
            <SvIconInfo
              aria-hidden="true"
              class="mt-0.5 h-5 w-5 shrink-0"
            />
            Commission is earned only after Finance validates the client payment. A completed service alone does not create earned commission.
          </p>
          <p class="flex items-start gap-2 px-1 text-xs leading-5 text-sv-text-muted">
            <SvIconSecurity
              aria-hidden="true"
              class="mt-0.5 h-4 w-4 shrink-0"
            />
            Only you can see these figures. No other team member’s pay is shown anywhere in your account.
          </p>
        </aside>
      </div>
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-earnings-statement {
  background:
    radial-gradient(circle at 0% 0%, color-mix(in srgb, var(--sv-color-growth) 14%, transparent), transparent 45%),
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-accent) 12%, transparent), transparent 40%),
    var(--sv-color-surface-raised);
}
</style>
