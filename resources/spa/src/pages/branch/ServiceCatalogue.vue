<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import BranchPreferredFeeCard from '@/pages/branch/BranchPreferredFeeCard.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { SvIconClock } from '@/design-system/icons';
import { navigationIcon } from '@/navigation/navigationIcons';
import { useForm } from '@/composables/useForm';
import { useCatalogueStore } from '@/stores/catalogueStore';
import { useNotificationStore } from '@/stores/notificationStore';
import type { Service } from '@/types/models';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

// Branch Manager service catalogue (Plan §39; Phase 15A). Backend owns authority
// (`service.*` + ServicePolicy); the buttons here are UX-only permission gates.
const catalogue = useCatalogueStore();
const notifications = useNotificationStore();

const statusFilter = ref<'active' | 'archived'>('active');
const editing = ref<Service | null>(null);
const showForm = ref(false);
const archiveTarget = ref<Service | null>(null);

const form = useForm<{ category_id: string; name: string; description: string; price_major: string; duration_minutes: string }>({
  category_id: '',
  name: '',
  description: '',
  price_major: '',
  duration_minutes: '',
});

const categoryOptions = computed(() => catalogue.categories.map((c) => ({ value: c.id, label: c.name })));

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (catalogue.loading) return 'loading';
  if (catalogue.error) return 'error';
  if (catalogue.services.length === 0) return 'empty';
  return 'success';
});

function money(minor: number, currency: string): string {
  return `${currency} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

async function load(): Promise<void> {
  await Promise.all([catalogue.fetchCategories(), catalogue.fetchServices({ status: statusFilter.value })]);
}

onMounted(load);

function openCreate(): void {
  editing.value = null;
  form.reset();
  showForm.value = true;
}

function openEdit(service: Service): void {
  editing.value = service;
  form.values.category_id = service.category_id ?? '';
  form.values.name = service.name;
  form.values.description = service.description ?? '';
  form.values.price_major = (service.price_minor / 100).toString();
  form.values.duration_minutes = service.duration_minutes.toString();
  showForm.value = true;
}

const submit = form.handleSubmit(async (values) => {
  const payload = {
    category_id: values.category_id,
    name: values.name,
    description: values.description === '' ? null : values.description,
    price_minor: Math.round(Number(values.price_major) * 100),
    duration_minutes: Number(values.duration_minutes),
  };
  try {
    if (editing.value) {
      await catalogue.updateService(editing.value.id, payload);
      notifications.addToast({ type: 'success', message: 'Service updated.' });
    } else {
      await catalogue.createService(payload);
      notifications.addToast({ type: 'success', message: 'Service created.' });
    }
    showForm.value = false;
    await catalogue.fetchServices({ status: statusFilter.value });
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      if (err.apiError.code === 'validation_failed') {
        form.mergeServerErrors(err.apiError);
        return;
      }
      notifications.addToast({ type: 'error', message: err.apiError.message });
      return;
    }
    notifications.addToast({ type: 'error', message: 'Something went wrong.' });
  }
});

async function confirmArchive(): Promise<void> {
  if (!archiveTarget.value) return;
  try {
    await catalogue.archiveService(archiveTarget.value.id);
    notifications.addToast({ type: 'success', message: 'Service archived.' });
    archiveTarget.value = null;
    await catalogue.fetchServices({ status: statusFilter.value });
  } catch (err: unknown) {
    const message = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Unable to archive service.';
    notifications.addToast({ type: 'error', message });
    archiveTarget.value = null;
  }
}

/**
 * The server's list, grouped by the category it already carries — presentation only, nothing
 * is filtered out or reordered within a group. A service with no category groups under 'Uncategorised'.
 */
const groupedServices = computed(() => {
  const groups = new Map<string, typeof catalogue.services>();
  for (const service of catalogue.services) {
    const key = service.category_name ?? 'Uncategorised';
    groups.set(key, [...(groups.get(key) ?? []), service]);
  }
  return [...groups.entries()].map(([category, services]) => ({ category, services }));
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — a scannable service catalogue. Status switches through a segmented
    control; services are GROUPED by their category (a presentation grouping of the server's own
    list) with duration and price set as the scan line; edit and archive stay permission-gated and
    precise. The platform's preferred-personnel fee sits read-only in the rail.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Services"
      eyebrow="Branch operations"
      description="The services your branch offers, with the prices and durations clients are booked against."
    >
      <template #actions>
        <PermissionGate permission="service.create">
          <SvButton
            variant="primary"
            data-testid="add-service"
            @click="openCreate"
          >
            Add service
          </SvButton>
        </PermissionGate>
      </template>
    </SvPageHeader>

    <div class="sv-r-grid sv-r-split">
      <div class="min-w-0">
        <div
          class="inline-flex flex-wrap gap-1 rounded-pill bg-sv-surface-raised p-1 shadow-card"
          role="group"
          aria-label="Service status"
        >
          <SvButton
            :variant="statusFilter === 'active' ? 'primary' : 'ghost'"
            :aria-pressed="statusFilter === 'active'"
            @click="statusFilter = 'active'; catalogue.fetchServices({ status: 'active' })"
          >
            Active
          </SvButton>
          <SvButton
            :variant="statusFilter === 'archived' ? 'primary' : 'ghost'"
            :aria-pressed="statusFilter === 'archived'"
            @click="statusFilter = 'archived'; catalogue.fetchServices({ status: 'archived' })"
          >
            Archived
          </SvButton>
        </div>

        <SvStateBoundary
          class="mt-5"
          :state="boundaryState"
          empty-message="No services in this branch yet. Add your first service."
          error-message="We couldn’t load services."
          @retry="load"
        >
          <div class="sv-r-stack">
            <section
              v-for="group in groupedServices"
              :key="group.category"
              :aria-label="group.category"
            >
              <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green mb-3">
                {{ group.category }} · {{ group.services.length }}
              </p>
              <ul
                class="grid grid-cols-1 gap-3 md:grid-cols-2"
                aria-label="Services"
              >
                <li
                  v-for="service in group.services"
                  :key="service.id"
                >
                  <article class="sv-r-record flex h-full flex-col">
                    <div class="flex items-start justify-between gap-2">
                      <div class="flex min-w-0 items-start gap-3">
                        <span
                          aria-hidden="true"
                          class="sv-r-icon sv-r-tone-green"
                        >
                          <component :is="navigationIcon('service')" />
                        </span>
                        <h2 class="min-w-0 break-words font-display text-base font-bold text-heading">
                          {{ service.name }}
                        </h2>
                      </div>
                      <span
                        class="sv-r-chip sv-r-chip--tone sv-r-chip--dot"
                        :class="service.status === 'active' ? 'sv-r-tone-green' : 'sv-r-tone-neutral'"
                        data-testid="service-status"
                      >{{ service.status }}</span>
                    </div>
                    <div class="mt-4 flex items-end justify-between gap-3">
                      <p class="sv-r-chip">
                        <SvIconClock
                          aria-hidden="true"
                          class="h-3.5 w-3.5"
                        />
                        {{ service.category_name ?? '—' }} · {{ service.duration_minutes }} min
                      </p>
                      <p class="sv-r-figure-md">
                        {{ money(service.price_minor, service.currency) }}
                      </p>
                    </div>
                    <div
                      v-if="service.status === 'active'"
                      class="mt-4 flex gap-2 border-t border-sv-border pt-3"
                    >
                      <PermissionGate permission="service.update">
                        <button
                          type="button"
                          class="sv-r-link sv-focus-ring rounded-control"
                          :data-testid="`edit-${service.id}`"
                          @click="openEdit(service)"
                        >
                          Edit
                        </button>
                      </PermissionGate>
                      <PermissionGate permission="service.archive">
                        <button
                          type="button"
                          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-2 text-sm font-semibold text-sv-error-fg"
                          :data-testid="`archive-${service.id}`"
                          @click="archiveTarget = service"
                        >
                          Archive
                        </button>
                      </PermissionGate>
                    </div>
                  </article>
                </li>
              </ul>
            </section>
          </div>
        </SvStateBoundary>
      </div>

      <aside
        class="sv-r-stack-sm sv-r-sticky"
        aria-label="Platform fee"
      >
        <!-- Phase 20A — read-only effective preferred-personnel fee (Branch Manager). UX gate
             only; the API enforces `preferred_personnel_fee.view_branch_rule`. No mutation. -->
        <PermissionGate permission="preferred_personnel_fee.view_branch_rule">
          <BranchPreferredFeeCard />
        </PermissionGate>
        <div class="sv-r-note sv-r-tone-green">
          <p class="sv-r-note__title">
            Branch-owned catalogue
          </p>
          <p class="sv-r-note__body">
            Services, prices and durations belong to this branch. Front Office books clients against them; Human Resource decides who may perform each one.
          </p>
        </div>
      </aside>
    </div>
    <!-- Create / edit modal -->
    <SvDialog
      :open="showForm"
      :title="editing ? 'Edit service' : 'Add service'"
      @close="showForm = false"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
      >
        <SvSelect
          id="category_id"
          v-model="form.values.category_id"
          label="Category"
          placeholder="Select a category"
          required
          :options="categoryOptions"
          :errors="form.errors.category_id"
        />
        <SvTextInput
          id="name"
          v-model="form.values.name"
          label="Service name"
          required
          :errors="form.errors.name"
        />
        <SvTextArea
          id="description"
          v-model="form.values.description"
          label="Description"
          :errors="form.errors.description"
        />
        <SvTextInput
          id="price_major"
          v-model="form.values.price_major"
          label="Price (KES)"
          required
          :errors="form.errors.price_minor"
        />
        <SvTextInput
          id="duration_minutes"
          v-model="form.values.duration_minutes"
          label="Duration (minutes)"
          required
          :errors="form.errors.duration_minutes"
        />
        <div class="flex justify-end gap-2">
          <SvButton
            type="button"
            variant="ghost"
            @click="showForm = false"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            variant="primary"
            :loading="form.submitting.value"
          >
            {{ editing ? 'Save changes' : 'Create service' }}
          </SvButton>
        </div>
      </form>
    </SvDialog>

    <!-- Archive confirmation -->
    <SvDialog
      :open="archiveTarget !== null"
      title="Archive service?"
      @close="archiveTarget = null"
    >
      <p class="text-sm text-text">
        Archiving <strong>{{ archiveTarget?.name }}</strong> removes it from active selection. This can be reviewed later.
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="archiveTarget = null"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="primary"
          data-testid="confirm-archive"
          @click="confirmArchive"
        >
          Archive
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
