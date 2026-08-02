<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useNotificationStore } from '@/stores/notificationStore';
import { useQueueStore } from '@/stores/queueStore';
import { SvIconBack } from '@/design-system/icons';

// Branch Manager queue configuration (Plan §37; Phase 16B). Sets queue open/close,
// capacity, and the default assignment mode on today's Branch Day — NOT an
// operational entry mutation (the backend enforces branch.profile.manage +
// day.open_close and rejects entry mutations). Capacity below the current active
// count is rejected by the backend.
const queue = useQueueStore();
const notifications = useNotificationStore();

const loading = ref(true);
const error = ref(false);
const working = ref(false);
const queueIsOpen = ref(false);
const capacity = ref<string>('');
const defaultMode = ref<'next_available' | 'manual'>('next_available');

const boundaryState = computed<'loading' | 'error' | 'success'>(() => {
  if (loading.value) return 'loading';
  if (error.value) return 'error';
  return 'success';
});
const activeCount = computed(() => queue.configuration?.active_count ?? 0);

async function load(): Promise<void> {
  loading.value = true;
  error.value = false;
  try {
    await queue.fetchConfiguration();
  } catch {
    error.value = true;
  } finally {
    loading.value = false;
  }
}

watch(
  () => queue.configuration,
  (config) => {
    if (config) {
      queueIsOpen.value = config.queue_is_open;
      capacity.value = config.queue_capacity === null ? '' : String(config.queue_capacity);
      defaultMode.value = config.queue_default_assignment_mode;
    }
  },
  { immediate: true },
);

async function save(): Promise<void> {
  working.value = true;
  try {
    await queue.updateConfiguration({
      queue_is_open: queueIsOpen.value,
      queue_capacity: capacity.value.trim() === '' ? null : Number(capacity.value),
      queue_default_assignment_mode: defaultMode.value,
    });
    notifications.addToast({ type: 'success', message: 'Queue settings saved.' });
  } catch (err: unknown) {
    const m = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Something went wrong.';
    notifications.addToast({ type: 'error', message: m });
  } finally {
    working.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section class="mx-auto w-full max-w-xl p-4 md:p-6">
    <RouterLink
      :to="{ name: 'branch.queue' }"
      class="text-sm font-semibold text-heading underline"
    >
      <SvIconBack
        aria-hidden="true"
        class="mr-1 inline-block h-4 w-4 align-text-bottom"
      />Back to the queue
    </RouterLink>

    <h1 class="mt-3 font-display text-2xl font-bold text-heading">
      Queue settings
    </h1>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      error-message="We couldn’t load the queue settings."
      @retry="load"
    >
      <SvCard
        as="div"
        padding="lg"
      >
        <p class="text-sm text-text-muted">
          {{ activeCount }} active in the queue today.
        </p>

        <form
          class="mt-4 flex flex-col gap-5"
          novalidate
          @submit.prevent="save"
        >
          <label class="flex items-center gap-3 text-sm font-medium text-text">
            <input
              v-model="queueIsOpen"
              type="checkbox"
              class="h-5 w-5"
              data-testid="queue-is-open"
            >
            Queue is open
          </label>

          <SvTextInput
            id="queue-capacity"
            v-model="capacity"
            label="Capacity"
            type="number"
            min="1"
            help="Leave blank for no limit. Cannot be set below the current active count."
          />

          <SvSelect
            id="default-mode"
            v-model="defaultMode"
            label="Default assignment"
            :options="[
              { value: 'next_available', label: 'Next available' },
              { value: 'manual', label: 'Manual' },
            ]"
          />

          <SvButton
            type="submit"
            variant="primary"
            :loading="working"
            data-testid="save-queue-config"
          >
            Save settings
          </SvButton>
        </form>
      </SvCard>
    </SvStateBoundary>
  </section>
</template>
