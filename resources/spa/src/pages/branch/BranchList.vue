<script setup lang="ts">
import { onMounted } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import { useAuthStore } from '@/stores/authStore';
import { useBranchStore } from '@/stores/branchStore';

// Branch directory (Scope §3.3). Create is admin-only (the API enforces it; the
// button is hidden for non-admins as UX only).
const branches = useBranchStore();
const auth = useAuthStore();

onMounted(() => {
  void branches.fetchBranches();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex items-center justify-between">
      <h1 class="font-display text-2xl font-bold text-brand-deep">
        Branches
      </h1>
      <RouterLink
        v-if="auth.isMerchantAdmin()"
        :to="{ name: 'branch.create' }"
      >
        <SvButton variant="primary">
          Add branch
        </SvButton>
      </RouterLink>
    </div>

    <p
      v-if="!branches.loading && branches.branches.length === 0"
      class="mt-6 text-text-muted"
    >
      No branches yet.
    </p>

    <ul
      class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
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
          <div class="flex items-center justify-between">
            <h2 class="font-display text-base font-semibold text-brand-deep">
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
          <p class="mt-1 text-sm text-text-muted">
            {{ branch.code }} · {{ branch.town ?? '—' }}
          </p>
          <RouterLink
            :to="{ name: 'branch.detail', params: { id: branch.id } }"
            class="mt-3 inline-block text-sm font-semibold text-brand-deep underline"
          >
            View branch
          </RouterLink>
        </SvCard>
      </li>
    </ul>
  </section>
</template>
