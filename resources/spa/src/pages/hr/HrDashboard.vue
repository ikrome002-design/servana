<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
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

onMounted(() => {
  void store.fetchOverview();
});
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="hr-dashboard"
  >
    <SvPageHeader
      :title="`Good day, ${auth.user?.name ?? 'Human Resource'}`"
      eyebrow="Workforce control desk"
      :description="overview ? `${overview.branch.name} · branch-scoped people readiness` : 'Staff access, readiness, compensation setup and payout preparation for your assigned branch.'"
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

    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No assigned Human Resource branch is available."
      @retry="store.fetchOverview()"
    >
      <template v-if="overview">
        <div class="overflow-hidden rounded-card border border-sv-border bg-sv-surface shadow-card">
          <div class="grid gap-6 bg-brand-deep p-5 text-white md:grid-cols-[1.35fr_1fr] md:p-7">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.18em] text-white/70">
                Workforce pulse
              </p>
              <h2 class="mt-2 font-display text-2xl font-extrabold">
                {{ overview.branch.name }}
              </h2>
              <p class="mt-1 text-sm text-white/75">
                {{ overview.branch.town ?? 'Town not set' }} · Branch {{ overview.branch.code }}
              </p>
              <div class="mt-5 flex flex-wrap gap-2">
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.staff.active }} active staff</span>
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.staff.pending_invitations }} pending invitations</span>
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.payouts.awaiting_finance }} awaiting Finance</span>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.readiness.eligible_staff }}
                </p>
                <p class="text-xs text-white/75">
                  Service-ready staff
                </p>
              </div>
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.readiness.available_staff }}
                </p>
                <p class="text-xs text-white/75">
                  Schedules set
                </p>
              </div>
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.readiness.configured_compensation }}
                </p>
                <p class="text-xs text-white/75">
                  Terms configured
                </p>
              </div>
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ accessAttention }}
                </p>
                <p class="text-xs text-white/75">
                  Access attention
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-brand"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              People
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Staff roster
            </h2>
            <p class="mt-3 text-2xl font-bold text-heading">
              {{ overview.staff.total }}
            </p>
            <p class="text-sm text-text-muted">
              profiles in this branch
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'hr.staff' }"
            >
              Review roster
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-success-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Readiness
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Eligibility gaps
            </h2>
            <p class="mt-3 text-2xl font-bold text-heading">
              {{ overview.readiness.without_eligibility }}
            </p>
            <p class="text-sm text-text-muted">
              active staff need service coverage
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'hr.eligibility' }"
            >
              Manage eligibility
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-warning-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Scheduling
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Availability gaps
            </h2>
            <p class="mt-3 text-2xl font-bold text-heading">
              {{ overview.readiness.without_availability }}
            </p>
            <p class="text-sm text-text-muted">
              active staff need schedules
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'hr.availability' }"
            >
              Set availability
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-info-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Declared terms
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Compensation gaps
            </h2>
            <p class="mt-3 text-2xl font-bold text-heading">
              {{ overview.readiness.without_compensation }}
            </p>
            <p class="text-sm text-text-muted">
              active staff need effective terms
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'hr.compensation' }"
            >
              Review configuration
            </RouterLink>
          </SvCard>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-[1.3fr_0.7fr]">
          <SvCard
            as="section"
            aria-labelledby="hr-task-heading"
          >
            <h2
              id="hr-task-heading"
              class="font-display text-lg font-bold text-heading"
            >
              Tasks requiring attention
            </h2>
            <ul class="mt-3 divide-y divide-border">
              <li
                v-for="task in overview.tasks"
                :key="task.key"
                class="flex min-h-sv-touch items-center justify-between gap-3 py-3"
              >
                <span class="text-sm text-text">{{ task.label }}</span>
                <span class="flex items-center gap-3">
                  <strong class="text-heading">{{ task.count }}</strong>
                  <RouterLink
                    class="sv-focus-ring rounded-control px-2 py-1 text-sm font-semibold text-heading underline"
                    :to="{ name: task.route_name }"
                  >
                    Open
                  </RouterLink>
                </span>
              </li>
            </ul>
          </SvCard>
          <SvCard as="section">
            <h2 class="font-display text-lg font-bold text-heading">
              Payout handoff
            </h2>
            <p class="mt-3 text-3xl font-bold text-heading">
              {{ overview.payouts.awaiting_finance }}
            </p>
            <p class="text-sm text-text-muted">
              submitted runs awaiting Finance verification
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'hr.payouts' }"
            >
              Prepare payout runs
            </RouterLink>
            <SvAlert
              class="mt-4"
              severity="info"
              title="Maker-only workspace"
            >
              Human Resource drafts and submits. Finance verifies, approves and records paid status; Merchant Administrator handles high-value approval.
            </SvAlert>
          </SvCard>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>
