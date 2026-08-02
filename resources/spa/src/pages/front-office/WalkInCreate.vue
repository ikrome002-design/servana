<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import { apiClient } from '@/services/apiClient';
import { useClientStore } from '@/stores/clientStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { useQueueStore } from '@/stores/queueStore';
import type { QueueAssignmentMode, Service, ServiceEligibility } from '@/types/models';
import { SvIconBack } from '@/design-system/icons';

// Front Office walk-in wizard (Plan §37; Phase 16B). Find an existing branch client
// OR create one through the existing client workflow, pick a service + assignment
// mode, select personnel where required, then create the walk-in + queue entry
// atomically. The wait estimate is server-calculated and labelled "Estimate" on the
// resulting board. No preferred-personnel fee is shown or charged (Phase 20A).
const router = useRouter();
const queue = useQueueStore();
const clients = useClientStore();
const notifications = useNotificationStore();

const clientMode = ref<'existing' | 'new'>('existing');
const existingClientId = ref('');
const newClient = ref({ full_name: '', phone: '' });
const serviceId = ref('');
const assignmentMode = ref<QueueAssignmentMode>('next_available');
const personnelId = ref('');
const services = ref<Service[]>([]);
const eligible = ref<ServiceEligibility[]>([]);
const working = ref(false);

const serviceOptions = computed(() => [
  { value: '', label: 'Select a service' },
  ...services.value.map((s) => ({ value: s.id, label: `${s.name} (${s.duration_minutes} min)` })),
]);
const clientOptions = computed(() => [
  { value: '', label: 'Select a client' },
  ...clients.clients.map((c) => ({ value: c.id, label: `${c.full_name} · ${c.phone_masked}` })),
]);
const personnelOptions = computed(() =>
  eligible.value
    .filter((e) => e.active && e.staff_profile_id)
    .map((e) => ({ value: e.staff_profile_id as string, label: e.staff_name ?? 'Personnel' })),
);
const personnelRequired = computed(
  () => assignmentMode.value === 'manual' || assignmentMode.value === 'preferred_personnel',
);
const canSubmit = computed(() => {
  if (serviceId.value === '') return false;
  if (clientMode.value === 'existing' && existingClientId.value === '') return false;
  if (clientMode.value === 'new' && (newClient.value.full_name === '' || newClient.value.phone === '')) return false;
  if (personnelRequired.value && personnelId.value === '') return false;
  return true;
});

async function loadEligibility(): Promise<void> {
  eligible.value = [];
  personnelId.value = '';
  if (serviceId.value === '') return;
  try {
    const { data } = await apiClient.get<{ data: ServiceEligibility[] }>(`/services/${serviceId.value}/eligibility`);
    eligible.value = data.data;
  } catch {
    eligible.value = [];
  }
}

watch(serviceId, () => void loadEligibility());

async function submit(): Promise<void> {
  working.value = true;
  try {
    const entry = await queue.createWalkIn({
      assignment_mode: assignmentMode.value,
      service: serviceId.value,
      client: clientMode.value === 'existing' ? existingClientId.value : null,
      new_client: clientMode.value === 'new' ? newClient.value : null,
      personnel: assignmentMode.value === 'manual' ? personnelId.value : null,
      preferred_personnel: assignmentMode.value === 'preferred_personnel' ? personnelId.value : null,
    });
    notifications.addToast({ type: 'success', message: 'Walk-in added to the queue.' });
    await router.push({ name: 'front-office.queue.detail', params: { id: entry.id } });
  } catch (err: unknown) {
    const m = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Something went wrong.';
    notifications.addToast({ type: 'error', message: m });
  } finally {
    working.value = false;
  }
}

onMounted(async () => {
  try {
    const { data } = await apiClient.get<{ data: Service[] }>('/services', { params: { status: 'active' } });
    services.value = data.data;
  } catch {
    services.value = [];
  }
  void clients.fetchClients();
});
</script>

<template>
  <section class="mx-auto w-full max-w-xl p-4 md:p-6">
    <RouterLink
      :to="{ name: 'front-office.queue' }"
      class="text-sm font-semibold text-heading underline"
    >
      <SvIconBack
        aria-hidden="true"
        class="mr-1 inline-block h-4 w-4 align-text-bottom"
      />Back to the queue
    </RouterLink>

    <h1 class="mt-3 font-display text-2xl font-bold text-heading">
      Start a walk-in
    </h1>

    <SvCard
      as="div"
      padding="lg"
      class="mt-4"
    >
      <form
        class="flex flex-col gap-5"
        novalidate
        @submit.prevent="submit"
      >
        <fieldset class="flex flex-col gap-3">
          <legend class="text-sm font-semibold text-text">
            Client
          </legend>
          <div class="flex gap-2">
            <SvButton
              type="button"
              :variant="clientMode === 'existing' ? 'primary' : 'secondary'"
              data-testid="client-existing"
              @click="clientMode = 'existing'"
            >
              Existing client
            </SvButton>
            <SvButton
              type="button"
              :variant="clientMode === 'new' ? 'primary' : 'secondary'"
              data-testid="client-new"
              @click="clientMode = 'new'"
            >
              New client
            </SvButton>
          </div>

          <template v-if="clientMode === 'existing'">
            <SvTextInput
              id="client-search"
              :model-value="clients.lastQuery"
              label="Search clients"
              placeholder="Name or phone"
              @update:model-value="(v: string) => clients.fetchClients(v)"
            />
            <SvSelect
              id="existing-client"
              v-model="existingClientId"
              label="Client"
              :options="clientOptions"
              required
            />
          </template>

          <template v-else>
            <SvTextInput
              id="new-client-name"
              v-model="newClient.full_name"
              label="Full name"
              required
            />
            <SvTextInput
              id="new-client-phone"
              v-model="newClient.phone"
              label="Phone"
              type="tel"
              required
            />
          </template>
        </fieldset>

        <SvSelect
          id="walk-in-service"
          v-model="serviceId"
          label="Service"
          :options="serviceOptions"
          required
        />

        <SvSelect
          id="assignment-mode"
          v-model="assignmentMode"
          label="Assignment"
          :options="[
            { value: 'next_available', label: 'Next available' },
            { value: 'manual', label: 'Manual' },
            { value: 'preferred_personnel', label: 'Preferred personnel' },
          ]"
        />

        <SvSelect
          v-if="personnelRequired"
          id="walk-in-personnel"
          v-model="personnelId"
          label="Personnel"
          placeholder="Select personnel"
          :options="personnelOptions"
          required
        />

        <p class="text-xs text-text-muted">
          The wait time shown on the queue is an estimate, not a guaranteed service time.
        </p>

        <SvButton
          type="submit"
          variant="primary"
          :loading="working"
          :disabled="!canSubmit"
          data-testid="submit-walk-in"
        >
          Add to queue
        </SvButton>
      </form>
    </SvCard>
  </section>
</template>
