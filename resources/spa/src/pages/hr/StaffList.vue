<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { useStaffStore } from '@/stores/staffStore';

const store = useStaffStore();
const filters = reactive({ search: '', role: '', status: '', employment_status: '', page: 1 });
const state = computed(() => (store.loading ? 'loading' : store.error ? 'error' : store.staff.length === 0 ? 'empty' : 'success'));

const roleOptions = [
  { value: '', label: 'All roles' },
  { value: 'branch_manager', label: 'Branch Manager' },
  { value: 'hr', label: 'Human Resource' },
  { value: 'finance', label: 'Finance' },
  { value: 'front_office', label: 'Front Office' },
  { value: 'personnel', label: 'Personnel' },
  { value: 'audit', label: 'Audit' },
];
const statusOptions = [
  { value: '', label: 'All access statuses' },
  { value: 'invited', label: 'Invited' },
  { value: 'active', label: 'Active' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'deactivated', label: 'Deactivated' },
];
const employmentOptions = [
  { value: '', label: 'All employment states' },
  { value: 'employed', label: 'Employed' },
  { value: 'on_leave', label: 'On leave' },
  { value: 'terminated', label: 'Terminated' },
];

function tone(status: string | null): SvStatusTone {
  if (status === 'active') return 'success';
  if (status === 'suspended' || status === 'invited') return 'warning';
  if (status === 'deactivated') return 'error';
  return 'neutral';
}

function humanize(value: string | null): string {
  return value ? value.replaceAll('_', ' ') : 'Not assigned';
}

async function load(page = 1): Promise<void> {
  filters.page = page;
  const params: Record<string, string | number> = { page, per_page: 20, sort: 'display_name' };
  if (filters.search.trim()) params.search = filters.search.trim();
  if (filters.role) params.role = filters.role;
  if (filters.status) params.status = filters.status;
  if (filters.employment_status) params.employment_status = filters.employment_status;
  await store.fetchStaff(params);
}

function reset(): void {
  filters.search = '';
  filters.role = '';
  filters.status = '';
  filters.employment_status = '';
  void load(1);
}

onMounted(() => {
  void load();
});
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="hr-staff-roster"
  >
    <SvPageHeader
      title="Staff roster"
      eyebrow="Staff"
      description="Search and review staff identities, access state and employment readiness within your assigned branch."
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep"
          :to="{ name: 'hr.staff-invite' }"
        >
          Invite staff
        </RouterLink>
      </template>
    </SvPageHeader>

    <SvCard
      as="form"
      class="grid gap-4 md:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_auto]"
      aria-label="Staff filters"
      @submit.prevent="load(1)"
    >
      <SvTextInput
        id="staff-search"
        v-model="filters.search"
        label="Search staff"
        type="search"
        placeholder="Name, title or phone"
      />
      <SvSelect
        id="staff-role"
        v-model="filters.role"
        label="Role"
        :options="roleOptions"
      />
      <SvSelect
        id="staff-access-status"
        v-model="filters.status"
        label="Access status"
        :options="statusOptions"
      />
      <SvSelect
        id="staff-employment-status"
        v-model="filters.employment_status"
        label="Employment"
        :options="employmentOptions"
      />
      <div class="flex items-end gap-2">
        <SvButton type="submit">
          Apply
        </SvButton>
        <SvButton
          type="button"
          variant="ghost"
          @click="reset"
        >
          Reset
        </SvButton>
      </div>
    </SvCard>

    <SvStateBoundary
      class="mt-5"
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No staff members match these filters."
      @retry="load(filters.page)"
    >
      <ul
        class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        aria-label="Staff members"
      >
        <li
          v-for="member in store.staff"
          :key="member.id"
        >
          <SvCard
            as="article"
            class="h-full border-t-4"
            :class="member.status === 'active' ? 'border-t-sv-success-border' : 'border-t-sv-warning-border'"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  {{ humanize(member.role) }}
                </p>
                <h2 class="mt-1 truncate font-display text-lg font-bold text-heading">
                  {{ member.display_name }}
                </h2>
                <p class="mt-1 text-sm text-text-muted">
                  {{ member.role_title ?? 'No role title' }}
                </p>
              </div>
              <SvStatusBadge
                data-testid="staff-status"
                :label="humanize(member.status)"
                :tone="tone(member.status)"
                size="sm"
                sr-prefix="Access status:"
              />
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt class="text-xs text-text-muted">
                  Employment
                </dt><dd class="capitalize text-text">
                  {{ humanize(member.employment_status) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Type
                </dt><dd class="capitalize text-text">
                  {{ humanize(member.employment_type) }}
                </dd>
              </div>
            </dl>
            <RouterLink
              class="mt-4 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'hr.staff-detail', params: { staffUlid: member.id } }"
            >
              View staff detail
            </RouterLink>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <SvPagination
      v-if="store.meta && store.meta.last_page > 1"
      class="mt-5"
      :current-page="store.meta.current_page"
      :last-page="store.meta.last_page"
      :per-page="store.meta.per_page"
      :total="store.meta.total"
      label="Staff roster pages"
      :disabled="store.loading"
      @change="load"
    />
  </section>
</template>
