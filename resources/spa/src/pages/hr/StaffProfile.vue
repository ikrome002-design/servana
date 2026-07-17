<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import { useBranchStore } from '@/stores/branchStore';
import { useStaffStore } from '@/stores/staffStore';

// Staff profile (Scope §3.4). Shows the membership status badge + branch
// assignment. Editing/lifecycle actions are authority-gated by the API.
const route = useRoute();
const staff = useStaffStore();
const branches = useBranchStore();
const id = String(route.params.id ?? '');

const member = computed(() => staff.staff.find((s) => s.id === id) ?? null);
const branchName = computed(() => {
  const bid = member.value?.primary_branch_id;
  return branches.branches.find((b) => b.id === bid)?.name ?? '—';
});

onMounted(async () => {
  if (staff.staff.length === 0) await staff.fetchStaff();
  if (branches.branches.length === 0) await branches.fetchBranches();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <template v-if="member">
      <h1 class="font-display text-2xl font-bold text-heading">
        {{ member.display_name }}
      </h1>
      <SvCard
        as="div"
        padding="md"
        class="mt-6 max-w-lg"
      >
        <dl class="grid grid-cols-2 gap-3 text-sm">
          <dt class="text-text-muted">
            Role
          </dt>
          <dd>{{ member.role ?? '—' }}</dd>
          <dt class="text-text-muted">
            Status
          </dt>
          <dd data-testid="profile-status">
            {{ member.status ?? '—' }}
          </dd>
          <dt class="text-text-muted">
            Phone
          </dt>
          <dd>{{ member.phone }}</dd>
          <dt class="text-text-muted">
            Branch
          </dt>
          <dd>{{ branchName }}</dd>
          <dt class="text-text-muted">
            Employment
          </dt>
          <dd>{{ member.employment_type }} · {{ member.employment_status }}</dd>
        </dl>
      </SvCard>
    </template>
  </section>
</template>
