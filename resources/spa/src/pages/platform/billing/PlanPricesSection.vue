<script setup lang="ts">
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useForm } from '@/composables/useForm';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import { BILLING_INTERVALS, usePlanPriceStore, type PlanPrice } from '@/stores/planPriceStore';
import type { SubscriptionPlan } from '@/stores/subscriptionPlanStore';

// Phase 20A — effective-dated plan prices (the sole price source). Read requires
// `platform.plan.view`; create/cancel require `platform.plan_price.manage` plus a fresh
// step-up (server-enforced). Overlaps are rejected by PostgreSQL (409). Only a FUTURE price
// may be cancelled; current/historical rows are read-only. Amounts are integer minor units.
const props = defineProps<{ plan: SubscriptionPlan | null }>();

const store = usePlanPriceStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canManage = computed(() => can('platform.plan_price.manage'));

const showForm = ref(false);
const cancelTarget = ref<PlanPrice | null>(null);
const actionError = ref<string | null>(null);

const form = useForm<{ amount_major: string; currency: string; billing_interval: string; effective_from: string; effective_to: string }>({
  amount_major: '',
  currency: 'KES',
  billing_interval: 'monthly',
  effective_from: '',
  effective_to: '',
});

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (props.plan === null) return 'empty';
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.prices.length === 0) return 'empty';
  return 'success';
});

function money(minor: number, currency: string): string {
  return `${currency} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

function intervalLabel(value: string): string {
  return BILLING_INTERVALS.find((i) => i.value === value)?.label ?? value;
}

watch(
  () => props.plan?.id,
  async (id) => {
    store.$reset();
    if (id !== undefined) await store.fetchPrices(id);
  },
  { immediate: true },
);

function openCreate(): void {
  form.reset();
  actionError.value = null;
  showForm.value = true;
}

const submit = form.handleSubmit(async (values) => {
  if (props.plan === null) return;
  actionError.value = null;
  Object.keys(form.errors).forEach((k) => delete form.errors[k]);
  // Convert the major-unit input to integer minor units (never float arithmetic downstream).
  const amountMinor = Math.round(Number(values.amount_major) * 100);
  try {
    await store.createPrice(props.plan.id, {
      amount_minor: amountMinor,
      currency: values.currency.toUpperCase(),
      billing_interval: values.billing_interval,
      effective_from: values.effective_from,
      effective_to: values.effective_to === '' ? null : values.effective_to,
    });
    notifications.addToast({ type: 'success', message: 'Price scheduled.' });
    showForm.value = false;
    await store.fetchPrices(props.plan.id);
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      form.mergeServerErrors(err.apiError);
      actionError.value =
        err.apiError.code === 'invalid_state_transition' || err.apiError.code === 'duplicate_reference'
          ? 'This price overlaps an existing effective range for the same interval and currency.'
          : err.apiError.message ?? 'The price could not be created (a fresh step-up may be required).';
    } else {
      actionError.value = 'Something went wrong.';
    }
  }
});

async function confirmCancel(): Promise<void> {
  if (cancelTarget.value === null || props.plan === null) return;
  actionError.value = null;
  try {
    await store.cancelFuturePrice(cancelTarget.value.id);
    notifications.addToast({ type: 'success', message: 'Future price cancelled.' });
    cancelTarget.value = null;
    await store.fetchPrices(props.plan.id);
  } catch (err) {
    actionError.value =
      axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'The price could not be cancelled.';
  }
}
</script>

<template>
  <section aria-labelledby="prices-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2
          id="prices-heading"
          class="font-display text-lg font-bold text-heading"
        >
          Plan prices
          <span
            v-if="plan"
            class="ml-2 text-sm font-normal text-text-muted"
          >{{ plan.name }}</span>
        </h2>
        <p class="mt-1 text-sm text-text-muted">
          Effective-dated prices. Current and historical prices are read-only; only a future
          price can be cancelled.
        </p>
      </div>
      <SvButton
        v-if="canManage && plan"
        @click="openCreate"
      >
        Schedule price
      </SvButton>
    </div>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      :empty-message="plan === null ? 'Select a plan from the Plans tab to manage its prices.' : 'No prices scheduled for this plan.'"
      @retry="plan && store.fetchPrices(plan.id)"
    >
      <div class="mt-2 overflow-x-auto">
        <table class="w-full min-w-[560px] text-left text-sm">
          <caption class="sr-only">
            Effective-dated prices for {{ plan?.name }}
          </caption>
          <thead>
            <tr class="border-b border-border text-text-muted">
              <th
                scope="col"
                class="py-2 pr-4"
              >
                Amount
              </th>
              <th
                scope="col"
                class="py-2 pr-4"
              >
                Interval
              </th>
              <th
                scope="col"
                class="py-2 pr-4"
              >
                Effective from
              </th>
              <th
                scope="col"
                class="py-2 pr-4"
              >
                Effective to
              </th>
              <th
                scope="col"
                class="py-2 pr-4"
              >
                Lifecycle
              </th>
              <th
                scope="col"
                class="py-2"
              >
                <span class="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="price in store.prices"
              :key="price.id"
              class="border-b border-border/60"
              data-testid="price-row"
            >
              <td class="py-2 pr-4 font-medium text-text">
                {{ money(price.amount_minor, price.currency) }}
              </td>
              <td class="py-2 pr-4">
                {{ intervalLabel(price.billing_interval) }}
              </td>
              <td class="py-2 pr-4">
                {{ price.effective_from }}
              </td>
              <td class="py-2 pr-4">
                {{ price.effective_to ?? '—' }}
              </td>
              <td class="py-2 pr-4">
                <span
                  class="rounded-control px-2 py-0.5 text-xs"
                  :class="{
                    'bg-primary/15 text-brand-deep': price.lifecycle === 'current',
                    'bg-surface-alt text-text-muted': price.lifecycle === 'historical',
                    'bg-cream text-brand-deep': price.lifecycle === 'future',
                  }"
                >{{ price.lifecycle }}</span>
              </td>
              <td class="py-2 text-right">
                <SvButton
                  v-if="canManage && price.lifecycle === 'future'"
                  variant="destructive"
                  @click="cancelTarget = price"
                >
                  Cancel
                </SvButton>
                <span
                  v-else
                  class="text-xs text-text-muted"
                >Read-only</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </SvStateBoundary>

    <SvDialog
      :open="showForm"
      title="Schedule price"
      description="Creates a new effective-dated price. Overlapping ranges for the same interval and currency are rejected."
      @close="showForm = false"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
      >
        <SvTextInput
          id="price-amount"
          label="Amount (major units)"
          type="number"
          :model-value="form.values.amount_major"
          :errors="form.errors.amount_minor"
          help="Entered in major units; stored as integer minor units."
          required
          @update:model-value="form.values.amount_major = $event"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <SvTextInput
            id="price-currency"
            label="Currency"
            :model-value="form.values.currency"
            :errors="form.errors.currency"
            required
            @update:model-value="form.values.currency = $event"
          />
          <SvSelect
            id="price-interval"
            label="Billing interval"
            :model-value="form.values.billing_interval"
            :options="[...BILLING_INTERVALS]"
            :errors="form.errors.billing_interval"
            required
            @update:model-value="form.values.billing_interval = $event"
          />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <SvTextInput
            id="price-from"
            label="Effective from"
            type="date"
            :model-value="form.values.effective_from"
            :errors="form.errors.effective_from"
            required
            @update:model-value="form.values.effective_from = $event"
          />
          <SvTextInput
            id="price-to"
            label="Effective to (optional)"
            type="date"
            :model-value="form.values.effective_to"
            :errors="form.errors.effective_to"
            @update:model-value="form.values.effective_to = $event"
          />
        </div>
        <p
          v-if="actionError"
          class="text-sm text-error"
          role="alert"
        >
          {{ actionError }}
        </p>
        <div class="flex justify-end gap-2">
          <SvButton
            variant="secondary"
            @click="showForm = false"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            :loading="form.submitting.value"
          >
            Schedule price
          </SvButton>
        </div>
      </form>
    </SvDialog>

    <SvDialog
      :open="cancelTarget !== null"
      title="Cancel future price?"
      description="This removes a scheduled future price. Current and historical prices are never affected."
      @close="cancelTarget = null"
    >
      <p
        v-if="actionError"
        class="text-sm text-error"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="flex justify-end gap-2">
        <SvButton
          variant="secondary"
          @click="cancelTarget = null"
        >
          Keep price
        </SvButton>
        <SvButton
          variant="destructive"
          @click="confirmCancel"
        >
          Cancel price
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
