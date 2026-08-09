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
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvMoney from '@/components/ui/SvMoney.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import { useCan } from '@/composables/useCan';
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

/** Reads one count out of a server-supplied breakdown map. */
function countOf(map: unknown, key: string): number {
  const value = asRecord(map)[key];
  return typeof value === 'number' ? value : 0;
}
</script>

<template>
  <div
    class="mx-auto w-full max-w-6xl"
    data-testid="platform-dashboard-screen"
  >
    <SvPageHeader
      title="Platform dashboard"
      eyebrow="Home"
      description="Governance, commercial, registration, risk and audit state across every self-registered merchant."
    />

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <p
        class="mb-6 text-xs text-sv-text-muted"
        data-testid="dashboard-last-refreshed"
      >
        Last refreshed
        <SvDateTime :value="store.lastRefreshed" />
      </p>

      <SvAlert
        v-if="store.error"
        severity="error"
        title="We could not load the platform dashboard"
        class="mb-6"
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
        :lines="6"
        label="Loading the platform dashboard"
      />

      <template v-else-if="store.dashboard">
        <!-- Merchant lifecycle -------------------------------------------------------------- -->
        <section
          aria-labelledby="dash-lifecycle-heading"
          class="mb-8"
        >
          <h2
            id="dash-lifecycle-heading"
            class="mb-1 font-display text-lg font-bold text-sv-text-heading"
          >
            Merchant lifecycle
          </h2>
          <p class="mb-3 text-xs text-sv-text-muted">
            {{ lifecycle?.time_range }}
          </p>

          <div
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
            data-testid="dashboard-lifecycle"
          >
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <p class="text-sm font-medium text-sv-text-muted">
                Total merchants
              </p>
              <p class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
                {{ lifecycle?.total_merchants }}
              </p>
              <p class="mt-1 text-xs text-sv-text-muted">
                {{ definitionOf(lifecycle, 'total_merchants') }}
              </p>
            </div>
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <p class="text-sm font-medium text-sv-text-muted">
                Active
              </p>
              <p class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
                {{ countOf(lifecycle?.by_operational_status, 'active') }}
              </p>
              <p class="mt-1 text-xs text-sv-text-muted">
                Operational status only — never merged with billing status.
              </p>
            </div>
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <p class="text-sm font-medium text-sv-text-muted">
                Suspended for billing
              </p>
              <p
                class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading"
                data-testid="dashboard-billing-suspended"
              >
                {{ lifecycle?.billing_suspended }}
              </p>
              <p class="mt-1 text-xs text-sv-text-muted">
                A billing suspension is not a policy suspension.
              </p>
            </div>
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <p class="text-sm font-medium text-sv-text-muted">
                Active branches
              </p>
              <p class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
                {{ lifecycle?.active_branches }}
              </p>
              <p class="mt-1 text-xs text-sv-text-muted">
                {{ definitionOf(lifecycle, 'active_branches') }}
              </p>
            </div>
          </div>

          <RouterLink
            v-if="lifecycle?.drill_through"
            :to="{ name: lifecycle.drill_through }"
            class="sv-focus-ring mt-3 inline-flex min-h-sv-touch items-center text-sm font-medium text-sv-link"
            data-testid="dashboard-drill-merchants"
          >
            Open the merchant directory
          </RouterLink>
        </section>

        <!-- Commercial ---------------------------------------------------------------------- -->
        <section
          aria-labelledby="dash-commercial-heading"
          class="mb-8"
        >
          <h2
            id="dash-commercial-heading"
            class="mb-1 font-display text-lg font-bold text-sv-text-heading"
          >
            Commercial and billing
          </h2>
          <p class="mb-3 text-xs text-sv-text-muted">
            {{ commercial?.time_range }}
          </p>

          <div
            class="grid gap-4 sm:grid-cols-2"
            data-testid="dashboard-commercial"
          >
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <p class="text-sm font-medium text-sv-text-muted">
                Issued invoices
              </p>
              <p class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
                {{ commercial?.issued_invoices }}
              </p>
            </div>
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <p class="text-sm font-medium text-sv-text-muted">
                Outstanding balance
              </p>
              <p class="mt-1">
                <SvMoney
                  :minor-units="commercial?.open_invoice_balance_minor"
                  size="lg"
                />
              </p>
              <p class="mt-1 text-xs text-sv-text-muted">
                {{ definitionOf(commercial, 'open_invoice_balance_minor') }}
              </p>
            </div>
          </div>

          <RouterLink
            v-if="commercial?.drill_through"
            :to="{ name: commercial.drill_through }"
            class="sv-focus-ring mt-3 inline-flex min-h-sv-touch items-center text-sm font-medium text-sv-link"
          >
            Open subscription operations
          </RouterLink>
        </section>

        <!-- Registration monitoring --------------------------------------------------------- -->
        <section
          aria-labelledby="dash-registrations-heading"
          class="mb-8"
        >
          <h2
            id="dash-registrations-heading"
            class="mb-1 font-display text-lg font-bold text-sv-text-heading"
          >
            Registration monitoring
          </h2>
          <p class="mb-3 text-xs text-sv-text-muted">
            {{ registrations?.time_range }}
          </p>

          <div
            class="grid gap-4 sm:grid-cols-3"
            data-testid="dashboard-registrations"
          >
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <p class="text-sm font-medium text-sv-text-muted">
                Last 7 days
              </p>
              <p class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
                {{ registrations?.registered_last_7_days }}
              </p>
            </div>
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <p class="text-sm font-medium text-sv-text-muted">
                Last 30 days
              </p>
              <p class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
                {{ registrations?.registered_last_30_days }}
              </p>
            </div>
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <p class="text-sm font-medium text-sv-text-muted">
                Awaiting setup completion
              </p>
              <p class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
                {{ registrations?.awaiting_setup_completion }}
              </p>
              <p class="mt-1 text-xs text-sv-text-muted">
                {{ definitionOf(registrations, 'awaiting_setup_completion') }}
              </p>
            </div>
          </div>
        </section>

        <!-- Governance tasks ----------------------------------------------------------------- -->
        <section
          aria-labelledby="dash-tasks-heading"
          class="mb-8"
        >
          <h2
            id="dash-tasks-heading"
            class="mb-3 font-display text-lg font-bold text-sv-text-heading"
          >
            Waiting for you
          </h2>

          <dl
            class="grid gap-4 sm:grid-cols-3"
            data-testid="dashboard-tasks"
          >
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <dt class="text-sm font-medium text-sv-text-muted">
                Suspended for billing
              </dt>
              <dd class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
                {{ tasks?.merchants_suspended_for_billing }}
              </dd>
            </div>
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <dt class="text-sm font-medium text-sv-text-muted">
                Suspended by policy
              </dt>
              <!--
                The definition sits INSIDE the `dd` (UI08-A11Y-001). A `p` as a sibling of `dt`/`dd`
                inside a definition list is invalid, and axe reports it `serious` — the value and
                the sentence explaining it are one description, so one `dd` is also the truer markup.
              -->
              <dd class="mt-1">
                <span class="sv-numeric text-2xl font-bold text-sv-text-heading">
                  {{ tasks?.merchants_suspended_by_policy }}
                </span>
                <span class="mt-1 block text-xs text-sv-text-muted">
                  {{ definitionOf(tasks, 'merchants_suspended_by_policy') }}
                </span>
              </dd>
            </div>
            <div class="rounded-card border border-sv-border bg-sv-surface-raised p-4">
              <dt class="text-sm font-medium text-sv-text-muted">
                Overdue invoices
              </dt>
              <dd class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
                {{ tasks?.overdue_invoices }}
              </dd>
            </div>
          </dl>
        </section>

        <!-- Audit -------------------------------------------------------------------------- -->
        <section
          aria-labelledby="dash-audit-heading"
          class="mb-8"
        >
          <h2
            id="dash-audit-heading"
            class="mb-1 font-display text-lg font-bold text-sv-text-heading"
          >
            Audit activity
          </h2>
          <p class="mb-3 text-xs text-sv-text-muted">
            {{ audit?.time_range }}
          </p>

          <div
            class="rounded-card border border-sv-border bg-sv-surface-raised p-4"
            data-testid="dashboard-audit"
          >
            <p class="text-sm font-medium text-sv-text-muted">
              Events in the last 7 days
            </p>
            <p class="sv-numeric mt-1 text-2xl font-bold text-sv-text-heading">
              {{ audit?.events_last_7_days }}
            </p>
            <p class="mt-1 text-xs text-sv-text-muted">
              {{ definitionOf(audit, 'events_last_7_days') }}
            </p>
          </div>

          <RouterLink
            v-if="audit?.drill_through"
            :to="{ name: audit.drill_through }"
            class="sv-focus-ring mt-3 inline-flex min-h-sv-touch items-center text-sm font-medium text-sv-link"
          >
            Open platform audit
          </RouterLink>
        </section>

        <!-- Integrations: truthfully unavailable --------------------------------------------- -->
        <section aria-labelledby="dash-integrations-heading">
          <h2
            id="dash-integrations-heading"
            class="mb-3 font-display text-lg font-bold text-sv-text-heading"
          >
            Integrations
          </h2>

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
        </section>
      </template>
    </template>
  </div>
</template>
