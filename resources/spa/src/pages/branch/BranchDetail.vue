<script setup lang="ts">
import { onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import { useBranchStore } from '@/stores/branchStore';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

// Branch detail (Scope §3.3). Branch-scoped: a non-assigned user 404s at the API.
const route = useRoute();
const branches = useBranchStore();
const id = String(route.params.id ?? '');

onMounted(() => {
  void branches.fetchBranch(id);
});
</script>

<template>
  <section class="mx-auto max-w-6xl">
    <template v-if="branches.activeBranch">
      <SvPageHeader
        :title="branches.activeBranch.name"
        eyebrow="Branch"
      >
        <template #actions>
          <RouterLink
            :to="{ name: 'branch.operating-hours', params: { id } }"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-sv-border-strong bg-sv-surface-raised px-4 py-2 text-sm font-semibold text-sv-text-heading hover:bg-sv-surface-subtle"
          >
            Operating hours
          </RouterLink>
          <!-- REM-SCR-002B — the date-specific calendar overrides sit beside the weekly hours. -->
          <RouterLink
            :to="{ name: 'branch.calendar', params: { id } }"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-sv-border-strong bg-sv-surface-raised px-4 py-2 text-sm font-semibold text-sv-text-heading hover:bg-sv-surface-subtle"
          >
            Branch calendar
          </RouterLink>
        </template>
      </SvPageHeader>
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
