<script setup lang="ts">
import { computed, onMounted } from 'vue';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
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
  <section class="mx-auto max-w-6xl p-4 md:p-6">
    <SvPageHeader
      title="Branches"
      :eyebrow="merchantOwnerView ? 'Merchant footprint' : undefined"
      :description="merchantOwnerView
        ? 'Create and oversee branches within the current subscription entitlement. Branch operations remain with their assigned roles.'
        : undefined"
    >
      <template #actions>
        <PermissionGate permission="branches.create">
          <RouterLink :to="{ name: 'branch.create' }">
            <SvButton variant="primary">
              Add branch
            </SvButton>
          </RouterLink>
        </PermissionGate>
      </template>
    </SvPageHeader>

    <div
      v-if="merchantOwnerView && !branches.loading"
      class="mb-6 grid gap-3 rounded-card border border-border bg-sv-surface-warm p-4 md:grid-cols-2 md:p-5"
      aria-label="Branch portfolio summary"
    >
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
          Active branches
        </p>
        <p class="mt-1 font-display text-2xl font-bold text-heading">
          {{ activeBranches }}
        </p>
      </div>
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
          Portfolio total
        </p>
        <p class="mt-1 font-display text-2xl font-bold text-heading">
          {{ branches.branches.length }}
        </p>
      </div>
    </div>

    <p
      v-if="!branches.loading && branches.branches.length === 0"
      class="mt-6 text-text-muted"
    >
      No branches yet.
    </p>

    <ul
      class="grid grid-cols-1 gap-4 md:grid-cols-2"
      aria-label="Branches"
    >
      <li
        v-for="branch in branches.branches"
        :key="branch.id"
      >
        <SvCard
          as="article"
          padding="md"
        >
          <div class="flex items-start justify-between gap-4">
            <h2 class="font-display text-base font-semibold text-heading">
              {{ branch.name }}
            </h2>
            <span
              class="rounded-full px-2 py-0.5 text-xs font-medium"
              :class="branch.status === 'active'
                ? 'bg-success/15 text-success'
                : 'bg-surface-alt text-text-muted'"
              data-testid="branch-status"
            >{{ branch.status }}</span>
          </div>
          <dl class="mt-4 grid grid-cols-2 gap-3 rounded-control bg-surface-alt p-3 text-sm">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Branch code
              </dt>
              <dd class="mt-1 font-medium text-text">
                {{ branch.code }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Town
              </dt>
              <dd class="mt-1 font-medium text-text">
                {{ branch.town ?? 'Not set' }}
              </dd>
            </div>
          </dl>
          <RouterLink
            :to="merchantOwnerView
              ? { name: 'merchant.branch-detail', params: { branchUlid: branch.id } }
              : { name: 'branch.detail', params: { id: branch.id } }"
            class="sv-focus-ring mt-4 inline-flex min-h-sv-touch items-center rounded-control text-sm font-semibold text-heading underline underline-offset-2"
          >
            View branch
          </RouterLink>
        </SvCard>
      </li>
    </ul>
  </section>
</template>
