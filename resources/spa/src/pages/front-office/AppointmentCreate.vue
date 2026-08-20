<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import { apiClient } from '@/services/apiClient';
import { useAppointmentStore } from '@/stores/appointmentStore';
import { useClientStore } from '@/stores/clientStore';
import { useNotificationStore } from '@/stores/notificationStore';
import type { Client, Service, ServiceEligibility } from '@/types/models';
import { toBusinessIso } from '@/utils/appointment';

// Book a Front Office appointment (Plan §36; Phase 16A). The backend derives the
// merchant/branch/end-time/status; this form only collects safe public references.
// Availability/conflict feedback comes from the server error envelope. No fee.
const appointments = useAppointmentStore();
const clients = useClientStore();
const notifications = useNotificationStore();
const router = useRouter();

const clientId = ref('');
const serviceId = ref('');
const startsAt = ref('');
const assignedPersonnel = ref('');

const services = ref<Service[]>([]);
const eligible = ref<ServiceEligibility[]>([]);
const submitting = ref(false);
const formError = ref<string | null>(null);

const clientOptions = computed(() => clients.clients.map((c: Client) => ({ value: c.id, label: `${c.full_name} · ${c.phone_masked}` })));
const serviceOptions = computed(() => services.value.map((s) => ({ value: s.id, label: `${s.name} (${s.duration_minutes} min)` })));
const personnelOptions = computed(() => [
  { value: '', label: 'Assign later' },
  ...eligible.value
    .filter((e) => e.active && e.staff_profile_id)
    .map((e) => ({ value: e.staff_profile_id as string, label: e.staff_name ?? 'Personnel' })),
]);

const selectedService = computed(() => services.value.find((s) => s.id === serviceId.value) ?? null);

watch(serviceId, async (id) => {
  assignedPersonnel.value = '';
  eligible.value = [];
  if (id === '') return;
  try {
    const { data } = await apiClient.get<{ data: ServiceEligibility[] }>(`/services/${id}/eligibility`);
    eligible.value = data.data;
  } catch {
    eligible.value = [];
  }
});

async function submit(): Promise<void> {
  formError.value = null;
  submitting.value = true;
  try {
    const created = await appointments.createAppointment({
      client: clientId.value,
      service: serviceId.value,
      starts_at: toBusinessIso(startsAt.value),
      assigned_personnel: assignedPersonnel.value === '' ? undefined : assignedPersonnel.value,
    });
    notifications.addToast({ type: 'success', message: 'Appointment booked.' });
    await router.push({ name: 'front-office.appointment-detail', params: { appointmentUlid: created.id } });
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      formError.value = err.apiError.message;
    } else {
      formError.value = 'Something went wrong.';
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  void clients.fetchClients();
  void apiClient.get<{ data: Service[] }>('/services').then(({ data }) => {
    services.value = data.data.filter((s) => s.status === 'active');
  });
});
</script>

<template>
  <section class="mx-auto w-full max-w-lg p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Book an appointment
    </h1>

    <p
      v-if="formError"
      role="alert"
      class="mt-4 rounded-control border border-error/40 bg-error/10 p-3 text-sm text-text"
      data-testid="form-error"
    >
      {{ formError }}
    </p>

    <SvCard
      as="div"
      padding="lg"
      class="mt-6"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
      >
        <SvSelect
          id="client"
          v-model="clientId"
          label="Client"
          placeholder="Select a client"
          :options="clientOptions"
          required
        />
        <SvSelect
          id="service"
          v-model="serviceId"
          label="Service"
          placeholder="Select a service"
          :options="serviceOptions"
          required
        />
        <SvTextInput
          id="starts_at"
          v-model="startsAt"
          label="Start time"
          type="datetime-local"
          help="Branch business time (Africa/Nairobi)."
          required
        />
        <p
          v-if="selectedService"
          class="text-sm text-text-muted"
          data-testid="duration-preview"
        >
          Ends after {{ selectedService.duration_minutes }} minutes (calculated by the server).
        </p>
        <SvSelect
          id="assigned_personnel"
          v-model="assignedPersonnel"
          label="Assign personnel (optional)"
          :options="personnelOptions"
          :disabled="serviceId === ''"
        />
        <SvButton
          type="submit"
          variant="primary"
          :loading="submitting"
          :disabled="clientId === '' || serviceId === '' || startsAt === ''"
        >
          Book appointment
        </SvButton>
      </form>
    </SvCard>
  </section>
</template>
