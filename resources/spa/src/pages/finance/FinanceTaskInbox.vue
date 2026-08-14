<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import { useFinanceWorkspaceStore } from '@/stores/financeWorkspaceStore';

const store = useFinanceWorkspaceStore();
const severity = ref('');
const overview = computed(() => store.overview);
const state = computed(() => (store.overviewLoading ? 'loading' : store.overviewError ? 'error' : overview.value ? 'success' : 'empty'));
const tasks = computed(() => (overview.value?.tasks ?? [])
  .filter((task) => severity.value === '' || task.severity === severity.value)
  .sort((a, b) => {
    const rank = { critical: 0, high: 1, medium: 2 };
    return rank[a.severity] - rank[b.severity] || b.count - a.count;
  }));

const severityOptions = [
  { value: '', label: 'All priorities' },
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
];

function tone(value: string): SvStatusTone {
  if (value === 'critical') return 'error';
  if (value === 'high') return 'warning';
  return 'info';
}

onMounted(() => {
  void store.fetchOverview();
});
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="finance-task-inbox"
  >
    <SvPageHeader
      title="Finance task inbox"
      eyebrow="Prioritized control work"
      description="A server-derived view of shipped Finance queues. Assignment and claim controls are absent because no authoritative task-assignment runtime exists."
    >
      <template #actions>
        <div class="w-48">
          <SvSelect
            id="finance-task-priority"
            v-model="severity"
            label="Priority"
            :options="severityOptions"
          />
        </div>
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.overviewError ?? undefined"
      empty-message="No assigned Finance branch is available."
      @retry="store.fetchOverview()"
    >
      <template v-if="overview">
        <div class="grid gap-3 sm:grid-cols-3">
          <SvCard
            as="article"
            class="border-l-4 border-l-sv-error-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Critical
            </p>
            <p class="mt-2 text-3xl font-bold text-heading">
              {{ overview.tasks.filter((task) => task.severity === 'critical').reduce((sum, task) => sum + task.count, 0) }}
            </p>
          </SvCard>
          <SvCard
            as="article"
            class="border-l-4 border-l-sv-warning-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              High
            </p>
            <p class="mt-2 text-3xl font-bold text-heading">
              {{ overview.tasks.filter((task) => task.severity === 'high').reduce((sum, task) => sum + task.count, 0) }}
            </p>
          </SvCard>
          <SvCard
            as="article"
            class="border-l-4 border-l-sv-info-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Medium
            </p>
            <p class="mt-2 text-3xl font-bold text-heading">
              {{ overview.tasks.filter((task) => task.severity === 'medium').reduce((sum, task) => sum + task.count, 0) }}
            </p>
          </SvCard>
        </div>

        <div
          class="mt-5 space-y-3"
          aria-label="Finance tasks"
        >
          <SvCard
            v-for="task in tasks"
            :key="task.key"
            as="article"
            class="transition-shadow motion-reduce:transition-none hover:shadow-card"
          >
            <div class="flex flex-wrap items-center justify-between gap-4">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <SvStatusBadge
                    :label="task.severity"
                    :tone="tone(task.severity)"
                    size="sm"
                    sr-prefix="Priority:"
                  />
                  <span
                    v-if="task.step_up_required"
                    class="rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
                  >Fresh step-up</span>
                </div>
                <h2 class="mt-2 font-display text-lg font-bold text-heading">
                  {{ task.label }}
                </h2>
                <p class="mt-1 text-sm text-text-muted">
                  {{ task.maker_checker }} · server authorization remains the boundary
                </p>
              </div>
              <div class="flex items-center gap-4">
                <span
                  class="text-3xl font-extrabold text-heading"
                  :aria-label="`${task.count} items`"
                >{{ task.count }}</span>
                <RouterLink
                  class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep"
                  :to="{ name: task.route_name }"
                >
                  Open queue
                </RouterLink>
              </div>
            </div>
          </SvCard>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>
