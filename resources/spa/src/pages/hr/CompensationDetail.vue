<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import { useCompensationStore, type CompensationPlan } from '@/stores/compensationStore';
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
</script>

<template>
  <section
    class="mx-auto max-w-5xl"
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
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep"
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
      <template v-if="member">
        <SvAlert
          severity="info"
          title="Configuration only"
        >
          Amounts are effective-dated declared terms in integer minor units on the server. Earned salary, commission, liability and payment are separate immutable workflows.
        </SvAlert>

        <div
          v-if="current"
          class="mt-4 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]"
        >
          <SvCard
            as="section"
            class="border-t-4 border-t-sv-brand"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Current priority version
                </p>
                <h2 class="mt-1 font-display text-xl font-bold capitalize text-heading">
                  {{ humanize(current.compensation_model) }}
                </h2>
              </div>
              <SvStatusBadge
                :label="humanize(current.status)"
                :tone="tone(current.status)"
              />
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
              <div>
                <dt class="text-xs text-text-muted">
                  Salary terms
                </dt><dd class="text-text">
                  {{ salary(current) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Effective window
                </dt><dd class="text-text">
                  {{ current.effective_from }} → {{ current.effective_to ?? 'open ended' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Commission rule
                </dt><dd class="text-text">
                  {{ current.commission_rule?.id ?? 'No commission rule' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Change reason
                </dt><dd class="text-text">
                  {{ current.change_reason ?? 'Not recorded' }}
                </dd>
              </div>
            </dl>
          </SvCard>
          <SvCard as="aside">
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Version trail
            </p>
            <p class="mt-2 text-3xl font-bold text-heading">
              {{ plans.length }}
            </p>
            <p class="text-sm text-text-muted">
              configuration versions visible for this staff member
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center font-semibold text-heading underline"
              :to="{ name: 'hr.compensation-history', params: { staffUlid: member.id } }"
            >
              Review change history
            </RouterLink>
          </SvCard>
        </div>

        <SvCard
          v-else
          as="section"
          class="mt-4 border-dashed"
        >
          <h2 class="font-display text-lg font-bold text-heading">
            No compensation plan configured
          </h2>
          <p class="mt-2 text-sm text-text-muted">
            Create a draft with an effective date, declared model and required salary or commission terms.
          </p>
          <RouterLink
            class="mt-3 inline-flex min-h-sv-touch items-center font-semibold text-heading underline"
            :to="{ name: 'hr.compensation-setup', params: { staffUlid: member.id } }"
          >
            Start compensation setup
          </RouterLink>
        </SvCard>

        <section
          v-if="plans.length > 1"
          class="mt-6"
          aria-labelledby="other-versions-heading"
        >
          <h2
            id="other-versions-heading"
            class="font-display text-lg font-bold text-heading"
          >
            All versions
          </h2>
          <ul class="mt-3 grid gap-3 sm:grid-cols-2">
            <li
              v-for="plan in plans"
              :key="plan.id"
            >
              <SvCard
                as="article"
                padding="sm"
              >
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
              </SvCard>
            </li>
          </ul>
        </section>
      </template>
    </SvStateBoundary>
  </section>
</template>
