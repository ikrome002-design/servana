<script setup lang="ts">
import { onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import { useBranchStore } from '@/stores/branchStore';

// Branch detail (Scope §3.3). Branch-scoped: a non-assigned user 404s at the API.
const route = useRoute();
const branches = useBranchStore();
const id = String(route.params.id ?? '');

onMounted(() => {
  void branches.fetchBranch(id);
});
</script>

<template>
  <section class="p-4 md:p-6">
    <template v-if="branches.activeBranch">
      <div class="flex items-center justify-between">
        <h1 class="font-display text-2xl font-bold text-brand-deep">
          {{ branches.activeBranch.name }}
        </h1>
        <RouterLink
          :to="{ name: 'branch.operating-hours', params: { id } }"
          class="text-sm font-semibold text-brand-deep underline"
        >
          Operating hours
        </RouterLink>
      </div>
      <SvCard
        as="div"
        padding="md"
        class="mt-6 max-w-lg"
      >
        <dl class="grid grid-cols-2 gap-3 text-sm">
          <dt class="text-text-muted">
            Code
          </dt>
          <dd>{{ branches.activeBranch.code }}</dd>
          <dt class="text-text-muted">
            Status
          </dt>
          <dd>{{ branches.activeBranch.status }}</dd>
          <dt class="text-text-muted">
            Town
          </dt>
          <dd>{{ branches.activeBranch.town ?? '—' }}</dd>
          <dt class="text-text-muted">
            Phone
          </dt>
          <dd>{{ branches.activeBranch.phone ?? '—' }}</dd>
        </dl>
      </SvCard>
    </template>
  </section>
</template>
