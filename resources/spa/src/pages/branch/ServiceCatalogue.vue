<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useForm } from '@/composables/useForm';
import { useCatalogueStore } from '@/stores/catalogueStore';
import { useNotificationStore } from '@/stores/notificationStore';
import type { Service } from '@/types/models';

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
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-brand-deep">
        Services
      </h1>
      <PermissionGate permission="service.create">
        <SvButton
          variant="primary"
          data-testid="add-service"
          @click="openCreate"
        >
          Add service
        </SvButton>
      </PermissionGate>
    </div>

    <div class="mt-4 flex gap-2">
      <SvButton
        :variant="statusFilter === 'active' ? 'primary' : 'ghost'"
        @click="statusFilter = 'active'; catalogue.fetchServices({ status: 'active' })"
      >
        Active
      </SvButton>
      <SvButton
        :variant="statusFilter === 'archived' ? 'primary' : 'ghost'"
        @click="statusFilter = 'archived'; catalogue.fetchServices({ status: 'archived' })"
      >
        Archived
      </SvButton>
    </div>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="No services in this branch yet. Add your first service."
      error-message="We couldn’t load services."
      @retry="load"
    >
      <ul
        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
        aria-label="Services"
      >
        <li
          v-for="service in catalogue.services"
          :key="service.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex items-start justify-between gap-2">
              <h2 class="font-display text-base font-semibold text-brand-deep">
                {{ service.name }}
              </h2>
              <span
                class="rounded-full px-2 py-0.5 text-xs font-medium"
                :class="service.status === 'active' ? 'bg-success/15 text-success' : 'bg-surface-alt text-text-muted'"
                data-testid="service-status"
              >{{ service.status }}</span>
            </div>
            <p class="mt-1 text-sm text-text-muted">
              {{ service.category_name ?? '—' }} · {{ service.duration_minutes }} min
            </p>
            <p class="mt-2 font-semibold text-text">
              {{ money(service.price_minor, service.currency) }}
            </p>
            <div
              v-if="service.status === 'active'"
              class="mt-3 flex gap-3"
            >
              <PermissionGate permission="service.update">
                <button
                  type="button"
                  class="text-sm font-semibold text-brand-deep underline"
                  :data-testid="`edit-${service.id}`"
                  @click="openEdit(service)"
                >
                  Edit
                </button>
              </PermissionGate>
              <PermissionGate permission="service.archive">
                <button
                  type="button"
                  class="text-sm font-semibold text-error underline"
                  :data-testid="`archive-${service.id}`"
                  @click="archiveTarget = service"
                >
                  Archive
                </button>
              </PermissionGate>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <!-- Create / edit modal -->
    <SvModal
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
        <SvInput
          id="name"
          v-model="form.values.name"
          label="Service name"
          required
          :errors="form.errors.name"
        />
        <SvTextarea
          id="description"
          v-model="form.values.description"
          label="Description"
          :errors="form.errors.description"
        />
        <SvInput
          id="price_major"
          v-model="form.values.price_major"
          label="Price (KES)"
          required
          :errors="form.errors.price_minor"
        />
        <SvInput
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
    </SvModal>

    <!-- Archive confirmation -->
    <SvModal
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
    </SvModal>
  </section>
</template>
