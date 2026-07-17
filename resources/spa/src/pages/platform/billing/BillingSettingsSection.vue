<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import {
  BILLING_MODES,
  usePlatformBillingSettingsStore,
} from '@/stores/platformBillingSettingsStore';

// Phase 20A — platform billing settings (canonical billing mode + trial/grace/currency).
// Read requires `platform.billing_settings.view`; update requires
// `platform.billing_settings.update` plus a fresh step-up (server-enforced). An update
// creates the next effective-dated version rather than overwriting history.
const store = usePlatformBillingSettingsStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canUpdate = computed(() => can('platform.billing_settings.update'));

const form = reactive({
  billing_mode: 'fixed_amount',
  default_trial_days: '0',
  grace_days: '0',
  currency: 'KES',
});
const errors = reactive<Record<string, string[]>>({});
const submitting = ref(false);
const actionError = ref<string | null>(null);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.current === null) return 'empty';
  return 'success';
});

function syncForm(): void {
  const c = store.current;
  if (c === null) return;
  form.billing_mode = c.billing_mode;
  form.default_trial_days = String(c.default_trial_days);
  form.grace_days = String(c.grace_days);
  form.currency = c.currency;
}

watch(() => store.current, syncForm);

onMounted(async () => {
  if (store.current === null) await store.fetch();
  syncForm();
});

async function submit(): Promise<void> {
  if (submitting.value || !canUpdate.value) return;
  submitting.value = true;
  actionError.value = null;
  Object.keys(errors).forEach((k) => delete errors[k]);
  try {
    await store.updateBillingSettings({
      billing_mode: form.billing_mode,
      default_trial_days: Number(form.default_trial_days),
      grace_days: Number(form.grace_days),
      currency: form.currency.toUpperCase(),
    });
    notifications.addToast({ type: 'success', message: 'Billing settings updated. A new effective version was created.' });
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      Object.assign(errors, err.apiError.fields);
      actionError.value = err.apiError.message ?? 'The update could not be applied (a fresh step-up may be required).';
    } else {
      actionError.value = 'Something went wrong.';
    }
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <section aria-labelledby="billing-settings-heading">
    <h2
      id="billing-settings-heading"
      class="font-display text-lg font-bold text-heading"
    >
      Billing settings
    </h2>
    <p class="mt-1 text-sm text-text-muted">
      The platform billing mode and trial/grace defaults. Saving creates a new effective-dated
      version; historical settings are preserved. A fresh step-up is required.
    </p>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      empty-message="No billing settings have been configured yet."
      @retry="store.fetch()"
    >
      <SvCard class="mt-4">
        <form
          class="flex flex-col gap-4"
          novalidate
          @submit.prevent="submit"
        >
          <p
            v-if="!canUpdate"
            class="rounded-control bg-surface-alt px-3 py-2 text-sm text-text-muted"
            role="note"
          >
            You have read-only access to billing settings.
          </p>

          <SvSelect
            id="billing-mode"
            label="Billing mode"
            :model-value="form.billing_mode"
            :options="[...BILLING_MODES]"
            :disabled="!canUpdate"
            :errors="errors.billing_mode"
            required
            @update:model-value="form.billing_mode = $event"
          />

          <div class="grid gap-4 sm:grid-cols-2">
            <SvInput
              id="default-trial-days"
              label="Default trial days"
              type="number"
              :model-value="form.default_trial_days"
              :disabled="!canUpdate"
              :errors="errors.default_trial_days"
              required
              @update:model-value="form.default_trial_days = $event"
            />
            <SvInput
              id="grace-days"
              label="Grace days"
              type="number"
              :model-value="form.grace_days"
              :disabled="!canUpdate"
              :errors="errors.grace_days"
              required
              @update:model-value="form.grace_days = $event"
            />
          </div>

          <SvInput
            id="currency"
            label="Currency (ISO 4217)"
            :model-value="form.currency"
            :disabled="!canUpdate"
            :errors="errors.currency"
            hint="Three-letter uppercase code, e.g. KES."
            required
            @update:model-value="form.currency = $event"
          />

          <p
            v-if="actionError"
            class="text-sm text-error"
            role="alert"
          >
            {{ actionError }}
          </p>

          <div v-if="canUpdate">
            <SvButton
              type="submit"
              :loading="submitting"
            >
              Save billing settings
            </SvButton>
          </div>
        </form>
      </SvCard>
    </SvStateBoundary>
  </section>
</template>
