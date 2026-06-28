<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { apiClient } from '@/services/apiClient';
import { useCatalogueStore } from '@/stores/catalogueStore';
import { useNotificationStore } from '@/stores/notificationStore';
import type { StaffProfile } from '@/types/models';

// HR personnel-service eligibility (Plan §19.3, §39; Phase 15A). HR manages which
// personnel may perform which service, within HR's own branch. The API
// (`personnel.eligibility.manage` + same-branch validation) is the boundary.
const catalogue = useCatalogueStore();
const notifications = useNotificationStore();

const selectedService = ref('');
const staff = ref<StaffProfile[]>([]);
const staffToAdd = ref('');
const loadingEligibility = ref(false);

const serviceOptions = computed(() => catalogue.services.map((s) => ({ value: s.id, label: s.name })));

const eligibleIds = computed(() => new Set(catalogue.eligibility.filter((e) => e.active).map((e) => e.staff_profile_id)));

const assignableStaff = computed(() =>
  staff.value
    .filter((s) => !eligibleIds.value.has(s.id))
    .map((s) => ({ value: s.id, label: s.display_name })),
);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (selectedService.value === '') return 'empty';
  if (loadingEligibility.value) return 'loading';
  return 'success';
});

onMounted(async () => {
  await catalogue.fetchServices({ status: 'active' });
  const { data } = await apiClient.get<{ data: StaffProfile[] }>('/staff');
  staff.value = data.data;
});

watch(selectedService, async (id) => {
  if (id === '') return;
  loadingEligibility.value = true;
  try {
    await catalogue.fetchEligibility(id);
  } finally {
    loadingEligibility.value = false;
  }
});

async function assign(): Promise<void> {
  if (selectedService.value === '' || staffToAdd.value === '') return;
  try {
    await catalogue.assignEligibility(selectedService.value, staffToAdd.value);
    notifications.addToast({ type: 'success', message: 'Eligibility added.' });
    staffToAdd.value = '';
  } catch (err: unknown) {
    const message = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Unable to assign eligibility.';
    notifications.addToast({ type: 'error', message });
  }
}

async function revoke(staffProfileId: string | null | undefined): Promise<void> {
  if (selectedService.value === '' || !staffProfileId) return;
  try {
    await catalogue.revokeEligibility(selectedService.value, staffProfileId);
    notifications.addToast({ type: 'success', message: 'Eligibility revoked.' });
  } catch {
    notifications.addToast({ type: 'error', message: 'Unable to revoke eligibility.' });
  }
}
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-brand-deep">
      Service eligibility
    </h1>
    <p class="mt-1 text-sm text-text-muted">
      Manage which personnel may perform each service in your branch.
    </p>

    <SvCard
      as="div"
      padding="md"
      class="mt-6"
    >
      <SvSelect
        id="service"
        v-model="selectedService"
        label="Service"
        placeholder="Select a service"
        :options="serviceOptions"
      />
    </SvCard>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="Select a service to manage its eligible personnel."
    >
      <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end gap-2">
          <div class="grow">
            <SvSelect
              id="staff-to-add"
              v-model="staffToAdd"
              label="Add eligible personnel"
              placeholder="Select personnel"
              :options="assignableStaff"
            />
          </div>
          <SvButton
            variant="primary"
            data-testid="assign-eligibility"
            :disabled="staffToAdd === ''"
            @click="assign"
          >
            Add
          </SvButton>
        </div>

        <ul
          class="flex flex-col gap-2"
          aria-label="Eligible personnel"
        >
          <li
            v-for="row in catalogue.eligibility.filter((e) => e.active)"
            :key="row.staff_profile_id ?? ''"
          >
            <SvCard
              as="div"
              padding="sm"
              class="flex items-center justify-between"
            >
              <span class="text-sm font-medium text-text">{{ row.staff_name }}</span>
              <button
                type="button"
                class="text-sm font-semibold text-error underline"
                :data-testid="`revoke-${row.staff_profile_id}`"
                @click="revoke(row.staff_profile_id)"
              >
                Revoke
              </button>
            </SvCard>
          </li>
          <li
            v-if="catalogue.eligibility.filter((e) => e.active).length === 0"
            class="text-sm text-text-muted"
          >
            No personnel are eligible for this service yet.
          </li>
        </ul>
      </div>
    </SvStateBoundary>
  </section>
</template>
