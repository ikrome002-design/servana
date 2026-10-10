<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PersonnelEarningsNav from '@/components/personnel/PersonnelEarningsNav.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconCheck, SvIconLocked } from '@/design-system/icons';
import { usePersonnelEarningsStore } from '@/stores/personnelEarningsStore';
import { formatMoney } from '@/utils/money';
import { compensationModelLabel, humanizeStatus, nairobiDate, personnelStatusTone } from '@/utils/personnelStatus';

/*
 * Compensation terms — read-only (UI-14). Human Resource owns the plan; Personnel can read it,
 * mark it reviewed and ask a question. There is no server acknowledgement contract, so "reviewed"
 * is recorded on this device only and is labelled exactly that way — it never approves, amends or
 * activates anything and creates no financial fact.
 */
const store = usePersonnelEarningsStore();
const acknowledged = ref<string | null>(null);
const state = computed(() => store.termsLoading ? 'loading' : store.termsError ? 'error' : store.terms?.has_current_plan ? 'success' : 'empty');
const storageKey = computed(() => `servana:personnel:compensation-terms:${store.terms?.plan_id ?? 'none'}`);
const rule = computed(() => store.terms?.commission_rule ?? null);
const ruleRate = computed(() => {
  const value = rule.value;
  if (!value) return null;
  if (value.percentage_basis_points != null) return `${value.percentage_basis_points / 100}%`;
  if (value.fixed_amount_minor != null && value.currency) return formatMoney(value.fixed_amount_minor, value.currency);
  return humanizeStatus(value.calculation_type);
});
const ruleScope = computed(() => {
  const value = rule.value;
  if (!value) return '';
  if (value.selected_services.length) return `${value.selected_services.length} selected ${value.selected_services.length === 1 ? 'service' : 'services'}`;
  if (value.service_category) return `services in ${value.service_category.name}`;
  return humanizeStatus(value.applies_to).toLowerCase();
});
const PERIOD: Record<string, string> = { monthly: 'month', weekly: 'week', biweekly: 'two weeks', fortnightly: 'two weeks', daily: 'day' };
/** One plain-language sentence built only from server-supplied terms (no arithmetic). */
const summary = computed(() => {
  const terms = store.terms;
  if (!terms) return '';
  const parts: string[] = [];
  if (terms.salary_amount_minor != null && terms.salary_currency) {
    const period = terms.salary_period ? PERIOD[terms.salary_period] ?? terms.salary_period.replaceAll('_', ' ') : 'period';
    parts.push(`You receive ${formatMoney(terms.salary_amount_minor, terms.salary_currency)} per ${period}${rule.value ? ', plus commission' : ''}.`);
  }
  if (rule.value) parts.push(`Commission is ${ruleRate.value} on ${ruleScope.value}, earned once Finance validates the client payment.`);
  return parts.join(' ');
});
function read(): string | null { try { return localStorage.getItem(storageKey.value); } catch { return null; } }
function acknowledge(): void {
  const at = new Date().toISOString();
  acknowledged.value = at;
  try { localStorage.setItem(storageKey.value, at); } catch { /* storage unavailable: the review still shows for this visit */ }
}
onMounted(async () => { await store.fetchTerms(); acknowledged.value = read(); });
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="personnel-compensation-terms"
  >
    <SvTonalPageHeader
      title="Compensation terms"
      eyebrow="My earnings"
      tone="green"
      :context="store.terms?.has_current_plan ? compensationModelLabel(store.terms.compensation_model) : undefined"
      description="The active arrangement Human Resource maintains for you, in plain language first. Reviewing it here never changes the agreement."
    />
    <PersonnelEarningsNav />
    <SvStateBoundary
      :state="state"
      :error-message="store.termsError ?? undefined"
      empty-message="No current compensation plan is on file. Ask Human Resource for help."
      @retry="store.fetchTerms()"
    >
      <template v-if="store.terms">
        <div class="grid gap-6 lg:grid-cols-[1.3fr_0.7fr]">
          <div class="flex flex-col gap-6">
            <section
              aria-labelledby="terms-summary-heading"
              class="sv-terms-summary rounded-card border border-sv-border p-5 shadow-raised md:p-7"
            >
              <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
                  Active plan
                </p>
                <SvStatusBadge
                  v-if="store.terms.status"
                  size="sm"
                  :label="humanizeStatus(store.terms.status)"
                  :tone="personnelStatusTone(store.terms.status)"
                />
              </div>
              <h2
                id="terms-summary-heading"
                class="mt-2 font-display text-3xl font-extrabold text-sv-text-heading"
              >
                {{ compensationModelLabel(store.terms.compensation_model) }}
              </h2>
              <p class="mt-3 max-w-2xl text-base leading-7 text-sv-text-secondary">
                {{ summary }}
              </p>
              <div class="mt-6 grid grid-cols-2 gap-3 md:grid-cols-3">
                <SvStatTile
                  label="Effective from"
                  tone="teal"
                >
                  <span class="text-lg">{{ nairobiDate(store.terms.effective_from) }}</span>
                </SvStatTile>
                <SvStatTile
                  label="Effective to"
                  tone="neutral"
                >
                  <span class="text-lg">{{ store.terms.effective_to ? nairobiDate(store.terms.effective_to) : 'Open-ended' }}</span>
                </SvStatTile>
                <SvStatTile
                  v-if="store.terms.salary_amount_minor != null && store.terms.salary_currency"
                  label="Salary"
                  tone="green"
                  :hint="store.terms.salary_payout_day ? `Paid around day ${store.terms.salary_payout_day}` : 'Payout day set by HR'"
                >
                  <span class="text-lg">{{ formatMoney(store.terms.salary_amount_minor, store.terms.salary_currency) }}</span>
                </SvStatTile>
              </div>
              <p class="mt-4 text-xs text-sv-text-muted">
                If you are suspended: {{ humanizeStatus(store.terms.suspension_salary_policy).toLowerCase() }}.
              </p>
            </section>

            <section
              v-if="rule"
              aria-labelledby="commission-rules-heading"
              class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card md:p-6"
            >
              <h2
                id="commission-rules-heading"
                class="font-display text-lg font-bold text-sv-text-heading"
              >
                Commission rule
              </h2>
              <p class="mt-1 text-sm text-sv-text-secondary">
                Commission becomes earned only after Finance validates the client payment.
              </p>
              <dl class="mt-4 grid gap-3 md:grid-cols-2">
                <div class="rounded-control bg-sv-surface-subtle p-3">
                  <dt class="text-xs text-sv-text-muted">
                    Rate
                  </dt>
                  <dd class="mt-1 font-semibold text-sv-text-heading">
                    {{ ruleRate }}
                  </dd>
                </div>
                <div class="rounded-control bg-sv-surface-subtle p-3">
                  <dt class="text-xs text-sv-text-muted">
                    Calculated on
                  </dt>
                  <dd class="mt-1 font-semibold text-sv-text-heading">
                    {{ humanizeStatus(rule.calculation_basis) }}
                  </dd>
                </div>
                <div class="rounded-control bg-sv-surface-subtle p-3">
                  <dt class="text-xs text-sv-text-muted">
                    Applies to
                  </dt>
                  <dd class="mt-1 font-semibold text-sv-text-heading">
                    {{ humanizeStatus(rule.applies_to) }}<span v-if="rule.service_category"> · {{ rule.service_category.name }}</span>
                  </dd>
                </div>
                <div class="rounded-control bg-sv-surface-subtle p-3">
                  <dt class="text-xs text-sv-text-muted">
                    Preferred-personnel fee
                  </dt>
                  <dd class="mt-1 font-semibold text-sv-text-heading">
                    {{ rule.applies_to_preferred_personnel_fee ? 'Included in commission' : 'Not included' }}
                  </dd>
                </div>
              </dl>
              <ul
                v-if="rule.selected_services.length"
                class="mt-4 flex flex-wrap gap-2"
                aria-label="Services this rule covers"
              >
                <li
                  v-for="service in rule.selected_services"
                  :key="service.id"
                  class="rounded-full border border-sv-border px-3 py-1 text-sm text-sv-text-secondary"
                >
                  {{ service.name }}
                </li>
              </ul>
            </section>
          </div>

          <aside class="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
            <section
              aria-labelledby="acknowledgement-heading"
              class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            >
              <h2
                id="acknowledgement-heading"
                class="font-display text-lg font-bold text-sv-text-heading"
              >
                Your review
              </h2>
              <p class="mt-2 text-sm leading-6 text-sv-text-secondary">
                This confirms only that you read the terms on this browser. It creates no financial fact and is not sent to the server.
              </p>
              <button
                type="button"
                :disabled="acknowledged !== null"
                class="sv-focus-ring mt-4 inline-flex min-h-sv-touch w-full items-center justify-center gap-2 rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover disabled:cursor-default disabled:bg-sv-success-bg disabled:text-sv-success-fg"
                @click="acknowledge"
              >
                <SvIconCheck
                  v-if="acknowledged"
                  aria-hidden="true"
                  class="h-5 w-5"
                />
                {{ acknowledged ? 'Reviewed on this device' : 'Mark as reviewed' }}
              </button>
              <p
                v-if="acknowledged"
                class="mt-2 text-xs text-sv-text-muted"
              >
                Reviewed {{ nairobiDate(acknowledged, true) }}
              </p>
            </section>
            <div class="rounded-card border border-sv-border bg-sv-surface-warm p-5">
              <p class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
                <SvIconLocked
                  aria-hidden="true"
                  class="h-5 w-5"
                />Something looks wrong?
              </p>
              <p class="mt-2 text-sm text-sv-text-secondary">
                Only Human Resource can change your plan. Ask Finance or HR through an earnings query.
              </p>
              <RouterLink
                :to="{ name: 'personnel.earnings-queries' }"
                class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
              >
                Raise an earnings query
              </RouterLink>
            </div>
          </aside>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-terms-summary {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-growth) 14%, transparent), transparent 50%),
    var(--sv-color-surface-raised);
}
</style>
