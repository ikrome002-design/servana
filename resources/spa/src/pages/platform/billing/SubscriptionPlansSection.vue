<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useForm } from '@/composables/useForm';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import { useSubscriptionPlanStore, type SubscriptionPlan } from '@/stores/subscriptionPlanStore';

// Phase 20A — subscription-plan catalogue (non-price metadata only). Read requires
// `platform.plan.view`; create/update/retire require `platform.plan.manage` plus a fresh
// step-up (server-enforced). Plans NEVER carry a price — prices live in the Prices tab.
// No merchant plan selection here (Phase 20B).
const store = useSubscriptionPlanStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canManage = computed(() => can('platform.plan.manage'));

const emit = defineEmits<{ select: [plan: SubscriptionPlan] }>();

const showForm = ref(false);
const editing = ref<SubscriptionPlan | null>(null);
const retireTarget = ref<SubscriptionPlan | null>(null);
const actionError = ref<string | null>(null);

const form = useForm<{ key: string; name: string; description: string; tier: string; sort_order: string }>({
  key: '',
  name: '',
  description: '',
  tier: '',
  sort_order: '0',
});

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.plans.length === 0) return 'empty';
  return 'success';
});

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'retired', label: 'Retired' },
];

onMounted(() => store.fetchPlans());

async function reloadWithStatus(value: string): Promise<void> {
  store.filterStatus = value;
  await store.fetchPlans();
}

function openCreate(): void {
  editing.value = null;
  form.reset();
  actionError.value = null;
  showForm.value = true;
}

function openEdit(plan: SubscriptionPlan): void {
  editing.value = plan;
  form.values.key = plan.key;
  form.values.name = plan.name;
  form.values.description = plan.description ?? '';
  form.values.tier = plan.tier ?? '';
  form.values.sort_order = String(plan.sort_order ?? 0);
  actionError.value = null;
  showForm.value = true;
}

const submit = form.handleSubmit(async (values) => {
  actionError.value = null;
  Object.keys(form.errors).forEach((k) => delete form.errors[k]);
  try {
    if (editing.value) {
      await store.updatePlan(editing.value.id, {
        name: values.name,
        description: values.description === '' ? null : values.description,
        tier: values.tier === '' ? null : values.tier,
        sort_order: Number(values.sort_order),
      });
      notifications.addToast({ type: 'success', message: 'Plan updated.' });
    } else {
      await store.createPlan({
        key: values.key,
        name: values.name,
        description: values.description === '' ? null : values.description,
        tier: values.tier === '' ? null : values.tier,
        sort_order: Number(values.sort_order),
      });
      notifications.addToast({ type: 'success', message: 'Plan created.' });
    }
    showForm.value = false;
    await store.fetchPlans();
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      form.mergeServerErrors(err.apiError);
      actionError.value = err.apiError.message ?? 'The plan could not be saved (a fresh step-up may be required).';
    } else {
      actionError.value = 'Something went wrong.';
    }
  }
});

async function confirmRetire(): Promise<void> {
  if (retireTarget.value === null) return;
  actionError.value = null;
  try {
    await store.retirePlan(retireTarget.value.id);
    notifications.addToast({ type: 'success', message: 'Plan retired. Its price history is preserved.' });
    retireTarget.value = null;
    await store.fetchPlans();
  } catch (err) {
    actionError.value =
      axios.isAxiosError(err) && err.apiError
        ? err.apiError.message
        : 'The plan could not be retired.';
  }
}
</script>

<template>
  <section aria-labelledby="plans-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2
          id="plans-heading"
          class="font-display text-lg font-bold text-brand-deep"
        >
          Subscription plans
        </h2>
        <p class="mt-1 text-sm text-text-muted">
          Catalogue metadata only — plans carry no price. Retiring a plan preserves its history.
        </p>
      </div>
      <SvButton
        v-if="canManage"
        @click="openCreate"
      >
        New plan
      </SvButton>
    </div>

    <div class="mt-4 max-w-xs">
      <SvSelect
        id="plan-status-filter"
        label="Filter by status"
        :model-value="store.filterStatus"
        :options="statusOptions"
        @update:model-value="reloadWithStatus($event)"
      />
    </div>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      empty-message="No subscription plans yet."
      @retry="store.fetchPlans()"
    >
      <ul class="mt-2 flex flex-col gap-3">
        <li
          v-for="plan in store.plans"
          :key="plan.id"
          data-testid="plan-row"
        >
          <SvCard padding="sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p class="font-semibold text-text">
                  {{ plan.name }}
                  <span class="ml-2 rounded-control bg-surface-alt px-2 py-0.5 text-xs text-text-muted">{{ plan.key }}</span>
                  <span
                    v-if="plan.status === 'retired'"
                    class="ml-2 rounded-control bg-cream px-2 py-0.5 text-xs text-brand-deep"
                  >Retired</span>
                </p>
                <p
                  v-if="plan.description"
                  class="mt-1 text-sm text-text-muted"
                >
                  {{ plan.description }}
                </p>
              </div>
              <div class="flex flex-wrap gap-2">
                <SvButton
                  variant="secondary"
                  @click="emit('select', plan)"
                >
                  Prices &amp; entitlements
                </SvButton>
                <SvButton
                  v-if="canManage && plan.status === 'active'"
                  variant="ghost"
                  @click="openEdit(plan)"
                >
                  Edit
                </SvButton>
                <SvButton
                  v-if="canManage && plan.status === 'active'"
                  variant="destructive"
                  @click="retireTarget = plan"
                >
                  Retire
                </SvButton>
              </div>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <SvModal
      :open="showForm"
      :title="editing ? 'Edit plan' : 'New plan'"
      description="Plan metadata only. Prices and entitlements are managed separately."
      @close="showForm = false"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
      >
        <SvInput
          id="plan-key"
          label="Key"
          :model-value="form.values.key"
          :disabled="editing !== null"
          :errors="form.errors.key"
          hint="Lowercase letters, digits and underscores. Immutable after creation."
          required
          @update:model-value="form.values.key = $event"
        />
        <SvInput
          id="plan-name"
          label="Name"
          :model-value="form.values.name"
          :errors="form.errors.name"
          required
          @update:model-value="form.values.name = $event"
        />
        <SvTextarea
          id="plan-description"
          label="Description"
          :model-value="form.values.description"
          :errors="form.errors.description"
          @update:model-value="form.values.description = $event"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <SvInput
            id="plan-tier"
            label="Tier"
            :model-value="form.values.tier"
            :errors="form.errors.tier"
            @update:model-value="form.values.tier = $event"
          />
          <SvInput
            id="plan-sort-order"
            label="Sort order"
            type="number"
            :model-value="form.values.sort_order"
            :errors="form.errors.sort_order"
            @update:model-value="form.values.sort_order = $event"
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
            {{ editing ? 'Save changes' : 'Create plan' }}
          </SvButton>
        </div>
      </form>
    </SvModal>

    <SvModal
      :open="retireTarget !== null"
      title="Retire plan?"
      description="Retiring stops new use of the plan. Existing price history is preserved and remains auditable."
      @close="retireTarget = null"
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
          @click="retireTarget = null"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="destructive"
          @click="confirmRetire"
        >
          Retire plan
        </SvButton>
      </div>
    </SvModal>
  </section>
</template>
