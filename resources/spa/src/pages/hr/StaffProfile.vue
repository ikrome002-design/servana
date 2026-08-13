<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvCard from '@/components/ui/SvCard.vue';
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
  <section
    class="mx-auto max-w-6xl"
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
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep"
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
      <template v-if="member">
        <div class="grid gap-5 lg:grid-cols-[0.8fr_1.2fr]">
          <SvCard
            as="section"
            class="border-t-4 border-t-sv-brand"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Identity
                </p>
                <h2 class="mt-1 font-display text-xl font-bold text-heading">
                  {{ member.display_name }}
                </h2>
                <p class="text-sm text-text-muted">
                  {{ member.role_title ?? humanize(member.role) }}
                </p>
              </div>
              <SvStatusBadge
                :label="humanize(member.status)"
                :tone="tone(member.status)"
                sr-prefix="Access status:"
              />
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
              <div>
                <dt class="text-xs text-text-muted">
                  Operational role
                </dt><dd class="capitalize text-text">
                  {{ humanize(member.role) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Employment
                </dt><dd class="capitalize text-text">
                  {{ humanize(member.employment_type) }} · {{ humanize(member.employment_status) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Phone
                </dt><dd class="text-text">
                  {{ member.phone }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-text-muted">
                  Public reference
                </dt><dd class="break-all text-sm text-text">
                  {{ member.id }}
                </dd>
              </div>
            </dl>
          </SvCard>

          <div class="grid gap-4 sm:grid-cols-2">
            <SvCard as="article">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Workforce readiness
              </p>
              <h2 class="mt-1 font-display text-lg font-bold text-heading">
                Eligibility and availability
              </h2>
              <p class="mt-2 text-sm text-text-muted">
                Manage service eligibility and the schedule inputs appointments and queues consume.
              </p>
              <div class="mt-3 flex flex-wrap gap-3">
                <RouterLink
                  class="inline-flex min-h-sv-touch items-center font-semibold text-heading underline"
                  :to="{ name: 'hr.eligibility' }"
                >
                  Eligibility
                </RouterLink>
                <RouterLink
                  class="inline-flex min-h-sv-touch items-center font-semibold text-heading underline"
                  :to="{ name: 'hr.availability' }"
                >
                  Availability
                </RouterLink>
              </div>
            </SvCard>
            <SvCard as="article">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Declared terms
              </p>
              <h2 class="mt-1 font-display text-lg font-bold text-heading">
                Compensation
              </h2>
              <p class="mt-2 text-sm text-text-muted">
                Review effective-dated configuration only. Earnings and payment execution are not managed here.
              </p>
              <RouterLink
                class="mt-3 inline-flex min-h-sv-touch items-center font-semibold text-heading underline"
                :to="{ name: 'hr.compensation-detail', params: { staffUlid: member.id } }"
              >
                Open compensation
              </RouterLink>
            </SvCard>
            <SvCard
              as="article"
              class="sm:col-span-2"
            >
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Access authority
              </p>
              <h2 class="mt-1 font-display text-lg font-bold text-heading">
                Profile and assignment controls
              </h2>
              <SvAlert
                class="mt-3"
                severity="info"
                title="Server authority is not active"
              >
                Staff profile editing and role/branch assignment remain unavailable until their canonical permission and mutation contracts are delivered. This screen does not simulate either change.
              </SvAlert>
              <div class="mt-3 flex flex-wrap gap-2">
                <button
                  class="min-h-sv-touch rounded-control border border-border px-3 py-2 text-sm text-text-muted"
                  type="button"
                  disabled
                >
                  Edit profile — unavailable
                </button>
                <button
                  class="min-h-sv-touch rounded-control border border-border px-3 py-2 text-sm text-text-muted"
                  type="button"
                  disabled
                >
                  Assign role or branch — unavailable
                </button>
              </div>
            </SvCard>
          </div>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>
