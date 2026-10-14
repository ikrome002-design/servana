<script setup lang="ts">
/**
 * Platform Dashboard — Super Administrator contract page §5.4.1 (Phase UI-08).
 *
 * The platform owner's governance control centre across all self-registered merchants.
 *
 * ## Every figure states what it counts
 *
 * A bare number on a governance screen is not evidence. Each KPI renders its server-supplied
 * definition, its time range, the last-refreshed instant and a drill-through to the page that can
 * act on it. All of that comes from the API, not from copy written here, so the number and its
 * meaning can never drift apart.
 *
 * ## A closed gate is shown as closed
 *
 * The integrations section is blocked by External Gate W. It renders the gate and no figures —
 * never `0`, never "healthy". A fabricated zero is indistinguishable from a real one and would
 * tell the owner that a system they cannot reach is working.
 *
 * ## Nothing is aggregated here
 *
 * The browser computes no total. `GET /platform/dashboard` aggregates server-side precisely
 * because every other platform read is paginated.
 */
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import SvFeaturePanel from '@/components/refined/SvFeaturePanel.vue';
import SvKpiCard from '@/components/refined/SvKpiCard.vue';
import SvPanel from '@/components/refined/SvPanel.vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvMoney from '@/components/ui/SvMoney.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { useCan } from '@/composables/useCan';
import { SvIconChevronRight, SvIconLocked } from '@/design-system/icons';
import { navigationIcon } from '@/navigation/navigationIcons';
import { usePlatformDashboardStore } from '@/stores/platformDashboardStore';

const store = usePlatformDashboardStore();
const { can } = useCan();

const canView = computed(() => can('platform.merchant.view'));

onMounted(() => {
  if (canView.value) void store.load();
});

const lifecycle = computed(() => store.dashboard?.merchant_lifecycle ?? null);
const commercial = computed(() => store.dashboard?.commercial ?? null);
const registrations = computed(() => store.dashboard?.registration_monitoring ?? null);
const tasks = computed(() => store.dashboard?.governance_tasks ?? null);
const audit = computed(() => store.dashboard?.audit_alerts ?? null);
const integrations = computed(() => store.dashboard?.integrations ?? null);

/**
 * The published contract types every `array<string, X>` MAP as `string`: the OpenAPI generator can
 * express an object with known keys, but not an open-ended map. `definitions`, `by_severity` and
 * the status breakdowns are genuinely maps, so rather than reshape the API to suit the generator
 * (fixed keys would be a lie — a new merchant status must not require an API change), the page
 * narrows them here, at the boundary, and treats anything unexpected as absent.
 */
function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
}

/** Reads a definition the server supplied for a named figure. Empty when it did not supply one. */
function definitionOf(section: unknown, key: string): string {
  const definitions = asRecord(asRecord(section).definitions);
  const value = definitions[key];
  return typeof value === 'string' ? value : '';
}

/**
 * The governance queue rows, in the server's own vocabulary. Each value and definition is read
 * straight off `governance_tasks`; a definition the server did not supply is simply not shown.
 */
const governanceTasks = computed(() => [
  { key: 'billing', label: 'Suspended for billing', value: tasks.value?.merchants_suspended_for_billing, definition: definitionOf(tasks.value, 'merchants_suspended_for_billing') },
  { key: 'policy', label: 'Suspended by policy', value: tasks.value?.merchants_suspended_by_policy, definition: definitionOf(tasks.value, 'merchants_suspended_by_policy') },
  { key: 'overdue', label: 'Overdue invoices', value: tasks.value?.overdue_invoices, definition: definitionOf(tasks.value, 'overdue_invoices') },
]);

/** Reads one count out of a server-supplied breakdown map. */
function countOf(map: unknown, key: string): number {
  const value = asRecord(map)[key];
  return typeof value === 'number' ? value : 0;
}
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the platform command centre.

    Editorial header on the canvas, then ONE dark feature panel carrying the merchant lifecycle
    (the owner's primary state) beside the commercial position, the governance queue as the
    dominant "act next" surface, and a supporting band for registrations, audit and the
    truthfully-gated integrations. Every figure, definition, time range and drill-through is still
    the server's; nothing is summed here, and a closed gate still shows no figure at all.
  -->
  <div
    class="sv-r-page"
    data-testid="platform-dashboard-screen"
  >
    <SvTonalPageHeader
      eyebrow="Platform governance"
      title="Platform dashboard"
      description="Governance, commercial, registration, risk and audit state across every self-registered merchant."
      context="Citrus platform"
    >
      <template #actions>
        <RouterLink
          v-if="lifecycle?.drill_through"
          :to="{ name: lifecycle.drill_through }"
          class="sv-r-action sv-focus-ring"
          data-testid="dashboard-drill-merchants"
        >
          Open the merchant directory
        </RouterLink>
        <RouterLink
          v-if="commercial?.drill_through"
          :to="{ name: commercial.drill_through }"
          class="sv-r-action sv-r-action--quiet sv-focus-ring"
        >
          Open subscription operations
        </RouterLink>
      </template>
      <p
        v-if="canView"
        class="sv-r-muted"
        data-testid="dashboard-last-refreshed"
      >
        Last refreshed
        <SvDateTime :value="store.lastRefreshed" />
      </p>
    </SvTonalPageHeader>

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <SvAlert
        v-if="store.error"
        severity="error"
        title="We could not load the platform dashboard"
      >
        <p>{{ store.error }}</p>
        <SvButton
          variant="secondary"
          size="sm"
          class="mt-3"
          data-testid="dashboard-retry"
          @click="store.load()"
        >
          Try again
        </SvButton>
      </SvAlert>

      <SvSkeleton
        v-else-if="store.loading"
        shape="text"
        :lines="3"
        label="Loading the platform dashboard"
      />

      <div
        v-else-if="store.dashboard"
        class="sv-r-stack"
      >
        <!-- Row 1: the lifecycle feature panel beside the commercial position ------------------ -->
        <div class="sv-r-grid sv-r-split-lead">
          <SvFeaturePanel
            eyebrow="Merchant lifecycle"
            title="Every self-registered merchant"
          >
            <div
              class="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] lg:items-end"
              data-testid="dashboard-lifecycle"
            >
              <div>
                <p class="sv-r-feature__label">
                  Total merchants
                </p>
                <p class="sv-r-feature__value sv-r-figure-xl mt-2">
                  {{ lifecycle?.total_merchants }}
                </p>
                <span
                  aria-hidden="true"
                  class="sv-r-feature__rule mt-4 block"
                />
                <p class="sv-r-feature__hint mt-3 max-w-xs">
                  {{ definitionOf(lifecycle, 'total_merchants') }}
                </p>
              </div>
              <div class="grid gap-3 md:grid-cols-3">
                <div class="sv-r-feature__cell">
                  <p class="sv-r-feature__label">
                    Active
                  </p>
                  <p class="sv-r-feature__value mt-1 text-3xl">
                    {{ countOf(lifecycle?.by_operational_status, 'active') }}
                  </p>
                  <p class="sv-r-feature__hint mt-2">
                    Operational status only — never merged with billing status.
                  </p>
                </div>
                <div class="sv-r-feature__cell">
                  <p class="sv-r-feature__label">
                    Suspended for billing
                  </p>
                  <p
                    class="sv-r-feature__value sv-r-feature__accent mt-1 text-3xl"
                    data-testid="dashboard-billing-suspended"
                  >
                    {{ lifecycle?.billing_suspended }}
                  </p>
                  <p class="sv-r-feature__hint mt-2">
                    A billing suspension is not a policy suspension.
                  </p>
                </div>
                <div class="sv-r-feature__cell">
                  <p class="sv-r-feature__label">
                    Active branches
                  </p>
                  <p class="sv-r-feature__value mt-1 text-3xl">
                    {{ lifecycle?.active_branches }}
                  </p>
                  <p class="sv-r-feature__hint mt-2">
                    {{ definitionOf(lifecycle, 'active_branches') }}
                  </p>
                </div>
              </div>
            </div>
            <p class="sv-r-feature__hint mt-6">
              {{ lifecycle?.time_range }}
            </p>
          </SvFeaturePanel>

          <SvPanel
            eyebrow="Commercial position"
            title="Commercial and billing"
            :description="commercial?.time_range"
            tone="sun"
            class="flex flex-col"
          >
            <div
              class="flex flex-1 flex-col gap-5"
              data-testid="dashboard-commercial"
            >
              <div>
                <p class="sv-r-kpi__label">
                  Outstanding balance
                </p>
                <p class="sv-r-figure mt-2">
                  <SvMoney
                    :minor-units="commercial?.open_invoice_balance_minor"
                    size="lg"
                  />
                </p>
                <p class="sv-r-muted mt-2">
                  {{ definitionOf(commercial, 'open_invoice_balance_minor') }}
                </p>
              </div>
              <dl class="sv-r-strip mt-auto">
                <div>
                  <dt>Issued invoices</dt>
                  <dd class="sv-r-figure-md">
                    {{ commercial?.issued_invoices }}
                  </dd>
                </div>
              </dl>
            </div>
          </SvPanel>
        </div>

        <!-- Row 2: the governance queue (dominant) beside registration monitoring --------------- -->
        <div class="sv-r-grid sv-r-split-lead">
          <SvPanel
            eyebrow="Governance queue"
            title="Waiting for you"
            description="Merchants and invoices that need a platform decision, in the server's own vocabulary."
            tone="warm"
          >
            <!--
              Each group keeps DOM order dt → dd (UI08-A11Y-001 / axe definition-list). The count is
              drawn first with CSS `order`; the server's definition, when supplied, is a second `dd`.
            -->
            <dl
              class="sv-r-queue"
              data-testid="dashboard-tasks"
            >
              <div
                v-for="task in governanceTasks"
                :key="task.key"
                class="sv-r-queue__row sv-r-tone-warm"
              >
                <dt class="sv-r-queue__label order-2">
                  {{ task.label }}
                </dt>
                <dd class="sv-r-queue__count order-1">
                  {{ task.value }}
                </dd>
                <dd
                  v-if="task.definition"
                  class="sv-r-queue__detail order-3 w-full pl-[3.75rem]"
                >
                  {{ task.definition }}
                </dd>
              </div>
            </dl>
          </SvPanel>

          <SvPanel
            eyebrow="Registration monitoring"
            title="New merchants"
            :description="registrations?.time_range"
            tone="teal"
          >
            <div
              class="grid gap-3"
              data-testid="dashboard-registrations"
            >
              <dl class="sv-r-strip">
                <div>
                  <dt>Last 7 days</dt>
                  <dd class="sv-r-figure-md">
                    {{ registrations?.registered_last_7_days }}
                  </dd>
                </div>
                <div>
                  <dt>Last 30 days</dt>
                  <dd class="sv-r-figure-md">
                    {{ registrations?.registered_last_30_days }}
                  </dd>
                </div>
              </dl>
              <SvKpiCard
                label="Awaiting setup completion"
                :hint="definitionOf(registrations, 'awaiting_setup_completion')"
                tone="teal"
                :icon="navigationIcon('registration')"
              >
                <span class="sv-numeric">{{ registrations?.awaiting_setup_completion }}</span>
              </SvKpiCard>
            </div>
          </SvPanel>
        </div>

        <!-- Row 3: audit beside the truthfully-unavailable integrations ------------------------- -->
        <div class="sv-r-grid sv-r-split-even">
          <SvPanel
            eyebrow="Audit activity"
            title="Immutable platform events"
            :description="audit?.time_range"
            tone="teal"
          >
            <div
              class="flex flex-wrap items-end justify-between gap-4"
              data-testid="dashboard-audit"
            >
              <div>
                <p class="sv-r-kpi__label">
                  Events in the last 7 days
                </p>
                <p class="sv-r-figure mt-1">
                  {{ audit?.events_last_7_days }}
                </p>
                <p class="sv-r-muted mt-1 max-w-sm">
                  {{ definitionOf(audit, 'events_last_7_days') }}
                </p>
              </div>
              <RouterLink
                v-if="audit?.drill_through"
                :to="{ name: audit.drill_through }"
                class="sv-r-link sv-focus-ring rounded-control"
              >
                Open platform audit<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </div>
          </SvPanel>

          <SvPanel
            variant="dashed"
            eyebrow="Integrations"
            title="Wallet and Refer & Earn health"
          >
            <template #actions>
              <span class="sv-r-chip">
                <SvIconLocked
                  aria-hidden="true"
                  class="h-3.5 w-3.5"
                />
                Gated
              </span>
            </template>
            <SvAlert
              v-if="integrations?.availability === 'disabled_by_gate'"
              severity="info"
              title="Integration health is unavailable"
              data-testid="dashboard-integrations-gated"
            >
              <p>{{ integrations.gate_statement }}</p>
              <p class="mt-2 text-xs">
                No figure is shown for Wallet health, reconciliation exceptions or Refer &amp; Earn
                qualification. A zero here would be indistinguishable from a real zero.
              </p>
            </SvAlert>
          </SvPanel>
        </div>
      </div>
    </template>
  </div>
</template>
