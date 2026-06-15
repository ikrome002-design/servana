<script setup lang="ts">
import { onMounted } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import { useStaffStore } from '@/stores/staffStore';
import type { MembershipStatus } from '@/types/models';

// Staff roster with status badges (Scope §3.4 Staff Operational Screen).
const staff = useStaffStore();

const badgeClass: Record<MembershipStatus, string> = {
  invited: 'bg-warning/15 text-warning',
  active: 'bg-success/15 text-success',
  suspended: 'bg-warning/15 text-warning',
  deactivated: 'bg-error/15 text-error',
};

onMounted(() => {
  void staff.fetchStaff();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex items-center justify-between">
      <h1 class="font-display text-2xl font-bold text-brand-deep">
        Staff
      </h1>
      <RouterLink
        :to="{ name: 'hr.invitations' }"
        class="text-sm font-semibold text-brand-deep underline"
      >
        Invitations
      </RouterLink>
    </div>

    <p
      v-if="!staff.loading && staff.staff.length === 0"
      class="mt-6 text-text-muted"
    >
      No staff yet.
    </p>

    <ul
      class="mt-6 flex flex-col gap-3"
      aria-label="Staff members"
    >
      <li
        v-for="member in staff.staff"
        :key="member.id"
      >
        <SvCard
          as="article"
          padding="md"
        >
          <div class="flex items-center justify-between">
            <div>
              <h2 class="font-display text-base font-semibold text-brand-deep">
                {{ member.display_name }}
              </h2>
              <p class="text-sm text-text-muted">
                {{ member.role ?? '—' }} · {{ member.phone }}
              </p>
            </div>
            <span
              v-if="member.status"
              class="rounded-full px-2 py-0.5 text-xs font-medium"
              :class="badgeClass[member.status]"
              data-testid="staff-status"
            >{{ member.status }}</span>
          </div>
        </SvCard>
      </li>
    </ul>
  </section>
</template>
