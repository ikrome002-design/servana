<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import {
  GENERAL_SETTINGS_KEYS,
  usePlatformBillingSettingsStore,
} from '@/stores/platformBillingSettingsStore';

// Phase 20A — general platform settings (the allowlisted `settings` jsonb keys only).
// Read requires `platform.settings.view`; update requires `platform.settings.update` plus a
// fresh step-up (server-enforced). No arbitrary JSON editing is offered. Saving creates the
// next effective version, sharing the platform_billing_settings record.
const store = usePlatformBillingSettingsStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canUpdate = computed(() => can('platform.settings.update'));

const values = reactive<Record<string, string>>({});
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
  const settings = store.current?.settings ?? {};
  for (const { key } of GENERAL_SETTINGS_KEYS) {
    const raw = (settings as Record<string, unknown>)[key];
    values[key] = raw === null || raw === undefined ? '' : String(raw);
  }
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
  const payload: Record<string, string | null> = {};
  for (const { key } of GENERAL_SETTINGS_KEYS) {
    const v = values[key]?.trim() ?? '';
    payload[key] = v === '' ? null : v;
  }
  try {
    await store.updateGeneralSettings(payload);
    notifications.addToast({ type: 'success', message: 'General settings updated. A new effective version was created.' });
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      Object.entries(err.apiError.fields).forEach(([field, messages]) => {
        // Server keys nest under `settings.<key>` — surface against the field.
        const short = field.replace(/^settings\./, '');
        errors[short] = messages;
      });
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
  <section aria-labelledby="general-settings-heading">
    <h2
      id="general-settings-heading"
      class="font-display text-lg font-bold text-heading"
    >
      General settings
    </h2>
    <p class="mt-1 text-sm text-text-muted">
      Documented platform settings. Only the listed keys are editable — there is no free-form
      JSON. Saving creates a new effective-dated version. A fresh step-up is required.
    </p>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      empty-message="No platform settings have been configured yet."
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
            You have read-only access to general settings.
          </p>

          <SvInput
            v-for="entry in GENERAL_SETTINGS_KEYS"
            :id="`setting-${entry.key}`"
            :key="entry.key"
            :label="entry.label"
            :hint="entry.hint"
            :model-value="values[entry.key] ?? ''"
            :disabled="!canUpdate"
            :errors="errors[entry.key]"
            @update:model-value="values[entry.key] = $event"
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
              Save general settings
            </SvButton>
          </div>
        </form>
      </SvCard>
    </SvStateBoundary>
  </section>
</template>
