<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvPanel from '@/components/refined/SvPanel.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import { useStaffStore } from '@/stores/staffStore';

const route = useRoute();
const store = useStaffStore();
const id = computed(() => String(route.params.staffUlid ?? ''));
const member = computed(() => store.current?.id === id.value ? store.current : null);
const state = computed(() => (store.loading ? 'loading' : store.error ? 'error' : member.value ? 'success' : 'empty'));

function humanize(value: string | null): string {
  return value ? value.replaceAll('_', ' ') : 'Not set';
}

function tone(status: string | null): SvStatusTone {
  if (status === 'active') return 'success';
  if (status === 'suspended' || status === 'invited') return 'warning';
  if (status === 'deactivated') return 'error';
  return 'neutral';
}

onMounted(() => {
  if (id.value) void store.fetchStaffMember(id.value);
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — a staff record with a strong identity area. A dark identity card
    (initials in an accent ring, name, title, access state) carries the person; their facts sit as
    a divided sheet; the readiness and declared-terms entry points read as two linked panels; and
    the access-authority panel states plainly which controls do not exist yet. Phone is shown only
    because this is the HR record of a member of the assigned branch.
  -->
  <section
    class="sv-r-page"
    data-testid="hr-staff-detail"
  >
    <SvPageHeader
      :title="member?.display_name ?? 'Staff detail'"
      eyebrow="Staff profile"
      description="Identity, branch access state, workforce readiness entry points and declared compensation context."
    >
      <template
        v-if="member"
        #actions
      >
        <RouterLink
          class="sv-r-action sv-focus-ring"
          :to="{ name: 'hr.staff-detail-lifecycle', params: { staffUlid: member.id } }"
        >
          Manage lifecycle
        </RouterLink>
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="This staff profile is not available in your assigned branch."
      @retry="store.fetchStaffMember(id)"
    >
      <div
        v-if="member"
        class="sv-r-grid lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:items-start"
      >
        <section
          class="sv-r-stack-sm"
          aria-label="Identity"
        >
          <div class="sv-r-feature flex flex-col items-center text-center">
            <span
              aria-hidden="true"
              class="inline-flex h-24 w-24 items-center justify-center rounded-full bg-white/10 font-display text-3xl font-extrabold text-white"
              style="box-shadow: 0 0 0 4px var(--sv-color-refined-feature-accent)"
            >{{ member.display_name.slice(0, 2).toUpperCase() }}</span>
            <p class="sv-r-feature__eyebrow mt-5">
              Identity
            </p>
            <h2 class="sv-r-feature__title break-words">
              {{ member.display_name }}
            </h2>
            <p class="sv-r-feature__accent mt-1 text-sm font-semibold">
              {{ member.role_title ?? humanize(member.role) }}
            </p>
            <SvStatusBadge
              class="mt-4"
              :label="humanize(member.status)"
              :tone="tone(member.status)"
              sr-prefix="Access status:"
            />
          </div>
          <dl class="sv-r-panel sv-r-facts">
            <div>
              <dt>Operational role</dt>
              <dd class="capitalize">
                {{ humanize(member.role) }}
              </dd>
            </div>
            <div>
              <dt>Employment</dt>
              <dd class="capitalize">
                {{ humanize(member.employment_type) }} · {{ humanize(member.employment_status) }}
              </dd>
            </div>
            <div>
              <dt>Phone</dt>
              <dd>{{ member.phone }}</dd>
            </div>
            <div>
              <dt>Public reference</dt>
              <dd class="break-all text-sm">
                {{ member.id }}
              </dd>
            </div>
          </dl>
        </section>

        <div class="sv-r-stack">
          <div class="sv-r-grid sv-r-cols-2">
            <SvPanel
              as="article"
              eyebrow="Workforce readiness"
              title="Eligibility and availability"
              tone="green"
              heading-level="h2"
            >
              <p class="sv-r-lede">
                Manage service eligibility and the schedule inputs appointments and queues consume.
              </p>
              <div class="mt-4 flex flex-wrap gap-2">
                <RouterLink
                  class="sv-r-action sv-r-action--quiet sv-focus-ring"
                  :to="{ name: 'hr.eligibility' }"
                >
                  Eligibility
                </RouterLink>
                <RouterLink
                  class="sv-r-action sv-r-action--quiet sv-focus-ring"
                  :to="{ name: 'hr.availability' }"
                >
                  Availability
                </RouterLink>
              </div>
            </SvPanel>
            <SvPanel
              as="article"
              eyebrow="Declared terms"
              title="Compensation"
              tone="teal"
              heading-level="h2"
            >
              <p class="sv-r-lede">
                Review effective-dated configuration only. Earnings and payment execution are not managed here.
              </p>
              <RouterLink
                class="sv-r-action sv-r-action--quiet sv-focus-ring mt-4"
                :to="{ name: 'hr.compensation-detail', params: { staffUlid: member.id } }"
              >
                Open compensation
              </RouterLink>
            </SvPanel>
          </div>
          <SvPanel
            as="article"
            variant="dashed"
            eyebrow="Access authority"
            title="Profile and assignment controls"
            heading-level="h2"
          >
            <SvAlert
              severity="info"
              title="Server authority is not active"
            >
              Staff profile editing and role/branch assignment remain unavailable until their canonical permission and mutation contracts are delivered. This screen does not simulate either change.
            </SvAlert>
            <div class="mt-3 flex flex-wrap gap-2">
              <button
                class="min-h-sv-touch rounded-pill border border-dashed border-sv-border-strong px-4 py-2 text-sm text-text-muted"
                type="button"
                disabled
              >
                Edit profile — unavailable
              </button>
              <button
                class="min-h-sv-touch rounded-pill border border-dashed border-sv-border-strong px-4 py-2 text-sm text-text-muted"
                type="button"
                disabled
              >
                Assign role or branch — unavailable
              </button>
            </div>
          </SvPanel>
        </div>
      </div>
    </SvStateBoundary>
  </section>
</template>