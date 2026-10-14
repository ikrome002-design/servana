<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { navigationIcon } from '@/navigation/navigationIcons';
import { useBranchStore } from '@/stores/branchStore';

const route = useRoute();
const branches = useBranchStore();
const branchUlid = computed(() => String(route.params.branchUlid ?? ''));
const state = computed(() => branches.loading ? 'loading' : branches.activeBranch ? 'success' : 'empty');

onMounted(() => { void branches.fetchBranch(branchUlid.value); });
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — owner oversight of one branch. A context-rich header (breadcrumb, name,
    status), the branch's facts as a sheet, and the responsibility boundaries as three role cards
    so the owner sees at a glance who operates what. The gated performance runtime stays inert.
    No operational control exists on this page.
  -->
  <section
    class="sv-r-page"
    data-testid="merchant-branch-detail"
  >
    <SvPageHeader
      :title="branches.activeBranch?.name ?? 'Branch detail'"
      eyebrow="Merchant oversight"
      description="Read-only owner context for this branch. Branch Manager, Human Resource, Front Office and Finance retain their operational responsibilities."
    >
      <template #breadcrumbs>
        <nav
          aria-label="Breadcrumb"
          class="mb-3 text-sm text-text-muted"
        >
          <RouterLink
            class="sv-focus-ring rounded-control underline"
            :to="{ name: 'merchant.branches' }"
          >
            Branches
          </RouterLink>
          <span aria-hidden="true"> / </span><span>Branch detail</span>
        </nav>
      </template>
    </SvPageHeader>
    <SvStateBoundary
      :state="state"
      empty-message="This branch could not be loaded."
      @retry="branches.fetchBranch(branchUlid)"
    >
      <div
        v-if="branches.activeBranch"
        class="sv-r-stack"
      >
        <div class="sv-r-grid sv-r-split-wide">
          <section
            class="sv-r-panel sv-r-panel--tonal sv-r-tone-green"
            aria-labelledby="branch-profile-heading"
          >
            <div class="flex flex-wrap items-start gap-4">
              <span
                aria-hidden="true"
                class="sv-r-icon sv-r-icon--lg sv-r-tone-green"
              >
                <component :is="navigationIcon('branch')" />
              </span>
              <div class="min-w-0">
                <p class="sv-r-eyebrow">
                  Branch identity
                </p>
                <h2
                  id="branch-profile-heading"
                  class="sv-r-title mt-1"
                >
                  Branch profile
                </h2>
              </div>
              <span class="sv-r-chip sv-r-chip--tone sv-r-chip--dot sv-r-tone-green ml-auto">{{ branches.activeBranch.status }}</span>
            </div>
            <dl class="sv-r-facts sv-r-facts--2 mt-4">
              <div>
                <dt>Code</dt>
                <dd>{{ branches.activeBranch.code }}</dd>
              </div>
              <div>
                <dt>Status</dt>
                <dd>{{ branches.activeBranch.status }}</dd>
              </div>
              <div>
                <dt>Town</dt>
                <dd>{{ branches.activeBranch.town ?? '—' }}</dd>
              </div>
              <div>
                <dt>Address</dt>
                <dd>{{ branches.activeBranch.address ?? '—' }}</dd>
              </div>
              <div>
                <dt>Contact</dt>
                <dd>{{ branches.activeBranch.phone ?? branches.activeBranch.email ?? '—' }}</dd>
              </div>
            </dl>
          </section>

          <section
            class="sv-r-panel"
            aria-labelledby="branch-boundaries-heading"
          >
            <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-warm">
              Who operates what
            </p>
            <h2
              id="branch-boundaries-heading"
              class="sv-r-title mt-1"
            >
              Responsibility boundaries
            </h2>
            <ul class="mt-4 flex flex-col gap-2 text-sm text-text">
              <li class="sv-r-queue__row">
                <span
                  aria-hidden="true"
                  class="sv-r-icon sv-r-tone-green"
                ><component :is="navigationIcon('day')" /></span>
                <span class="min-w-0 flex-1"><strong>Branch Manager:</strong> operating hours, calendar, services, pricing and branch day.</span>
              </li>
              <li class="sv-r-queue__row">
                <span
                  aria-hidden="true"
                  class="sv-r-icon sv-r-tone-teal"
                ><component :is="navigationIcon('staff')" /></span>
                <span class="min-w-0 flex-1"><strong>Human Resource:</strong> operational staff, eligibility, availability and compensation setup.</span>
              </li>
              <li class="sv-r-queue__row">
                <span
                  aria-hidden="true"
                  class="sv-r-icon sv-r-tone-sun"
                ><component :is="navigationIcon('payment')" /></span>
                <span class="min-w-0 flex-1"><strong>Front Office / Finance:</strong> clients, invoices, payment recording/validation, receipts and cash-up.</span>
              </li>
            </ul>
          </section>
        </div>
        <SvAlert
          severity="info"
          title="Branch performance is gated"
        >
          Revenue, service/staff performance and daily-report archive data remain unavailable behind External Gate W. This page does not fabricate empty metrics.
        </SvAlert>
      </div>
    </SvStateBoundary>
  </section>
</template>