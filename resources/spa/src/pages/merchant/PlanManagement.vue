<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import { useSubscriptionStore } from '@/stores/subscriptionStore';
import { formatMoney } from '@/utils/money';

/**
 * Plan management (Plan §48; Phase 20B). Schedule / cancel a NO-PRORATION next-cycle plan change.
 * `effective_at` is computed server-side (the current period end) — the UI never sends a client date
 * and never offers an immediate/mid-cycle change. `merchant.subscription.plan_change` gates the
 * controls (UX only); in billing read-only states the mutation controls are disabled while the
 * backend remains authoritative. Structured 409 (a change is already pending) and 422 are surfaced.
 */
const store = useSubscriptionStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canChange = computed(() => can('merchant.subscription.plan_change'));
const submitting = ref(false);
const actionError = ref<string | null>(null);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.subscription === null) return 'empty';
  return 'success';
});

const sub = computed(() => store.subscription);
const scheduled = computed(() => store.scheduledChange);
const billingReadOnly = computed(() => sub.value?.billing_read_only === true);
/** Mutation controls are shown only with the permission AND no billing block (backend authoritative). */
const canMutate = computed(() => canChange.value && !billingReadOnly.value);

onMounted(async () => {
  if (!can('merchant.subscription.view')) return;
  await store.fetchSubscription();
  if (store.subscription !== null) {
    await Promise.all([store.fetchPlans(), store.fetchScheduledChange()]);
  }
});

function amount(minor: number | string): number {
  return typeof minor === 'string' ? Number(minor) : minor;
}

async function schedule(planUlid: string, priceUlid: string): Promise<void> {
  if (submitting.value || !canMutate.value) return;
  submitting.value = true;
  actionError.value = null;
  try {
    await store.schedulePlanChange(planUlid, priceUlid);
    notifications.addToast({ type: 'success', message: 'Plan change scheduled for the next billing cycle.' });
  } catch (err) {
    actionError.value = resolveError(err, 'The plan change could not be scheduled.');
  } finally {
    submitting.value = false;
  }
}

async function cancel(): Promise<void> {
  if (submitting.value || !canMutate.value) return;
  submitting.value = true;
  actionError.value = null;
  try {
    await store.cancelScheduledChange();
    notifications.addToast({ type: 'success', message: 'Scheduled plan change cancelled.' });
  } catch (err) {
    actionError.value = resolveError(err, 'The scheduled change could not be cancelled.');
  } finally {
    submitting.value = false;
  }
}

function resolveError(err: unknown, fallback: string): string {
  if (axios.isAxiosError(err) && err.apiError) {
    if (err.apiError.code === 'scheduled_plan_change_exists') {
      return 'A plan change is already scheduled. Cancel it before scheduling another.';
    }
    if (err.apiError.code === 'billing_read_only') {
      return 'Plan changes are paused while billing is in read-only mode.';
    }
    return err.apiError.message ?? fallback;
  }
  return fallback;
}
</script>

<template>
  <div class="mx-auto flex max-w-4xl flex-col gap-6">
    <header>
      <h1 class="font-display text-2xl font-bold text-heading">
        Plan management
      </h1>
      <p class="mt-1 text-sm text-text-muted">
        Choose a plan for your next billing cycle. Changes take effect at the cycle boundary with
        <strong>no proration</strong>.
      </p>
    </header>

    <p
      v-if="!can('merchant.subscription.view')"
      class="rounded-control bg-surface-alt px-4 py-3 text-sm text-text-muted"
      role="note"
    >
      You do not have access to plan management.
    </p>

    <SvStateBoundary
      v-else
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      empty-message="No subscription was found for your account."
      @retry="store.fetchSubscription()"
    >
      <div
        v-if="sub"
        class="flex flex-col gap-6"
      >
        <div
          v-if="billingReadOnly"
          class="rounded-control border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-text"
          role="status"
        >
          Billing is in read-only mode, so plan changes are paused. You can review plans, but
          scheduling a change is unavailable until billing is up to date.
        </div>

        <p
          v-if="actionError"
          class="rounded-control bg-red-50 px-4 py-3 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ actionError }}
        </p>

        <!-- Scheduled change -->
        <SvCard v-if="scheduled">
          <h2 class="font-display text-lg font-bold text-heading">
            Scheduled change
          </h2>
          <p
            class="mt-2 text-sm text-text"
            data-testid="scheduled-change"
          >
            Changing to <strong>{{ scheduled.target_plan.name }}</strong>
            ({{ formatMoney(amount(scheduled.target_price.amount_minor), scheduled.target_price.currency) }})
            on <strong>{{ scheduled.effective_at }}</strong> — the start of your next cycle.
          </p>
          <div
            v-if="canMutate"
            class="mt-4"
          >
            <SvButton
              variant="destructive"
              :loading="submitting"
              data-testid="cancel-scheduled-change"
              @click="cancel"
            >
              Cancel scheduled change
            </SvButton>
          </div>
        </SvCard>

        <!-- Available plans -->
        <section aria-labelledby="plans-heading">
          <h2
            id="plans-heading"
            class="font-display text-lg font-bold text-heading"
          >
            Available plans
          </h2>
          <p class="mt-1 text-sm text-text-muted">
            Your next cycle starts on <strong>{{ sub.current_period_end }}</strong>. A change applies
            then — you are never charged a mid-cycle proration.
          </p>

          <ul
            role="list"
            class="mt-4 grid gap-4 sm:grid-cols-2"
          >
            <li
              v-for="plan in store.plans"
              :key="plan.id"
            >
              <SvCard>
                <div class="flex items-start justify-between gap-2">
                  <div>
                    <h3 class="text-base font-semibold text-heading">
                      {{ plan.name }}
                    </h3>
                    <p
                      v-if="plan.description"
                      class="mt-1 text-sm text-text-muted"
                    >
                      {{ plan.description }}
                    </p>
                  </div>
                  <span
                    v-if="plan.is_current"
                    class="rounded-full bg-cream px-2 py-0.5 text-xs font-semibold text-brand-deep"
                    data-testid="current-plan-badge"
                  >
                    Current
                  </span>
                </div>

                <p
                  v-if="plan.effective_price"
                  class="mt-3 text-lg font-bold text-heading"
                >
                  {{ formatMoney(amount(plan.effective_price.amount_minor), plan.effective_price.currency) }}
                  <span class="text-sm font-normal text-text-muted">/ {{ plan.effective_price.billing_interval }}</span>
                </p>
                <p
                  v-else
                  class="mt-3 text-sm text-text-muted"
                >
                  No price is currently available for your billing interval.
                </p>

                <div
                  v-if="canMutate && !plan.is_current && plan.effective_price && !scheduled"
                  class="mt-4"
                >
                  <SvButton
                    :loading="submitting"
                    :data-testid="`schedule-${plan.key}`"
                    @click="schedule(plan.id, plan.effective_price.id)"
                  >
                    Schedule for next cycle
                  </SvButton>
                </div>
              </SvCard>
            </li>
          </ul>
        </section>
      </div>
    </SvStateBoundary>
  </div>
</template>
