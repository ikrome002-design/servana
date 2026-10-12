<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { SvIconChevronRight } from '@/design-system/icons';
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
    { key: 'roster', eyebrow: 'People', label: 'Staff roster', value: o.staff.total, hint: 'profiles in this branch', to: 'hr.staff', action: 'Review roster', tone: 'brand' },
    { key: 'eligibility', eyebrow: 'Readiness', label: 'Eligibility gaps', value: o.readiness.without_eligibility, hint: 'active staff need service coverage', to: 'hr.eligibility', action: 'Manage eligibility', tone: 'green' },
    { key: 'availability', eyebrow: 'Scheduling', label: 'Availability gaps', value: o.readiness.without_availability, hint: 'active staff need schedules', to: 'hr.availability', action: 'Set availability', tone: 'sun' },
    { key: 'compensation', eyebrow: 'Declared terms', label: 'Compensation gaps', value: o.readiness.without_compensation, hint: 'active staff need effective terms', to: 'hr.compensation', action: 'Review configuration', tone: 'teal' },
  ] as const;
});

onMounted(() => {
  void store.fetchOverview();
});
</script>

<template>
  <!--
    UI16-VIS-013: the HR dashboard split its greeting header from a flat brown pulse panel and then
    repeated four identical top-bordered cards before the task list. It is now one workforce story:
    the command hero holds the greeting, the branch and the readiness counts; the tasks HR must act
    on are the dominant panel beside the payout handoff; the four readiness gaps follow as linked,
    toned tiles. Every figure is the `/hr/workspace` projection; HR remains maker-only.
  -->
  <section
    class="mx-auto max-w-7xl"
    data-testid="hr-dashboard"
  >
    <SvOperationalHero
      class="mb-6"
      eyebrow="Workforce control desk"
      :title="`Good day, ${auth.user?.name ?? 'Human Resource'}`"
      :description="overview ? `${overview.branch.name} · branch-scoped people readiness` : 'Staff access, readiness, compensation setup and payout preparation for your assigned branch.'"
      :context="overview ? `${overview.branch.town ?? 'Town not set'} · Branch ${overview.branch.code}` : undefined"
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 py-2 text-sm font-semibold text-sv-text-on-brand hover:bg-sv-brand-hover"
          :to="{ name: 'hr.staff-invite' }"
        >
          Invite staff
        </RouterLink>
      </template>

      <div v-if="overview">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-white/70">
          Workforce pulse
        </p>
        <h2 class="sr-only">
          {{ overview.branch.name }}
        </h2>
        <div class="mt-3 flex flex-wrap gap-2">
          <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.staff.active }} active staff</span>
          <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.staff.pending_invitations }} pending invitations</span>
          <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.payouts.awaiting_finance }} awaiting Finance</span>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div class="rounded-card border border-white/15 bg-white/10 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.readiness.eligible_staff }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Service-ready staff
            </p>
          </div>
          <div class="rounded-card border border-white/10 bg-white/5 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.readiness.available_staff }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Schedules set
            </p>
          </div>
          <div class="rounded-card border border-white/10 bg-white/5 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.readiness.configured_compensation }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Terms configured
            </p>
          </div>
          <div class="rounded-card border border-white/10 bg-white/5 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ accessAttention }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Access attention
            </p>
          </div>
        </div>
      </div>
    </SvOperationalHero>

    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No assigned Human Resource branch is available."
      @retry="store.fetchOverview()"
    >
      <template v-if="overview">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,0.65fr)]">
          <section
            aria-labelledby="hr-task-heading"
            class="sv-hr-tasks rounded-card border border-sv-border p-5 shadow-card md:p-6"
          >
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
              Act next
            </p>
            <h2
              id="hr-task-heading"
              class="mt-1 font-display text-xl font-extrabold text-sv-text-heading"
            >
              Tasks requiring attention
            </h2>
            <ul class="mt-5 grid gap-3">
              <li
                v-for="task in overview.tasks"
                :key="task.key"
                class="flex min-h-sv-touch items-center gap-4 rounded-card border border-sv-border bg-sv-surface-raised p-3 pl-4"
              >
                <span class="sv-hr-tasks__count sv-numeric inline-flex h-10 min-w-10 shrink-0 items-center justify-center rounded-control px-2 font-display text-lg font-extrabold">{{ task.count }}</span>
                <span class="min-w-0 flex-1 text-sm font-medium text-sv-text">{{ task.label }}</span>
                <RouterLink
                  class="sv-focus-ring inline-flex min-h-sv-touch shrink-0 items-center gap-1 rounded-control px-3 py-2 text-sm font-semibold text-sv-link hover:bg-sv-surface-subtle"
                  :to="{ name: task.route_name }"
                >
                  Open<SvIconChevronRight
                    aria-hidden="true"
                    class="h-4 w-4"
                  />
                </RouterLink>
              </li>
            </ul>
          </section>

          <section
            aria-labelledby="hr-payout-heading"
            class="sv-hr-payout flex flex-col rounded-card border border-sv-border p-5 shadow-card"
          >
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
              Compensation
            </p>
            <h2
              id="hr-payout-heading"
              class="mt-1 font-display text-lg font-bold text-sv-text-heading"
            >
              Payout handoff
            </h2>
            <p class="sv-numeric mt-3 font-display text-4xl font-extrabold text-sv-text-heading">
              {{ overview.payouts.awaiting_finance }}
            </p>
            <p class="text-sm text-sv-text-muted">
              submitted runs awaiting Finance verification
            </p>
            <RouterLink
              class="sv-focus-ring mt-3 inline-flex min-h-sv-touch items-center gap-1 self-start text-sm font-semibold text-sv-link hover:text-sv-link-hover"
              :to="{ name: 'hr.payouts' }"
            >
              Prepare payout runs<SvIconChevronRight
                aria-hidden="true"
                class="h-4 w-4"
              />
            </RouterLink>
            <SvAlert
              class="mt-4"
              severity="info"
              title="Maker-only workspace"
            >
              Human Resource drafts and submits. Finance verifies, approves and records paid status; Merchant Administrator handles high-value approval.
            </SvAlert>
          </section>
        </div>

        <section
          aria-labelledby="hr-gaps-heading"
          class="mt-6"
        >
          <h2
            id="hr-gaps-heading"
            class="font-display text-lg font-bold text-sv-text-heading"
          >
            Readiness gaps
          </h2>
          <div class="mt-3 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <article
              v-for="gap in gaps"
              :key="gap.key"
              class="sv-hr-gap flex flex-col rounded-card border p-4"
              :data-tone="gap.tone"
            >
              <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                {{ gap.eyebrow }}
              </p>
              <h3 class="mt-1 font-display font-bold text-sv-text-heading">
                {{ gap.label }}
              </h3>
              <p class="sv-numeric mt-3 font-display text-3xl font-extrabold text-sv-text-heading">
                {{ gap.value }}
              </p>
              <p class="text-sm text-sv-text-muted">
                {{ gap.hint }}
              </p>
              <RouterLink
                class="sv-focus-ring mt-auto inline-flex min-h-sv-touch items-center gap-1 self-start pt-2 text-sm font-semibold text-sv-link hover:text-sv-link-hover"
                :to="{ name: gap.to }"
              >
                {{ gap.action }}<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </article>
          </div>
        </section>
      </template>
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-hr-tasks {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-growth) 10%, transparent), transparent 42%),
    var(--sv-color-surface-raised);
}
.sv-hr-tasks__count {
  background: color-mix(in srgb, var(--sv-color-brand-primary) 14%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
.sv-hr-payout {
  background: linear-gradient(170deg, color-mix(in srgb, var(--sv-color-brand-secondary) 12%, var(--sv-color-surface-raised)), var(--sv-color-surface-raised) 65%);
}
.sv-hr-gap {
  --sv-gap: var(--sv-color-border-strong);
  border-color: color-mix(in srgb, var(--sv-gap) 32%, var(--sv-color-border-default));
  background: linear-gradient(160deg, color-mix(in srgb, var(--sv-gap) 10%, var(--sv-color-surface-raised)), var(--sv-color-surface-raised) 70%);
}
.sv-hr-gap[data-tone='brand'] { --sv-gap: var(--sv-color-brand-primary); }
.sv-hr-gap[data-tone='green'] { --sv-gap: var(--sv-color-growth); }
.sv-hr-gap[data-tone='sun'] { --sv-gap: var(--sv-color-accent); }
.sv-hr-gap[data-tone='teal'] { --sv-gap: var(--sv-color-brand-secondary); }
</style>