<script setup lang="ts">
/**
 * Merchant Directory — Super Administrator contract page §5.4.11 (Phase UI-08).
 *
 * A platform-wide directory of SELF-REGISTERED merchants for governance and billing oversight.
 *
 * ## No embedded detail
 *
 * The consolidated screen this replaces showed the merchant detail in a pane beside the list, which
 * is why the detail had no address and could not be linked, bookmarked or reloaded. Here a row is a
 * LINK to `/merchants/:merchantUlid`. The directory holds no selected-merchant state and renders no
 * governance control — opening a merchant is a navigation, not a selection.
 *
 * ## What is deliberately absent
 *
 * No create-merchant, first-Administrator, impersonation, merchant-membership, branch-creation or
 * staff-creation control: read access to the directory grants no merchant operation, and the API
 * has no such endpoint to call.
 *
 * Export is NOT rendered. The contract describes a permissioned masked export, but no export
 * operation exists for platform merchant data; a button that cannot produce a file is worse than
 * its absence, so the gap is stated instead.
 *
 * Search and the plan / billing-mode / trial-cohort / overdue / risk filters are likewise absent:
 * `PlatformMerchantGovernanceController::index` allowlists `status` only.
 *
 * Split out of the consolidated `RegistrationMonitoring.vue` in Increment 9D. Routed in 7B.
 */
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvDataTable from '@/components/ui/SvDataTable.vue';
import SvFilterBar from '@/components/ui/SvFilterBar.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvResponsiveRecordList from '@/components/ui/SvResponsiveRecordList.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import type { SvColumn, SvDataState } from '@/components/ui/dataContract';
import { useCan } from '@/composables/useCan';
import { usePlatformMerchantStore, type PlatformMerchant } from '@/stores/platformMerchantStore';
import { merchantDetailLocation } from '@/components/platform/merchants/merchantRoutes';
import {
  OPERATIONAL_STATUS_FILTER_OPTIONS,
  billingLabel,
  billingTone,
  operationalLabel,
  operationalTone,
} from '@/components/platform/merchants/merchantStatus';

const store = usePlatformMerchantStore();
const { can } = useCan();

const canView = computed(() => can('platform.merchant.view'));

const columns: SvColumn<PlatformMerchant>[] = [
  { key: 'name', label: 'Merchant', priority: 'primary', value: (row) => row.name },
  { key: 'operational_status', label: 'Operational status', priority: 'secondary', value: (row) => operationalLabel(row.operational_status) },
  { key: 'billing_status', label: 'Billing status', priority: 'secondary', value: (row) => billingLabel(row.billing_status) },
  { key: 'registered_at', label: 'Registered', priority: 'detail', value: (row) => row.registered_at?.slice(0, 10) ?? '—' },
  { key: 'suspended_at', label: 'Suspended', priority: 'detail', value: (row) => row.suspended_at?.slice(0, 10) ?? '—' },
  { key: 'deactivated_at', label: 'Deactivated', priority: 'detail', value: (row) => row.deactivated_at?.slice(0, 10) ?? '—' },
];

const dataState = computed<SvDataState>(() => {
  if (!canView.value) return 'forbidden';
  if (store.loading) return 'loading';
  if (store.error !== null) return 'error';
  return store.merchants.length === 0 ? 'empty' : 'idle';
});

/**
 * The contract asks for a default sort that surfaces merchants needing attention while preserving a
 * neutral all-merchants view. The server returns newest-first and accepts no sort parameter, so the
 * neutral view is what it returns; "attention" is expressed as a status FILTER the operator chooses,
 * not as a client-side reordering of one page that would misrepresent the platform.
 */
const attentionCount = computed(
  () => store.merchants.filter((m) => m.operational_status === 'suspended' || m.billing_status === 'suspended_billing' || m.billing_status === 'overdue').length,
);

const activeFilterCount = computed(() => (store.filterStatus === '' ? 0 : 1));
const meta = computed(() => store.merchantMeta);

onMounted(() => {
  if (canView.value) void load();
});

async function load(): Promise<void> {
  await store.fetchMerchants();
}

async function applyStatus(value: string): Promise<void> {
  store.filterStatus = value;
  store.merchantPage = 1;
  await load();
}

async function clearFilters(): Promise<void> {
  await applyStatus('');
}

async function goToPage(page: number): Promise<void> {
  store.merchantPage = page;
  await load();
}
</script>

<template>
  <div
    class="mx-auto w-full max-w-5xl"
    data-testid="platform-merchant-directory-screen"
  >
    <SvPageHeader
      title="Merchant directory"
      eyebrow="Merchants"
      description="Every merchant on the platform. Open a merchant to review its governance record — this directory grants no merchant operation."
    />

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <p
        v-if="attentionCount > 0"
        class="mb-4 text-sm text-sv-text-muted"
        data-testid="directory-attention-summary"
      >
        {{ attentionCount }} of the merchants on this page are operationally suspended, overdue or
        billing-suspended. Filter by operational status to review them.
      </p>

      <SvFilterBar
        label="Directory filters"
        :active-count="activeFilterCount"
        @clear="clearFilters"
      >
        <SvSelect
          id="directory-status-filter"
          label="Operational status"
          :model-value="store.filterStatus"
          :options="OPERATIONAL_STATUS_FILTER_OPTIONS"
          @update:model-value="applyStatus($event)"
        />
      </SvFilterBar>

      <!-- Desktop and tablet: a semantic table. -->
      <div class="hidden md:block">
        <SvDataTable
          :columns="columns"
          :rows="store.merchants"
          :row-key="(row) => row.id"
          caption="Platform merchant directory"
          :state="dataState"
          :error-message="store.error ?? undefined"
          empty-message="No merchants match this view. Merchants appear here after they self-register."
          @retry="load"
        >
          <template #cell:name="{ row }">
            <RouterLink
              :to="merchantDetailLocation(row.id)"
              class="font-medium text-sv-text underline underline-offset-2"
              :data-testid="`merchant-directory-link-${row.id}`"
            >
              {{ row.name }}
            </RouterLink>
          </template>
          <template #cell:operational_status="{ row }">
            <SvStatusBadge
              :label="operationalLabel(row.operational_status)"
              :tone="operationalTone(row.operational_status)"
              size="sm"
              sr-prefix="Operational status:"
            />
          </template>
          <template #cell:billing_status="{ row }">
            <SvStatusBadge
              :label="billingLabel(row.billing_status)"
              :tone="billingTone(row.billing_status)"
              size="sm"
              sr-prefix="Billing status:"
            />
          </template>
        </SvDataTable>
      </div>

      <!-- Mobile: labelled record cards, never a sideways-scrolling table. -->
      <div class="md:hidden">
        <SvResponsiveRecordList
          :columns="columns"
          :rows="store.merchants"
          :row-key="(row) => row.id"
          caption="Platform merchant directory"
          :state="dataState"
          :error-message="store.error ?? undefined"
          empty-message="No merchants match this view. Merchants appear here after they self-register."
          @retry="load"
        >
          <template #cell:name="{ row }">
            <RouterLink
              :to="merchantDetailLocation(row.id)"
              class="font-medium text-sv-text underline underline-offset-2"
              :data-testid="`merchant-directory-link-${row.id}`"
            >
              {{ row.name }}
            </RouterLink>
          </template>
          <template #cell:operational_status="{ row }">
            <SvStatusBadge
              :label="operationalLabel(row.operational_status)"
              :tone="operationalTone(row.operational_status)"
              size="sm"
              sr-prefix="Operational status:"
            />
          </template>
          <template #cell:billing_status="{ row }">
            <SvStatusBadge
              :label="billingLabel(row.billing_status)"
              :tone="billingTone(row.billing_status)"
              size="sm"
              sr-prefix="Billing status:"
            />
          </template>
        </SvResponsiveRecordList>
      </div>

      <SvPagination
        v-if="meta !== null && meta.last_page > 1"
        class="mt-4"
        :current-page="meta.current_page"
        :last-page="meta.last_page"
        :total="meta.total"
        label="Directory pages"
        data-testid="directory-pagination"
        @change="goToPage"
      />

      <SvAlert
        severity="info"
        title="Not yet available on this page"
        class="mt-8"
        data-testid="directory-unavailable-evidence"
      >
        <ul class="list-disc space-y-1 pl-5">
          <li>
            Plan, billing interval, branch count, staff count, overdue amount and last activity are
            not carried by the merchant read, so no column claims them.
          </li>
          <li>
            Search and the plan, billing-mode, trial-cohort, overdue and risk filters are not
            accepted by the shipped read; operational status is the only filter it allowlists.
          </li>
          <li>
            Saved filters and a masked directory export have no operation behind them. No export
            control is shown, because one that cannot produce a file would be a false affordance.
          </li>
        </ul>
      </SvAlert>

      <p
        class="mt-4 text-xs text-sv-text-muted"
        data-testid="directory-boundary-note"
      >
        Merchants self-register. This page offers no way to create a merchant, create its first
        administrator, sign in as one of its users, or change anything a merchant operates.
      </p>
    </template>
  </div>
</template>
