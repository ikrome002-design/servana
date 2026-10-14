<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import { SvIconCheck, SvIconForbidden } from '@/design-system/icons';
import SvTextInput from '@/components/ui/SvTextInput.vue';
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
  <!--
    COR-UI08-UI12-VIS-001: the entitlements read as a capability matrix for ONE plan — capability,
    whether it is granted, and its limit — on a single panel whose footer holds the add/save tools.
    Every value is still the server's; an empty limit still means unlimited.
  -->
  <section
    aria-labelledby="entitlements-heading"
    class="sv-r-panel sv-r-panel--tonal sv-r-tone-teal"
  >
    <div class="sv-r-panel__head">
      <div class="min-w-0">
        <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal">
          Server-enforced capabilities
        </p>
        <h2
          id="entitlements-heading"
          class="sv-r-title mt-1"
        >
          Plan entitlements
          <span
            v-if="plan"
            class="sv-r-chip sv-r-chip--tone sv-r-tone-teal ml-2 align-middle"
          >{{ plan.name }}</span>
        </h2>
        <p class="sv-r-panel__desc">
          Feature flags and limits for this plan. A disabled or absent entitlement denies access;
          an empty limit means unlimited.
        </p>
      </div>
    </div>

    <SvStateBoundary
      :state="boundaryState"
      :error-message="loadError ?? undefined"
      :empty-message="plan === null ? 'Select a plan from the Plans tab to manage its entitlements.' : 'No entitlements defined for this plan yet.'"
      @retry="load"
    >
      <div class="sv-r-ledger">
        <div
          class="sv-r-ledger__colhead"
          style="--sv-r-ledger-cols: minmax(0, 2fr) minmax(0, 1fr) 8rem auto"
          aria-hidden="true"
        >
          <span>Capability</span><span>Access</span><span>Limit</span><span />
        </div>
        <ul>
          <li
            v-for="(row, index) in draft.rows"
            :key="row.entitlement_key"
            class="sv-r-ledger__row"
            style="--sv-r-ledger-cols: minmax(0, 2fr) minmax(0, 1fr) 8rem auto"
          >
            <span class="flex min-w-0 items-center gap-3">
              <span
                aria-hidden="true"
                class="sv-r-icon"
                :class="row.enabled ? 'sv-r-tone-green' : 'sv-r-tone-neutral'"
              >
                <SvIconCheck v-if="row.enabled" />
                <SvIconForbidden v-else />
              </span>
              <span class="min-w-0 break-all font-mono text-sm font-semibold text-sv-text">{{ row.entitlement_key }}</span>
            </span>
            <label class="flex min-h-sv-touch items-center gap-2 text-sm font-medium text-sv-text-secondary">
              <input
                v-model="row.enabled"
                type="checkbox"
                class="h-5 w-5 rounded border-border accent-sv-brand"
                :disabled="!canManage"
                :aria-label="`Enable ${row.entitlement_key}`"
              >
              Enabled
            </label>
            <div>
              <SvTextInput
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
            <span v-else />
          </li>
        </ul>
      </div>
    </SvStateBoundary>

    <div
      v-if="canManage && plan"
      class="sv-r-panel__foot flex flex-col gap-4"
    >
      <div class="flex flex-wrap items-end gap-2">
        <div class="min-w-[14rem] flex-1">
          <SvTextInput
            id="new-entitlement-key"
            label="Add entitlement key"
            :model-value="newKey"
            help="e.g. branches.max or reports.advanced"
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
