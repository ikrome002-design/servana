<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { SvIconChevronRight, SvIconEmail, SvIconLocked, SvIconSuccess } from '@/design-system/icons';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';
import { formatMoney } from '@/utils/money';
import { compensationModelLabel, humanizeStatus, nairobiTime, personnelStatusTone } from '@/utils/personnelStatus';

/*
 * Personnel dashboard — a private workday companion (UI-14).
 *
 * Every number comes from the server's own-scope workspace aggregate (`/personnel/me/workspace`);
 * the browser counts nothing, compares nobody and calculates no money. The next assignment is the
 * dominant surface, the workday flow shows where the day stands, and earnings appear as a calm
 * statement snapshot rather than a wallet.
 */
const store = usePersonnelExperienceStore();
const workspace = computed(() => store.workspace);
const state = computed(() => store.loading.workspace ? 'loading' : store.errors.workspace ? 'error' : workspace.value ? 'success' : 'empty');
const branchContext = computed(() => workspace.value?.staff.branch ? `${workspace.value.staff.branch.name} · ${workspace.value.business_date}` : workspace.value?.business_date);
const firstName = computed(() => workspace.value?.staff.display_name.split(' ')[0] ?? '');
const kindLabel: Record<string, string> = { queue: 'From your queue', appointment: 'Booked appointment', session: 'In service now' };

const flow = computed(() => {
  const value = workspace.value;
  if (!value) return [];
  return [
    { key: 'queue', label: 'Waiting for you', count: value.queue.active, hint: value.queue.estimated_wait_minutes != null ? `Next wait ~${value.queue.estimated_wait_minutes} min` : 'No wait estimate', route: 'personnel.work-queue' },
    { key: 'appointments', label: 'Booked today', count: value.appointments.today, hint: value.appointments.next_at ? `Next at ${nairobiTime(value.appointments.next_at)}` : `${value.appointments.upcoming} upcoming`, route: 'personnel.work-appointments' },
    { key: 'sessions', label: 'With you now', count: value.sessions.active, hint: 'Front Office records completion', route: 'personnel.work-sessions' },
    { key: 'done', label: 'Done today', count: value.sessions.completed_today, hint: 'Completed services', route: 'personnel.work-history' },
  ];
});

function observed(value?: string): string | undefined {
  if (!value) return undefined;
  return new Intl.DateTimeFormat('en-KE', { hour: 'numeric', minute: '2-digit', timeZone: 'Africa/Nairobi' }).format(new Date(value));
}

onMounted(() => { void store.fetchWorkspace(true); });
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="personnel-dashboard"
  >
    <SvStateBoundary
      :state="state"
      :error-message="store.errors.workspace ?? undefined"
      empty-message="Your private work workspace is not available yet."
      @retry="store.fetchWorkspace(true)"
    >
      <template v-if="workspace">
        <SvOperationalHero
          eyebrow="Your work today"
          :title="`Good day, ${firstName}`"
          description="Your next client, your day so far, your own client relationships and your recorded pay — visible only to you."
          :context="branchContext"
          :observed-at="observed(workspace.observed_at)"
        >
          <template #actions>
            <RouterLink
              class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 py-2 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
              :to="{ name: workspace.next_assignment?.route_name ?? 'personnel.work-queue' }"
            >
              {{ workspace.next_assignment ? 'Open next assignment' : 'Open my queue' }}
            </RouterLink>
          </template>
          <ol
            class="grid grid-cols-2 gap-2 md:grid-cols-4 md:gap-0"
            aria-label="Your workday so far"
          >
            <li
              v-for="(step, index) in flow"
              :key="step.key"
              class="relative"
            >
              <RouterLink
                :to="{ name: step.route }"
                class="sv-focus-ring group flex h-full min-h-sv-touch flex-col rounded-control border border-white/10 bg-white/10 p-3 transition-colors duration-sv-fast hover:bg-white/15 motion-reduce:transition-none md:mr-3"
              >
                <span class="text-xs font-semibold text-white/75">{{ String(index + 1).padStart(2, '0') }} · {{ step.label }}</span>
                <strong class="mt-1 font-display text-2xl">{{ step.count }}</strong>
                <span class="text-xs text-white/70">{{ step.hint }}</span>
              </RouterLink>
              <SvIconChevronRight
                v-if="index < flow.length - 1"
                aria-hidden="true"
                class="absolute -right-0.5 top-1/2 hidden h-4 w-4 -translate-y-1/2 text-white/50 md:block"
              />
            </li>
          </ol>
        </SvOperationalHero>

        <div class="mt-5 grid gap-5 lg:grid-cols-[1.35fr_0.65fr]">
          <section
            class="sv-next-up relative overflow-hidden rounded-card border border-sv-border p-5 shadow-raised md:p-7"
            aria-labelledby="next-assignment-heading"
          >
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
              Your next service
            </p>
            <template v-if="workspace.next_assignment">
              <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
                <h2
                  id="next-assignment-heading"
                  class="font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl"
                >
                  {{ workspace.next_assignment.title }}
                </h2>
                <SvStatusBadge
                  :label="humanizeStatus(workspace.next_assignment.status)"
                  :tone="personnelStatusTone(workspace.next_assignment.status)"
                />
              </div>
              <p class="mt-1 text-sm text-sv-text-secondary">
                {{ kindLabel[workspace.next_assignment.kind] ?? 'Assigned to you' }}
              </p>
              <div class="mt-5 flex flex-wrap items-center justify-between gap-4 rounded-control border border-sv-border bg-sv-surface-raised p-4">
                <SvMaskedIdentity
                  :name="workspace.next_assignment.client_name"
                  :phone-masked="workspace.next_assignment.phone_masked"
                />
                <dl class="flex flex-wrap gap-5 text-sm">
                  <div v-if="workspace.next_assignment.position">
                    <dt class="text-xs text-sv-text-muted">
                      Position
                    </dt>
                    <dd class="font-display text-lg font-bold text-sv-text-heading">
                      #{{ workspace.next_assignment.position }}
                    </dd>
                  </div>
                  <div v-if="workspace.next_assignment.estimated_wait_minutes != null">
                    <dt class="text-xs text-sv-text-muted">
                      Estimated wait
                    </dt>
                    <dd class="font-display text-lg font-bold text-sv-text-heading">
                      ~{{ workspace.next_assignment.estimated_wait_minutes }} min
                    </dd>
                  </div>
                  <div v-if="workspace.next_assignment.at">
                    <dt class="text-xs text-sv-text-muted">
                      Time
                    </dt>
                    <dd class="font-display text-lg font-bold text-sv-text-heading">
                      {{ nairobiTime(workspace.next_assignment.at) }}
                    </dd>
                  </div>
                </dl>
              </div>
              <RouterLink
                class="sv-focus-ring mt-5 inline-flex min-h-sv-touch items-center gap-1 rounded-control bg-sv-brand px-5 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
                :to="{ name: workspace.next_assignment.route_name }"
              >
                Open<span class="sr-only"> {{ workspace.next_assignment.title }}</span>
                <SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </template>
            <template v-else>
              <h2
                id="next-assignment-heading"
                class="mt-2 font-display text-2xl font-extrabold text-sv-text-heading"
              >
                You’re clear for now
              </h2>
              <p class="mt-2 max-w-lg text-sm text-sv-text-secondary">
                No active assignment is waiting. Check your appointments or availability before the next client arrives.
              </p>
            </template>
          </section>

          <div class="flex flex-col gap-4">
            <section
              aria-labelledby="availability-heading"
              class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            >
              <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                Set by Human Resource
              </p>
              <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <h2
                  id="availability-heading"
                  class="font-display text-xl font-bold text-sv-text-heading"
                >
                  {{ humanizeStatus(workspace.availability.current_state) }}
                </h2>
                <SvStatusBadge
                  size="sm"
                  label="Availability"
                  :tone="personnelStatusTone(workspace.availability.current_state)"
                  sr-prefix=""
                />
              </div>
              <p class="mt-2 text-sm text-sv-text-secondary">
                Your weekly schedule is read-only here.
              </p>
              <RouterLink
                class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
                :to="{ name: 'personnel.availability' }"
              >
                View schedule
              </RouterLink>
            </section>
            <section
              aria-labelledby="preferred-heading"
              class="rounded-card border p-5"
              :class="workspace.preferred_requests.active > 0 ? 'border-sv-warning-border bg-sv-warning-bg text-sv-warning-fg' : 'border-sv-border bg-sv-surface-raised'"
            >
              <p
                class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide"
                :class="workspace.preferred_requests.active > 0 ? '' : 'text-sv-text-muted'"
              >
                <SvIconSuccess
                  aria-hidden="true"
                  class="h-4 w-4"
                />Asked for you
              </p>
              <h2
                id="preferred-heading"
                class="mt-2 font-display text-xl font-bold"
                :class="workspace.preferred_requests.active > 0 ? '' : 'text-sv-text-heading'"
              >
                {{ workspace.preferred_requests.active }} active {{ workspace.preferred_requests.active === 1 ? 'request' : 'requests' }}
              </h2>
              <RouterLink
                class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold underline"
                :class="workspace.preferred_requests.active > 0 ? '' : 'text-sv-link'"
                :to="{ name: 'personnel.work-preferred-requests' }"
              >
                See preferred requests
              </RouterLink>
            </section>
          </div>
        </div>

        <div class="mt-5 grid gap-5 lg:grid-cols-[1.2fr_0.8fr_0.8fr]">
          <article
            class="sv-earnings-snapshot rounded-card border border-sv-border p-5 shadow-card md:p-6"
            aria-labelledby="earnings-heading"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h2
                id="earnings-heading"
                class="font-display text-lg font-bold text-sv-text-heading"
              >
                My earnings
              </h2>
              <span class="rounded-full border border-sv-border bg-sv-surface-raised px-3 py-1 text-xs font-semibold text-sv-text-secondary">{{ compensationModelLabel(workspace.earnings.tab_visibility.model) }}</span>
            </div>
            <template v-if="workspace.earnings.currencies.length">
              <div
                v-for="row in workspace.earnings.currencies"
                :key="row.currency"
                class="mt-4"
              >
                <p class="text-xs text-sv-text-muted">
                  {{ row.currency }} recorded to date
                </p>
                <p class="font-display text-3xl font-extrabold text-sv-text-heading">
                  {{ formatMoney(row.net_minor, row.currency) }}
                </p>
                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                  <SvStatTile
                    label="Outstanding"
                    tone="sun"
                  >
                    <span class="text-lg">{{ formatMoney(row.unpaid_minor, row.currency) }}</span>
                  </SvStatTile>
                  <SvStatTile
                    label="Recorded paid"
                    tone="green"
                  >
                    <span class="text-lg">{{ formatMoney(row.paid_minor, row.currency) }}</span>
                  </SvStatTile>
                </div>
              </div>
            </template>
            <p
              v-else
              class="mt-3 text-sm text-sv-text-muted"
            >
              No earnings have been recorded yet.
            </p>
            <p
              v-if="workspace.earnings.latest_payout"
              class="mt-3 text-xs text-sv-text-muted"
            >
              Latest payout: {{ formatMoney(workspace.earnings.latest_payout.gross_amount_minor, workspace.earnings.latest_payout.currency) }} · {{ humanizeStatus(workspace.earnings.latest_payout.status).toLowerCase() }}
            </p>
            <RouterLink
              class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
              :to="{ name: 'personnel.earnings' }"
            >
              Review earnings
            </RouterLink>
          </article>

          <article
            class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card md:p-6"
            aria-labelledby="clients-heading"
          >
            <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
              <SvIconLocked
                aria-hidden="true"
                class="h-4 w-4"
              />Personally served
            </p>
            <h2
              id="clients-heading"
              class="mt-2 font-display text-2xl font-extrabold text-sv-text-heading"
            >
              {{ workspace.clients.served }} clients
            </h2>
            <p class="mt-2 text-sm text-sv-text-secondary">
              Contacts stay masked. Follow-up SMS stays inside Servana and reaches eligible clients only.
            </p>
            <p class="mt-2 text-xs text-sv-text-muted">
              {{ workspace.clients.recent_messages }} recent {{ workspace.clients.recent_messages === 1 ? 'message' : 'messages' }} sent by you
            </p>
            <div class="mt-3 flex flex-wrap gap-x-4">
              <RouterLink
                class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
                :to="{ name: 'personnel.clients' }"
              >
                Open served clients
              </RouterLink>
              <RouterLink
                v-if="workspace.clients.can_compose_sms"
                class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-1 text-sm font-semibold text-sv-link underline"
                :to="{ name: 'personnel.messages-compose' }"
              >
                <SvIconEmail
                  aria-hidden="true"
                  class="h-4 w-4"
                />Compose SMS
              </RouterLink>
            </div>
          </article>

          <article
            class="rounded-card border border-sv-border bg-sv-surface-warm p-5 md:p-6"
            aria-labelledby="queries-heading"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
              Questions for Finance
            </p>
            <h2
              id="queries-heading"
              class="mt-2 font-display text-2xl font-extrabold text-sv-text-heading"
            >
              {{ workspace.earnings.unresolved_queries }} open
            </h2>
            <p class="mt-2 text-sm text-sv-text-secondary">
              Queries never edit earnings; Finance reviews and answers them.
            </p>
            <RouterLink
              class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
              :to="{ name: 'personnel.earnings-queries' }"
            >
              View queries
            </RouterLink>
          </article>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-next-up {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-primary) 16%, transparent), transparent 48%),
    linear-gradient(165deg, color-mix(in srgb, var(--sv-color-accent) 10%, var(--sv-color-surface-raised)), var(--sv-color-surface-raised) 65%);
}
.sv-earnings-snapshot {
  background:
    radial-gradient(circle at 0% 0%, color-mix(in srgb, var(--sv-color-growth) 12%, transparent), transparent 50%),
    var(--sv-color-surface-raised);
}
</style>
