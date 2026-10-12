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
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import { useCan } from '@/composables/useCan';
import { SvIconChevronRight, SvIconLocked } from '@/design-system/icons';
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
    Phase UI-16 (UI16-VIS-010): the dashboard used to be six stacked sections of identical
    full-width single-number boxes — the `sm:grid-cols-*` they relied on never compiled
    (UI16-RESP-001), so every figure ran edge to edge. It is now one governance narrative: a
    command hero that states the lifecycle and offers the two places an owner acts, a dominant
    "waiting for you" queue beside the commercial position, and a supporting band for
    registrations, audit and the gated integrations. Every figure, definition, time range and
    drill-through is still the server's; nothing is summed here.
  -->
  <div
    class="mx-auto w-full max-w-7xl"
    data-testid="platform-dashboard-screen"
  >
    <SvOperationalHero
      class="mb-6"
      eyebrow="Platform governance"
      title="Platform dashboard"
      description="Governance, commercial, registration, risk and audit state across every self-registered merchant."
      context="Citrus platform"
    >
      <template #actions>
        <RouterLink
          v-if="lifecycle?.drill_through"
          :to="{ name: lifecycle.drill_through }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-2 rounded-control bg-sv-brand px-4 py-2 text-sm font-semibold text-sv-text-on-brand hover:bg-sv-brand-hover"
          data-testid="dashboard-drill-merchants"
        >
          Open the merchant directory
        </RouterLink>
        <RouterLink
          v-if="commercial?.drill_through"
          :to="{ name: commercial.drill_through }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/15"
        >
          Open subscription operations
        </RouterLink>
      </template>

      <template v-if="canView">
        <p
          class="text-xs text-white/70"
          data-testid="dashboard-last-refreshed"
        >
          Last refreshed
          <SvDateTime :value="store.lastRefreshed" />
        </p>

        <SvAlert
          v-if="store.error"
          severity="error"
          title="We could not load the platform dashboard"
          class="mt-4"
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
          class="mt-4"
          shape="text"
          :lines="3"
          label="Loading the platform dashboard"
        />

        <section
          v-else-if="store.dashboard"
          aria-labelledby="dash-lifecycle-heading"
          class="mt-4"
        >
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2
              id="dash-lifecycle-heading"
              class="text-xs font-semibold uppercase tracking-[0.16em] text-white/80"
            >
              Merchant lifecycle
            </h2>
            <p class="text-xs text-white/70">
              {{ lifecycle?.time_range }}
            </p>
          </div>
          <div
            class="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-[1.25fr_1fr_1fr_1fr]"
            data-testid="dashboard-lifecycle"
          >
            <div class="rounded-card border border-white/15 bg-white/10 p-4">
              <p class="text-sm font-medium text-white/80">
                Total merchants
              </p>
              <p class="sv-numeric mt-1 font-display text-4xl font-extrabold leading-none">
                {{ lifecycle?.total_merchants }}
              </p>
              <p class="mt-2 text-xs leading-5 text-white/70">
                {{ definitionOf(lifecycle, 'total_merchants') }}
              </p>
            </div>
            <div class="rounded-card border border-white/10 bg-white/5 p-4">
              <p class="text-sm font-medium text-white/80">
                Active
              </p>
              <p class="sv-numeric mt-1 font-display text-3xl font-extrabold leading-none">
                {{ countOf(lifecycle?.by_operational_status, 'active') }}
              </p>
              <p class="mt-2 text-xs leading-5 text-white/70">
                Operational status only — never merged with billing status.
              </p>
            </div>
            <div class="rounded-card border border-white/10 bg-white/5 p-4">
              <p class="text-sm font-medium text-white/80">
                Suspended for billing
              </p>
              <p
                class="sv-numeric mt-1 font-display text-3xl font-extrabold leading-none"
                data-testid="dashboard-billing-suspended"
              >
                {{ lifecycle?.billing_suspended }}
              </p>
              <p class="mt-2 text-xs leading-5 text-white/70">
                A billing suspension is not a policy suspension.
              </p>
            </div>
            <div class="rounded-card border border-white/10 bg-white/5 p-4">
              <p class="text-sm font-medium text-white/80">
                Active branches
              </p>
              <p class="sv-numeric mt-1 font-display text-3xl font-extrabold leading-none">
                {{ lifecycle?.active_branches }}
              </p>
              <p class="mt-2 text-xs leading-5 text-white/70">
                {{ definitionOf(lifecycle, 'active_branches') }}
              </p>
            </div>
          </div>
        </section>
      </template>
    </SvOperationalHero>

    <SvPermissionState v-if="!canView" />

    <template v-if="canView && store.dashboard && !store.error && !store.loading">
      <div class="mb-6 grid gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,0.65fr)]">
        <!-- Governance queue: the dominant "act next" surface ------------------------------ -->
        <section
          aria-labelledby="dash-tasks-heading"
          class="sv-dash-panel rounded-card border border-sv-border p-5 shadow-card md:p-6"
        >
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
            Governance queue
          </p>
          <h2
            id="dash-tasks-heading"
            class="mt-1 font-display text-xl font-extrabold text-sv-text-heading"
          >
            Waiting for you
          </h2>

          <dl
            class="mt-5 grid gap-3"
            data-testid="dashboard-tasks"
          >
            <!--
                Each group keeps DOM order dt → dd (UI08-A11Y-001 / axe definition-list). The count is
                drawn first with CSS `order`; the server's definition, when it supplies one, is a
                second `dd` of the same term.
              -->
            <div
              v-for="task in governanceTasks"
              :key="task.key"
              class="sv-dash-task flex flex-wrap items-center gap-x-4 gap-y-1 rounded-card border border-sv-border p-4"
            >
              <dt class="order-2 min-w-0 flex-1 text-sm font-semibold text-sv-text-heading">
                {{ task.label }}
              </dt>
              <dd class="sv-dash-task__count sv-numeric order-1 inline-flex h-12 min-w-12 shrink-0 items-center justify-center rounded-control px-3 font-display text-xl font-extrabold">
                {{ task.value }}
              </dd>
              <dd
                v-if="task.definition"
                class="order-3 w-full pl-16 text-xs leading-5 text-sv-text-muted"
              >
                {{ task.definition }}
              </dd>
            </div>
          </dl>
        </section>

        <!-- Commercial position --------------------------------------------------------------- -->
        <section
          aria-labelledby="dash-commercial-heading"
          class="sv-dash-commercial rounded-card border border-sv-border p-5 shadow-card md:p-6"
        >
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
            Commercial position
          </p>
          <h2
            id="dash-commercial-heading"
            class="mt-1 font-display text-xl font-extrabold text-sv-text-heading"
          >
            Commercial and billing
          </h2>
          <p class="mt-0.5 text-xs text-sv-text-muted">
            {{ commercial?.time_range }}
          </p>

          <div
            class="mt-5 flex flex-col gap-4"
            data-testid="dashboard-commercial"
          >
            <div>
              <p class="text-sm font-medium text-sv-text-muted">
                Outstanding balance
              </p>
              <p class="mt-1">
                <SvMoney
                  :minor-units="commercial?.open_invoice_balance_minor"
                  size="lg"
                />
              </p>
              <p class="mt-1 text-xs leading-5 text-sv-text-muted">
                {{ definitionOf(commercial, 'open_invoice_balance_minor') }}
              </p>
            </div>
            <div class="flex items-center justify-between gap-3 rounded-control bg-sv-surface-subtle px-4 py-3">
              <p class="text-sm font-medium text-sv-text-secondary">
                Issued invoices
              </p>
              <p class="sv-numeric font-display text-2xl font-extrabold text-sv-text-heading">
                {{ commercial?.issued_invoices }}
              </p>
            </div>
          </div>
        </section>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <!-- Registration monitoring ------------------------------------------------------------ -->
        <section
          aria-labelledby="dash-registrations-heading"
          class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
        >
          <h2
            id="dash-registrations-heading"
            class="font-display text-lg font-bold text-sv-text-heading"
          >
            Registration monitoring
          </h2>
          <p class="mt-0.5 text-xs text-sv-text-muted">
            {{ registrations?.time_range }}
          </p>
          <div
            class="mt-4 grid grid-cols-2 gap-3"
            data-testid="dashboard-registrations"
          >
            <SvStatTile
              label="Last 7 days"
              tone="brand"
            >
              <span class="sv-numeric">{{ registrations?.registered_last_7_days }}</span>
            </SvStatTile>
            <SvStatTile
              label="Last 30 days"
              tone="sun"
            >
              <span class="sv-numeric">{{ registrations?.registered_last_30_days }}</span>
            </SvStatTile>
            <SvStatTile
              class="col-span-2"
              label="Awaiting setup completion"
              tone="teal"
              :hint="definitionOf(registrations, 'awaiting_setup_completion')"
            >
              <span class="sv-numeric">{{ registrations?.awaiting_setup_completion }}</span>
            </SvStatTile>
          </div>
        </section>

        <!-- Audit ----------------------------------------------------------------------------- -->
        <section
          aria-labelledby="dash-audit-heading"
          class="flex flex-col rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
        >
          <h2
            id="dash-audit-heading"
            class="font-display text-lg font-bold text-sv-text-heading"
          >
            Audit activity
          </h2>
          <p class="mt-0.5 text-xs text-sv-text-muted">
            {{ audit?.time_range }}
          </p>
          <div
            class="mt-4"
            data-testid="dashboard-audit"
          >
            <SvStatTile
              label="Events in the last 7 days"
              tone="teal"
              emphasis
              :hint="definitionOf(audit, 'events_last_7_days')"
            >
              <span class="sv-numeric">{{ audit?.events_last_7_days }}</span>
            </SvStatTile>
          </div>
          <RouterLink
            v-if="audit?.drill_through"
            :to="{ name: audit.drill_through }"
            class="sv-focus-ring mt-auto inline-flex min-h-sv-touch items-center gap-1 pt-3 text-sm font-semibold text-sv-link hover:text-sv-link-hover"
          >
            Open platform audit<SvIconChevronRight
              aria-hidden="true"
              class="h-4 w-4"
            />
          </RouterLink>
        </section>

        <!-- Integrations: truthfully unavailable ---------------------------------------------- -->
        <section
          aria-labelledby="dash-integrations-heading"
          class="rounded-card border border-dashed border-sv-border-strong bg-sv-surface-subtle p-5"
        >
          <h2
            id="dash-integrations-heading"
            class="flex items-center gap-2 font-display text-lg font-bold text-sv-text-heading"
          >
            <SvIconLocked
              aria-hidden="true"
              class="h-5 w-5 text-sv-text-muted"
            />
            Integrations
          </h2>
          <SvAlert
            v-if="integrations?.availability === 'disabled_by_gate'"
            severity="info"
            title="Integration health is unavailable"
            class="mt-4"
            data-testid="dashboard-integrations-gated"
          >
            <p>{{ integrations.gate_statement }}</p>
            <p class="mt-2 text-xs">
              No figure is shown for Wallet health, reconciliation exceptions or Refer &amp; Earn
              qualification. A zero here would be indistinguishable from a real zero.
            </p>
          </SvAlert>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.sv-dash-panel {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-primary) 10%, transparent), transparent 42%),
    var(--sv-color-surface-raised);
}
.sv-dash-task {
  background: var(--sv-color-surface-raised);
  transition: border-color var(--sv-motion-duration-fast) var(--sv-motion-ease-standard);
}
.sv-dash-task:hover { border-color: var(--sv-color-border-strong); }
.sv-dash-task__count {
  background: color-mix(in srgb, var(--sv-color-brand-primary) 14%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
.sv-dash-commercial {
  background:
    linear-gradient(170deg, color-mix(in srgb, var(--sv-color-accent) 16%, var(--sv-color-surface-raised)), var(--sv-color-surface-raised) 60%);
}
</style>
