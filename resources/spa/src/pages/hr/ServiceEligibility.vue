<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import { SvIconCheck } from '@/design-system/icons';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
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
const services = ref<Array<{ ulid: string; name: string }>>([]);
const staff = ref<StaffProfile[]>([]);
const staffToAdd = ref('');
const loadingEligibility = ref(false);

const serviceOptions = computed(() => services.value.map((service) => ({ value: service.ulid, label: service.name })));

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
  const [serviceResponse, staffResponse] = await Promise.all([
    apiClient.get<{ data: Array<{ ulid: string; name: string }> }>('/hr/service-options'),
    apiClient.get<{ data: StaffProfile[] }>('/staff', { params: { per_page: 100, status: 'active', employment_status: 'employed' } }),
  ]);
  services.value = serviceResponse.data.data;
  staff.value = staffResponse.data.data;
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
  <!--
    COR-UI08-UI12-VIS-001 — eligibility as a precise, branch-scoped SKILLS BOARD for one service at a
    time. The service is chosen in the rail; the board splits the branch's active personnel into
    two columns — eligible (each revocable) and not yet eligible (each addable) — so the rule reads
    at a glance, with the picker-and-add control kept as the accessible equivalent. HR never edits
    service names, prices or durations here; the server validates every change.
  -->
  <section
    class="sv-r-page"
    data-testid="hr-service-eligibility"
  >
    <SvPageHeader
      title="Service eligibility"
      eyebrow="Workforce readiness"
      description="Assign active branch services to personnel. Human Resource does not configure service names, prices or durations here."
    />

    <div class="sv-r-grid lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:items-start">
      <aside
        class="sv-r-stack-sm sv-r-sticky"
        aria-label="Choose a service"
      >
        <div class="sv-r-panel sv-r-panel--tonal sv-r-tone-green">
          <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green mb-3">
            Service
          </p>
          <SvSelect
            id="service"
            v-model="selectedService"
            label="Service"
            placeholder="Select a service"
            :options="serviceOptions"
          />
          <p class="sv-r-muted mt-3">
            {{ services.length }} active branch services.
          </p>
        </div>
        <div class="sv-r-note sv-r-tone-teal">
          <p class="sv-r-note__title">
            Same branch only
          </p>
          <p class="sv-r-note__body">
            Only personnel of your assigned branch can be made eligible. The server checks every change.
          </p>
        </div>
      </aside>

      <SvStateBoundary
        :state="boundaryState"
        empty-message="Select a service to manage its eligible personnel."
      >
        <div class="sv-r-stack">
          <div class="sv-r-panel sv-r-panel--sunken sv-r-panel--compact flex flex-wrap items-end gap-2">
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

          <div class="sv-r-grid sv-r-cols-2">
            <section aria-labelledby="eligible-heading">
              <h2
                id="eligible-heading"
                class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green mb-3"
              >
                Eligible · {{ catalogue.eligibility.filter((e) => e.active).length }}
              </h2>
              <ul
                class="flex flex-col gap-2"
                aria-label="Eligible personnel"
              >
                <li
                  v-for="row in catalogue.eligibility.filter((e) => e.active)"
                  :key="row.staff_profile_id ?? ''"
                  class="sv-r-queue__row flex-nowrap"
                >
                  <span
                    aria-hidden="true"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sv-success-bg text-sv-success-fg"
                  ><SvIconCheck class="h-4 w-4" /></span>
                  <span class="sv-r-queue__label">{{ row.staff_name }}</span>
                  <button
                    type="button"
                    class="sv-focus-ring inline-flex min-h-sv-touch shrink-0 items-center rounded-control px-2 text-sm font-semibold text-sv-error-fg"
                    :data-testid="`revoke-${row.staff_profile_id}`"
                    @click="revoke(row.staff_profile_id)"
                  >
                    Revoke
                  </button>
                </li>
                <li
                  v-if="catalogue.eligibility.filter((e) => e.active).length === 0"
                  class="sv-r-panel sv-r-panel--dashed text-sm text-text-muted"
                >
                  No personnel are eligible for this service yet.
                </li>
              </ul>
            </section>

            <section aria-labelledby="not-eligible-heading">
              <h2
                id="not-eligible-heading"
                class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-neutral mb-3"
              >
                Not yet eligible · {{ assignableStaff.length }}
              </h2>
              <ul
                class="flex flex-col gap-2"
                aria-label="Personnel not yet eligible"
              >
                <li
                  v-for="person in assignableStaff"
                  :key="person.value"
                  class="sv-r-queue__row flex-nowrap"
                >
                  <span
                    aria-hidden="true"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sv-surface-subtle font-display text-xs font-extrabold text-sv-text-heading"
                  >{{ person.label.slice(0, 2).toUpperCase() }}</span>
                  <span class="sv-r-queue__label">{{ person.label }}</span>
                  <button
                    type="button"
                    class="sv-r-link sv-focus-ring shrink-0 rounded-control px-2"
                    @click="staffToAdd = person.value; assign()"
                  >
                    Make eligible
                  </button>
                </li>
              </ul>
            </section>
          </div>
        </div>
      </SvStateBoundary>
    </div>
  </section>
</template>