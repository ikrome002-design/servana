<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvKpiCard from '@/components/refined/SvKpiCard.vue';
import { SvIconClock } from '@/design-system/icons';
import { navigationIcon } from '@/navigation/navigationIcons';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import { describeCommissionRule, useCompensationStore, type CompensationPlan } from '@/stores/compensationStore';
import { useStaffStore } from '@/stores/staffStore';
import { formatMoney } from '@/utils/money';

const route = useRoute();
const store = useCompensationStore();
const staff = useStaffStore();
const staffId = computed(() => String(route.params.staffUlid ?? ''));
const member = computed(() => staff.current?.id === staffId.value ? staff.current : null);
const plans = computed(() => store.plans.filter((plan) => plan.staff_profile_id === staffId.value));
const current = computed(() => plans.value.find((plan) => plan.status === 'active')
  ?? plans.value.find((plan) => plan.status === 'scheduled')
  ?? plans.value.find((plan) => plan.status === 'pending_approval')
  ?? plans.value[0]
  ?? null);
const state = computed(() => (store.loading || staff.loading ? 'loading' : store.error || staff.error ? 'error' : member.value ? 'success' : 'empty'));

function humanize(value: string | null | undefined): string {
  return value ? value.replaceAll('_', ' ') : 'Not set';
}

function tone(status: string): SvStatusTone {
  if (status === 'active') return 'success';
  if (status === 'pending_approval' || status === 'scheduled' || status === 'draft') return 'warning';
  if (status === 'rejected' || status === 'cancelled') return 'error';
  return 'neutral';
}

function salary(plan: CompensationPlan): string {
  if (plan.salary_amount_minor == null || plan.salary_currency == null) return 'No salary terms';
  return formatMoney(plan.salary_amount_minor, plan.salary_currency)
    + (plan.salary_period ? ' · ' + humanize(plan.salary_period) : '');
}

onMounted(async () => {
  store.filterStaffProfile = staffId.value;
  await Promise.all([
    staff.fetchStaffMember(staffId.value),
    store.fetchPlans(),
  ]);
});

/** The current version as numbered contract clauses — the server's values, verbatim. */
const clauses = computed(() => current.value ? [
  { label: 'Model', value: humanize(current.value.compensation_model) },
  { label: 'Salary terms', value: salary(current.value) },
  {
    label: 'Commission rule',
    value: current.value.commission_rule
      ? describeCommissionRule(current.value.commission_rule, (minor, currency) =>
        minor === null || currency === null ? '—' : formatMoney(minor, currency))
      : 'No commission rule',
  },
  { label: 'Effective window', value: `${current.value.effective_from} → ${current.value.effective_to ?? 'open ended'}` },
  { label: 'Change reason', value: current.value.change_reason ?? 'Not recorded' },
] : []);
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — declared terms as a CONTRACT. The current priority version reads as a
    document: a header band with the model and status, then numbered clauses (model, salary terms,
    commission rule, effective window, change reason) set apart by fine rules. The rail carries the
    version trail and the configuration-only boundary; every version follows on a dated rail.
    Nothing here computes earnings or executes a payout.
  -->
  <section
    class="sv-r-page"
    data-testid="hr-compensation-detail"
  >
    <SvPageHeader
      :title="member ? member.display_name + ' compensation' : 'Compensation detail'"
      eyebrow="Declared terms"
      description="Current, scheduled and historical compensation configuration. This page does not calculate earnings or execute a payout."
    >
      <template
        v-if="member"
        #actions
      >
        <RouterLink
          class="sv-r-action sv-focus-ring"
          :to="{ name: 'hr.compensation-setup', params: { staffUlid: member.id } }"
        >
          Set up terms
        </RouterLink>
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? staff.error ?? undefined"
      empty-message="This staff member is not available in your assigned branch."
      @retry="store.fetchPlans()"
    >
      <div
        v-if="member"
        class="sv-r-grid sv-r-split"
      >
        <div class="sv-r-stack min-w-0">
          <article
            v-if="current"
            class="sv-r-panel sv-r-panel--flush"
          >
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-sv-border bg-sv-surface-warm px-6 py-5">
              <div>
                <p class="sv-r-eyebrow">
                  Current priority version
                </p>
                <h2 class="sv-r-title-lg mt-1 capitalize">
                  {{ humanize(current.compensation_model) }}
                </h2>
              </div>
              <SvStatusBadge
                :label="humanize(current.status)"
                :tone="tone(current.status)"
              />
            </div>
            <dl class="px-6 py-2">
              <div
                v-for="(clause, index) in clauses"
                :key="clause.label"
                class="grid grid-cols-[minmax(0,15rem)_minmax(0,1fr)] items-baseline gap-3 border-b border-sv-border py-4 last:border-b-0"
              >
                <!-- The clause number lives inside the dt so the group holds only dt/dd (axe definition-list). -->
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  <span
                    aria-hidden="true"
                    class="inline-block w-10 font-display text-sm font-extrabold normal-case tracking-normal text-sv-text-muted"
                  >{{ String(index + 1).padStart(2, '0') }}</span>{{ clause.label }}
                </dt><dd class="text-sm font-semibold text-text">
                  {{ clause.value }}
                </dd>
              </div>
            </dl>
          </article>

          <section
            v-else
            class="sv-r-panel sv-r-panel--dashed"
          >
            <h2 class="sv-r-title">
              No compensation plan configured
            </h2>
            <p class="sv-r-lede mt-2">
              Create a draft with an effective date, declared model and required salary or commission terms.
            </p>
            <RouterLink
              class="sv-r-action sv-focus-ring mt-4"
              :to="{ name: 'hr.compensation-setup', params: { staffUlid: member.id } }"
            >
              Start compensation setup
            </RouterLink>
          </section>

          <section
            v-if="plans.length > 1"
            aria-labelledby="other-versions-heading"
          >
            <h2
              id="other-versions-heading"
              class="sv-r-title mb-3"
            >
              All versions
            </h2>
            <ol class="sv-r-steps">
              <li
                v-for="plan in plans"
                :key="plan.id"
                class="sv-r-step"
              >
                <span
                  aria-hidden="true"
                  class="sv-r-step__marker"
                ><SvIconClock class="h-4 w-4" /></span>
                <article class="sv-r-record">
                  <div class="flex items-center justify-between gap-2">
                    <strong class="capitalize text-heading">{{ humanize(plan.compensation_model) }}</strong>
                    <SvStatusBadge
                      :label="humanize(plan.status)"
                      :tone="tone(plan.status)"
                      size="sm"
                    />
                  </div>
                  <p class="mt-2 text-sm text-text-muted">
                    {{ plan.effective_from }} → {{ plan.effective_to ?? 'open ended' }}
                  </p>
                </article>
              </li>
            </ol>
          </section>
        </div>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Version trail"
        >
          <SvKpiCard
            label="Version trail"
            tone="teal"
            :icon="navigationIcon('history')"
            hint="configuration versions visible for this staff member"
            :to="{ name: 'hr.compensation-history', params: { staffUlid: member.id } }"
            link-label="Review change history"
          >
            {{ plans.length }}
          </SvKpiCard>
          <SvAlert
            severity="info"
            title="Configuration only"
          >
            Amounts are effective-dated declared terms in integer minor units on the server. Earned salary, commission, liability and payment are separate immutable workflows.
          </SvAlert>
        </aside>
      </div>
    </SvStateBoundary>
  </section>
</template>