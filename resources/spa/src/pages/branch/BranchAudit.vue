<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';

const store = useBranchExperienceStore();
const state = computed(() => store.loading ? 'loading' : store.error ? 'error' : store.auditEvents.length ? 'success' : 'empty');
const tone = (severity: string): 'neutral' | 'info' | 'warning' | 'error' => {
  if (severity === 'critical' || severity === 'high') return 'error';
  if (severity === 'warning') return 'warning';
  if (severity === 'notice') return 'info';
  return 'neutral';
};

onMounted(() => { void store.fetchAudit({ sort: '-created_at' }); });
</script>

<template>
  <section
    class="mx-auto max-w-5xl"
    data-testid="branch-audit"
  >
    <SvPageHeader
      title="Branch audit"
      eyebrow="Operational visibility"
      description="A masked, read-only timeline for your assigned branch. Raw audit review, flagged-event workflow and exports stay with the Audit account."
    />
    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No branch audit events are available."
      @retry="store.fetchAudit({ sort: '-created_at' })"
    >
      <ol
        class="relative grid gap-3 border-l border-sv-border pl-5"
        aria-label="Branch audit timeline"
      >
        <li
          v-for="event in store.auditEvents"
          :key="event.id"
          class="relative"
        >
          <span
            class="absolute -left-[1.63rem] top-5 h-3 w-3 rounded-full border-2 border-sv-surface bg-sv-brand"
            aria-hidden="true"
          />
          <SvCard as="article">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="font-display font-bold text-heading">
                  {{ event.action.replaceAll('.', ' ').replaceAll('_', ' ') }}
                </h2><p class="mt-1 text-sm text-text-muted">
                  {{ event.actor ?? 'System' }} · {{ event.subject_type ?? 'Branch record' }}
                </p>
              </div>
              <SvStatusBadge
                :label="event.severity"
                :tone="tone(event.severity)"
              />
            </div>
            <time
              class="mt-3 block text-xs text-text-muted"
              :datetime="event.created_at ?? undefined"
            >{{ event.created_at ? new Date(event.created_at).toLocaleString() : 'Time unavailable' }}</time>
          </SvCard>
        </li>
      </ol>
    </SvStateBoundary>
  </section>
</template>
