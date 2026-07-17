<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import { useSubscriptionPlanStore, type PlanEntitlement } from '@/stores/subscriptionPlanStore';
import type { SubscriptionPlan } from '@/stores/subscriptionPlanStore';

// Phase 20A — plan entitlements (managed under `platform.plan.manage`; fresh step-up
// server-enforced). Each entitlement is enabled/disabled with an optional integer limit.
// NO merchant-subscription binding here — entitlements describe the plan only (Phase 20B
// binds a merchant to a plan).
const props = defineProps<{ plan: SubscriptionPlan | null }>();

const store = useSubscriptionPlanStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canManage = computed(() => can('platform.plan.manage'));

// Local editable copy; limits held as strings for the inputs.
interface Draft {
  entitlement_key: string;
  enabled: boolean;
  limit: string;
}
const draft = reactive<{ rows: Draft[] }>({ rows: [] });
const newKey = ref('');
const submitting = ref(false);
const actionError = ref<string | null>(null);
const loadError = ref<string | null>(null);
const loading = ref(false);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (props.plan === null) return 'empty';
  if (loading.value) return 'loading';
  if (loadError.value) return 'error';
  if (draft.rows.length === 0) return 'empty';
  return 'success';
});

function toDraft(list: PlanEntitlement[]): void {
  draft.rows = list.map((e) => ({
    entitlement_key: e.entitlement_key,
    enabled: e.enabled,
    limit: e.limit_int === null || e.limit_int === undefined ? '' : String(e.limit_int),
  }));
}

async function load(): Promise<void> {
  if (props.plan === null) return;
  loading.value = true;
  loadError.value = null;
  try {
    await store.fetchEntitlements(props.plan.id);
    toDraft(store.entitlements);
  } catch {
    loadError.value = 'Unable to load entitlements.';
  } finally {
    loading.value = false;
  }
}

watch(() => props.plan?.id, load, { immediate: true });

function addRow(): void {
  const key = newKey.value.trim();
  if (key === '' || draft.rows.some((r) => r.entitlement_key === key)) return;
  draft.rows.push({ entitlement_key: key, enabled: true, limit: '' });
  newKey.value = '';
}

function removeRow(index: number): void {
  draft.rows.splice(index, 1);
}

async function save(): Promise<void> {
  if (props.plan === null || submitting.value || !canManage.value) return;
  submitting.value = true;
  actionError.value = null;
  try {
    const next: PlanEntitlement[] = draft.rows.map((r) => ({
      entitlement_key: r.entitlement_key,
      enabled: r.enabled,
      limit_int: r.limit.trim() === '' ? null : Number(r.limit),
    }));
    await store.updateEntitlements(props.plan.id, next);
    toDraft(store.entitlements);
    notifications.addToast({ type: 'success', message: 'Entitlements updated.' });
  } catch (err) {
    actionError.value =
      axios.isAxiosError(err) && err.apiError
        ? err.apiError.message ?? 'Entitlements could not be saved (a fresh step-up may be required).'
        : 'Something went wrong.';
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <section aria-labelledby="entitlements-heading">
    <h2
      id="entitlements-heading"
      class="font-display text-lg font-bold text-heading"
    >
      Plan entitlements
      <span
        v-if="plan"
        class="ml-2 text-sm font-normal text-text-muted"
      >{{ plan.name }}</span>
    </h2>
    <p class="mt-1 text-sm text-text-muted">
      Feature flags and limits for this plan. A disabled or absent entitlement denies access;
      an empty limit means unlimited.
    </p>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      :error-message="loadError ?? undefined"
      :empty-message="plan === null ? 'Select a plan from the Plans tab to manage its entitlements.' : 'No entitlements defined for this plan yet.'"
      @retry="load"
    >
      <SvCard class="mt-2">
        <ul class="flex flex-col divide-y divide-border">
          <li
            v-for="(row, index) in draft.rows"
            :key="row.entitlement_key"
            class="flex flex-wrap items-center gap-4 py-3"
          >
            <span class="min-w-[10rem] flex-1 font-medium text-text">{{ row.entitlement_key }}</span>
            <label class="flex items-center gap-2 text-sm">
              <input
                v-model="row.enabled"
                type="checkbox"
                class="h-5 w-5 rounded border-border"
                :disabled="!canManage"
                :aria-label="`Enable ${row.entitlement_key}`"
              >
              Enabled
            </label>
            <div class="w-32">
              <SvInput
                :id="`limit-${row.entitlement_key}`"
                label="Limit"
                type="number"
                :model-value="row.limit"
                :disabled="!canManage"
                @update:model-value="row.limit = $event"
              />
            </div>
            <SvButton
              v-if="canManage"
              variant="ghost"
              @click="removeRow(index)"
            >
              Remove
            </SvButton>
          </li>
        </ul>
      </SvCard>
    </SvStateBoundary>

    <div
      v-if="canManage && plan"
      class="mt-4 flex flex-col gap-4"
    >
      <div class="flex items-end gap-2">
        <div class="flex-1">
          <SvInput
            id="new-entitlement-key"
            label="Add entitlement key"
            :model-value="newKey"
            hint="e.g. branches.max or reports.advanced"
            @update:model-value="newKey = $event"
          />
        </div>
        <SvButton
          variant="secondary"
          @click="addRow"
        >
          Add
        </SvButton>
      </div>

      <p
        v-if="actionError"
        class="text-sm text-error"
        role="alert"
      >
        {{ actionError }}
      </p>

      <div>
        <SvButton
          :loading="submitting"
          @click="save"
        >
          Save entitlements
        </SvButton>
      </div>
    </div>
  </section>
</template>
