<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvFeaturePanel from '@/components/refined/SvFeaturePanel.vue';
import SvKpiCard from '@/components/refined/SvKpiCard.vue';
import SvPanel from '@/components/refined/SvPanel.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { SvIconChevronRight } from '@/design-system/icons';
import { navigationIcon } from '@/navigation/navigationIcons';
import { useAuthStore } from '@/stores/authStore';
import { useHrWorkspaceStore } from '@/stores/hrWorkspaceStore';

const auth = useAuthStore();
const store = useHrWorkspaceStore();
const overview = computed(() => store.overview);
const state = computed(() => (store.loading ? 'loading' : store.error ? 'error' : overview.value ? 'success' : 'empty'));
const accessAttention = computed(() => {
  const status = overview.value?.staff.by_access_status ?? {};
  return (status.invited ?? 0) + (status.suspended ?? 0);
});

/** The four readiness gaps HR closes, each with the page that closes it. All counts are the server's. */
const gaps = computed(() => {
  const o = overview.value;
  if (!o) return [];
  return [
    { key: 'roster', eyebrow: 'People', label: 'Staff roster', value: o.staff.total, hint: 'profiles in this branch', to: 'hr.staff', action: 'Review roster', tone: 'warm', icon: 'staff' },
    { key: 'eligibility', eyebrow: 'Readiness', label: 'Eligibility gaps', value: o.readiness.without_eligibility, hint: 'active staff need service coverage', to: 'hr.eligibility', action: 'Manage eligibility', tone: 'green', icon: 'eligibility' },
    { key: 'availability', eyebrow: 'Scheduling', label: 'Availability gaps', value: o.readiness.without_availability, hint: 'active staff need schedules', to: 'hr.availability', action: 'Set availability', tone: 'sun', icon: 'availability' },
    { key: 'compensation', eyebrow: 'Declared terms', label: 'Compensation gaps', value: o.readiness.without_compensation, hint: 'active staff need effective terms', to: 'hr.compensation', action: 'Review configuration', tone: 'teal', icon: 'compensation' },
  ] as const;
});

/**
 * Readiness drawn as the server's READY count against its GAP count for each dimension. The bar
 * only proportions two server numbers; no rate or percentage is computed or claimed.
 */
const dimensions = computed(() => {
  const o = overview.value;
  if (!o) return [];
  return [
    { key: 'eligibility', label: 'Service coverage', ready: o.readiness.eligible_staff, readyLabel: 'service-ready', gap: o.readiness.without_eligibility },
    { key: 'availability', label: 'Schedules', ready: o.readiness.available_staff, readyLabel: 'schedules set', gap: o.readiness.without_availability },
    { key: 'compensation', label: 'Declared terms', ready: o.readiness.configured_compensation, readyLabel: 'terms configured', gap: o.readiness.without_compensation },
  ];
});

onMounted(() => {
  void store.fetchOverview();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the workforce readiness command centre. Readiness leads on the dark
    feature surface as three dimensions (service coverage, schedules, declared terms), each drawn
    as the server's ready count against its gap count; the act-next task queue sits beside it; the
    four gap tiles link to the page that closes each gap; the payout handoff closes the page with
    the maker-only boundary. No HR metric is invented: every number is `/hr/workspace`.
  -->
  <section
    class="sv-r-page"
    data-testid="hr-dashboard"
  >
    <SvTonalPageHeader
      eyebrow="Workforce control desk"
      :title="`Good day, ${auth.user?.name ?? 'Human Resource'}`"
      :description="overview ? `${overview.branch.name} · branch-scoped people readiness` : 'Staff access, readiness, compensation setup and payout preparation for your assigned branch.'"
      :context="overview ? `${overview.branch.town ?? 'Town not set'} · Branch ${overview.branch.code}` : undefined"
    >
      <template #actions>
        <RouterLink
          class="sv-r-action sv-focus-ring"
          :to="{ name: 'hr.staff-invite' }"
        >
          Invite staff
        </RouterLink>
      </template>
    </SvTonalPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No assigned Human Resource branch is available."
      @retry="store.fetchOverview()"
    >
      <div
        v-if="overview"
        class="sv-r-stack"
      >
        <div class="sv-r-grid sv-r-split-lead">
          <SvFeaturePanel
            eyebrow="Workforce pulse"
            title="Readiness to serve"
          >
            <h2 class="sr-only">
              {{ overview.branch.name }}
            </h2>
            <div class="mt-3 flex flex-wrap gap-2">
              <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white">{{ overview.staff.active }} active staff</span>
              <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white">{{ overview.staff.pending_invitations }} pending invitations</span>
              <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white">{{ overview.payouts.awaiting_finance }} awaiting Finance</span>
              <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white">{{ accessAttention }} access attention</span>
            </div>
            <dl class="mt-6 flex flex-col gap-5">
              <!-- dl > div > (dt, dd, dd): the bar is a second, decorative dd so the list stays valid. -->
              <div
                v-for="dimension in dimensions"
                :key="dimension.key"
                class="flex flex-wrap items-baseline justify-between gap-x-2"
              >
                <dt class="sv-r-feature__label">
                  {{ dimension.label }}
                </dt>
                <dd class="sv-r-feature__hint">
                  <span class="sv-r-feature__value text-2xl">{{ dimension.ready }}</span>
                  {{ dimension.readyLabel }} · {{ dimension.gap }} need attention
                </dd>
                <dd
                  class="mt-2 flex h-2 basis-full overflow-hidden rounded-pill bg-white/10"
                  aria-hidden="true"
                >
                  <span
                    class="block h-full bg-sv-accent"
                    :style="{ flexGrow: dimension.ready }"
                  />
                  <span
                    class="block h-full bg-white/25"
                    :style="{ flexGrow: dimension.gap }"
                  />
                </dd>
              </div>
            </dl>
          </SvFeaturePanel>

          <SvPanel
            eyebrow="Act next"
            title="Tasks requiring attention"
            tone="green"
          >
            <ul class="sv-r-queue">
              <li
                v-for="task in overview.tasks"
                :key="task.key"
                class="sv-r-queue__row sv-r-tone-green flex-nowrap"
              >
                <span class="sv-r-queue__count">{{ task.count }}</span>
                <span class="sv-r-queue__label">{{ task.label }}</span>
                <RouterLink
                  class="sv-r-link sv-focus-ring shrink-0 rounded-control"
                  :to="{ name: task.route_name }"
                >
                  Open<SvIconChevronRight
                    aria-hidden="true"
                    class="h-4 w-4"
                  />
                </RouterLink>
              </li>
            </ul>
          </SvPanel>
        </div>

        <section aria-labelledby="hr-gaps-heading">
          <h2
            id="hr-gaps-heading"
            class="sv-r-title mb-3"
          >
            Readiness gaps
          </h2>
          <div class="sv-r-grid sv-r-cols-4">
            <SvKpiCard
              v-for="gap in gaps"
              :key="gap.key"
              :label="gap.label"
              :hint="gap.hint"
              :tone="gap.tone"
              :icon="navigationIcon(gap.icon)"
              :to="{ name: gap.to }"
              :link-label="gap.action"
            >
              <span class="sv-numeric">{{ gap.value }}</span>
              <template #meta>
                <span class="sv-r-eyebrow">{{ gap.eyebrow }}</span>
              </template>
            </SvKpiCard>
          </div>
        </section>

        <section
          aria-labelledby="hr-payout-heading"
          class="sv-r-panel sv-r-panel--tonal sv-r-tone-teal"
        >
          <div class="grid gap-5 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center">
            <div>
              <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal">
                Compensation
              </p>
              <h2
                id="hr-payout-heading"
                class="sv-r-title mt-1"
              >
                Payout handoff
              </h2>
              <p class="sv-r-figure-xl mt-3 text-sv-text-heading">
                {{ overview.payouts.awaiting_finance }}
              </p>
              <p class="sv-r-muted">
                submitted runs awaiting Finance verification
              </p>
              <RouterLink
                class="sv-r-link sv-focus-ring mt-2 rounded-control"
                :to="{ name: 'hr.payouts' }"
              >
                Prepare payout runs<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </div>
            <SvAlert
              severity="info"
              title="Maker-only workspace"
            >
              Human Resource drafts and submits. Finance verifies, approves and records paid status; Merchant Administrator handles high-value approval.
            </SvAlert>
          </div>
        </section>
      </div>
    </SvStateBoundary>
  </section>
</template>