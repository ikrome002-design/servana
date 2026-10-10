<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { usePersonnelEarningsStore } from '@/stores/personnelEarningsStore';

/**
 * In-section navigation for the seven My Earnings pages. Salary and Commission follow the server's
 * `tab_visibility`, so a model the user is not on is never offered; the direct route still renders
 * a safe non-applicable state rather than another person's data.
 */
const earnings = usePersonnelEarningsStore();
/** Same rule as the shell: only a positively reported plan can exclude a model-specific page. */
const applies = (tab: boolean) => {
  const visibility = earnings.overview.tab_visibility;
  return !visibility.has_current_plan || visibility.conflicting || tab;
};
const links = computed(() => [
  { name: 'personnel.earnings', label: 'Overview', show: true },
  { name: 'personnel.earnings-commission', label: 'Commission', show: applies(earnings.overview.tab_visibility.commission_tab) },
  { name: 'personnel.earnings-salary', label: 'Salary', show: applies(earnings.overview.tab_visibility.salary_tab) },
  { name: 'personnel.earnings-payouts', label: 'Payouts', show: true },
  { name: 'personnel.earnings-terms', label: 'Terms', show: true },
  { name: 'personnel.earnings-statements', label: 'Statements', show: true },
  { name: 'personnel.earnings-queries', label: 'Queries', show: true },
].filter((link) => link.show));
onMounted(() => { if (!earnings.overview.tab_visibility.has_current_plan) void earnings.fetchOverview(); });
</script>

<template>
  <nav
    aria-label="My earnings pages"
    class="mb-6"
  >
    <ul class="flex flex-wrap gap-2">
      <li
        v-for="link in links"
        :key="link.name"
      >
        <RouterLink
          :to="{ name: link.name }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-full border border-sv-border bg-sv-surface-raised px-4 text-sm font-semibold text-sv-text-secondary transition-colors duration-sv-fast hover:border-sv-brand hover:text-sv-text-heading motion-reduce:transition-none"
          exact-active-class="!border-sv-brand !bg-sv-selected-bg !text-sv-selected-fg"
        >
          {{ link.label }}
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>
