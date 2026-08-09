<script setup lang="ts">
/**
 * Registration Monitoring — Super Administrator contract page §5.4.10 (Phase UI-08).
 *
 * Monitors self-registration WITHOUT introducing a manual activation path. Merchants self-register;
 * there is no approve, activate, create-merchant, first-administrator or KYC-gate control on this
 * page, and none exists in the API behind it.
 *
 * ## Why parts of the contract are stated as unavailable rather than rendered
 *
 * `MerchantRegistrationMonitorResource` exposes six fields: name, operational status, billing
 * status, pending setup, registered at, setup completed at. The contract also describes owner
 * email, a source/referral snapshot, duplicate-business warnings, velocity/IP/device risk metadata,
 * referral anomalies and governance-note history. None of those has a backing field. Rendering a
 * risk column that is always "none" would tell a governance operator that a registration has been
 * screened and cleared, which is a false statement — so the page names what it cannot show, and
 * why, instead. The same applies to the filters: the shipped index allowlists `status` only.
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
import { usePlatformMerchantStore, type RegistrationMonitorRow } from '@/stores/platformMerchantStore';
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

const canView = computed(() => can('platform.registration_monitor.view'));

const columns: SvColumn<RegistrationMonitorRow>[] = [
  { key: 'name', label: 'Merchant', priority: 'primary', value: (row) => row.name },
  { key: 'operational_status', label: 'Operational status', priority: 'secondary', value: (row) => operationalLabel(row.operational_status) },
  { key: 'billing_status', label: 'Billing status', priority: 'secondary', value: (row) => billingLabel(row.billing_status) },
  { key: 'pending_setup', label: 'Setup', priority: 'secondary', value: (row) => (row.pending_setup ? 'Pending' : 'Complete') },
  { key: 'registered_at', label: 'Registered', priority: 'detail', value: (row) => row.registered_at?.slice(0, 10) ?? '—' },
  { key: 'setup_completed_at', label: 'Setup completed', priority: 'detail', value: (row) => row.setup_completed_at?.slice(0, 10) ?? '—' },
];

const dataState = computed<SvDataState>(() => {
  if (!canView.value) return 'forbidden';
  if (store.loading) return 'loading';
  if (store.error !== null) return 'error';
  return store.registrations.length === 0 ? 'empty' : 'idle';
});

const activeFilterCount = computed(() => (store.registrationStatus === '' ? 0 : 1));
const meta = computed(() => store.registrationMeta);

onMounted(() => {
  if (canView.value) void load();
});

async function load(): Promise<void> {
  await store.fetchRegistrations();
}

async function applyStatus(value: string): Promise<void> {
  store.registrationStatus = value;
  store.registrationPage = 1;
  await load();
}

async function clearFilters(): Promise<void> {
  await applyStatus('');
}

async function goToPage(page: number): Promise<void> {
  store.registrationPage = page;
  await load();
}
</script>

<template>
  <div
    class="mx-auto w-full max-w-5xl"
    data-testid="platform-merchant-registrations-screen"
  >
    <SvPageHeader
      title="Registration monitoring"
      eyebrow="Merchants"
      description="Review self-registrations as they arrive. Merchants activate themselves — nothing here approves, activates or creates a merchant."
    />

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <SvFilterBar
        label="Registration filters"
        :active-count="activeFilterCount"
        @clear="clearFilters"
      >
        <SvSelect
          id="registration-status-filter"
          label="Operational status"
          :model-value="store.registrationStatus"
          :options="OPERATIONAL_STATUS_FILTER_OPTIONS"
          @update:model-value="applyStatus($event)"
        />
      </SvFilterBar>

      <!-- Desktop and tablet: a semantic table. -->
      <div class="hidden md:block">
        <SvDataTable
          :columns="columns"
          :rows="store.registrations"
          :row-key="(row) => row.id"
          caption="Merchant registrations, most recent first"
          :state="dataState"
          :error-message="store.error ?? undefined"
          empty-message="No merchant registrations to monitor yet. Merchants appear here as soon as they self-register."
          @retry="load"
        >
          <template #cell:name="{ row }">
            <RouterLink
              :to="merchantDetailLocation(row.id)"
              class="font-medium text-sv-text underline underline-offset-2"
              data-testid="registration-detail-link"
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
          :rows="store.registrations"
          :row-key="(row) => row.id"
          caption="Merchant registrations, most recent first"
          :state="dataState"
          :error-message="store.error ?? undefined"
          empty-message="No merchant registrations to monitor yet. Merchants appear here as soon as they self-register."
          @retry="load"
        >
          <template #cell:name="{ row }">
            <RouterLink
              :to="merchantDetailLocation(row.id)"
              class="font-medium text-sv-text underline underline-offset-2"
              data-testid="registration-detail-link"
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
        label="Registration pages"
        data-testid="registrations-pagination"
        @change="goToPage"
      />

      <!--
        Truthful unavailability. Every line names a field or filter the contract asks for and the
        shipped read does not carry, so the gap is visible to the operator instead of being papered
        over with an always-empty risk column.
      -->
      <SvAlert
        severity="info"
        title="Not yet available on this page"
        class="mt-8"
        data-testid="registrations-unavailable-evidence"
      >
        <ul class="list-disc space-y-1 pl-5">
          <li>
            Owner email, plan selection and trial start are not carried by the registration read, so
            they are not shown rather than guessed.
          </li>
          <li>
            Risk indicators, duplicate-business warnings, velocity, IP and device signals and
            referral anomalies have no backing field. No registration on this page has been screened
            for them.
          </li>
          <li>
            Governance notes and escalation are recorded only as the mandatory reason on a
            suspend, reactivate or deactivate action, on the merchant’s own page.
          </li>
          <li>
            Filtering is by operational status only; date, plan, setup-completion, risk and source
            filters are not accepted by the shipped read.
          </li>
        </ul>
      </SvAlert>

      <p
        class="mt-4 text-xs text-sv-text-muted"
        data-testid="registrations-no-activation-note"
      >
        There is no approve, activate or create-merchant action here, and none exists in the API.
        A registration becomes an operating merchant when its owner completes setup.
      </p>
    </template>
  </div>
</template>
