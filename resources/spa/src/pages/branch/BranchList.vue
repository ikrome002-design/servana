<script setup lang="ts">
import { computed, onMounted } from 'vue';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import SvKpiCard from '@/components/refined/SvKpiCard.vue';
import SvButton from '@/components/ui/SvButton.vue';
import { navigationIcon } from '@/navigation/navigationIcons';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import { useBranchStore } from '@/stores/branchStore';

// Branch directory (Scope §3.3). Create requires the `branches.create`
// permission (Plan §10.3). The API enforces it; the button is hidden for users
// without the permission as UX only.
const branches = useBranchStore();
withDefaults(defineProps<{ merchantOwnerView?: boolean }>(), {
  merchantOwnerView: false,
});

onMounted(() => {
  void branches.fetchBranches();
});

const activeBranches = computed(() => branches.branches.filter((branch) => branch.status === 'active').length);
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the branch portfolio. Two server-backed portfolio figures lead, then
    each branch is a card with its identity, operational status and facts; for an owner with the
    create permission the grid closes on a dashed "add a branch" tile (the Billings reference's
    add-new affordance) beside the header action. Nothing here operates a branch.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Branches"
      :eyebrow="merchantOwnerView ? 'Merchant footprint' : undefined"
      :description="merchantOwnerView
        ? 'Create and oversee branches within the current subscription entitlement. Branch operations remain with their assigned roles.'
        : undefined"
    >
      <template #actions>
        <PermissionGate permission="branches.create">
          <RouterLink
            v-if="merchantOwnerView"
            :to="{ name: 'merchant.branch-create' }"
          >
            <SvButton variant="primary">
              Add branch
            </SvButton>
          </RouterLink>
        </PermissionGate>
      </template>
    </SvPageHeader>

    <div
      v-if="merchantOwnerView && !branches.loading"
      class="sv-r-grid sv-r-cols-2 mb-6"
      aria-label="Branch portfolio summary"
    >
      <SvKpiCard
        label="Active branches"
        tone="green"
        :icon="navigationIcon('branch')"
        hint="Branches currently operating."
      >
        {{ activeBranches }}
      </SvKpiCard>
      <SvKpiCard
        label="Portfolio total"
        tone="warm"
        :icon="navigationIcon('merchant')"
        hint="Every branch in your portfolio, in any status."
      >
        {{ branches.branches.length }}
      </SvKpiCard>
    </div>

    <p
      v-if="!branches.loading && branches.branches.length === 0"
      class="sv-r-panel sv-r-panel--dashed sv-r-lede mt-6 text-center"
    >
      No branches yet.
    </p>

    <ul
      class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
      aria-label="Branches"
    >
      <li
        v-for="branch in branches.branches"
        :key="branch.id"
      >
        <article class="sv-r-record flex h-full flex-col">
          <div class="flex items-start gap-3">
            <span
              aria-hidden="true"
              class="sv-r-icon sv-r-icon--lg"
              :class="branch.status === 'active' ? 'sv-r-tone-green' : 'sv-r-tone-neutral'"
            >
              <component :is="navigationIcon('branch')" />
            </span>
            <div class="min-w-0 flex-1">
              <h2 class="sv-r-title break-words">
                {{ branch.name }}
              </h2>
              <span
                class="sv-r-chip sv-r-chip--tone sv-r-chip--dot mt-1.5"
                :class="branch.status === 'active' ? 'sv-r-tone-green' : 'sv-r-tone-neutral'"
                data-testid="branch-status"
              >{{ branch.status }}</span>
            </div>
          </div>
          <dl class="sv-r-strip mt-4">
            <div>
              <dt>Branch code</dt>
              <dd>{{ branch.code }}</dd>
            </div>
            <div>
              <dt>Town</dt>
              <dd>{{ branch.town ?? 'Not set' }}</dd>
            </div>
          </dl>
          <RouterLink
            :to="merchantOwnerView
              ? { name: 'merchant.branch-detail', params: { branchUlid: branch.id } }
              : { name: 'branch.branch-profile' }"
            class="sv-r-action sv-r-action--quiet sv-focus-ring mt-4 self-start"
          >
            View branch
          </RouterLink>
        </article>
      </li>
      <li v-if="merchantOwnerView && !branches.loading">
        <PermissionGate permission="branches.create">
          <RouterLink
            :to="{ name: 'merchant.branch-create' }"
            class="sv-focus-ring flex h-full min-h-[12rem] flex-col items-center justify-center gap-3 rounded-card border-2 border-dashed border-sv-border-strong p-6 text-center text-sm font-semibold text-sv-text-heading transition-colors duration-sv-fast hover:bg-sv-surface-raised"
            data-testid="branch-add-tile"
          >
            <span
              aria-hidden="true"
              class="sv-r-icon sv-r-tone-warm"
            >+</span>
            Add a branch
            <span class="sv-r-muted font-normal">Within your current subscription entitlement.</span>
          </RouterLink>
        </PermissionGate>
      </li>
    </ul>
  </section>
</template>