<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';

const store = useBranchExperienceStore();
const busy = ref(false);
const actionError = ref<string | null>(null);
const state = computed(() => store.loading ? 'loading' : store.error ? 'error' : store.overview ? 'success' : 'empty');
const day = computed(() => store.overview?.day ?? null);
const isLive = computed(() => ['open', 'paused', 'reopened'].includes(day.value?.status ?? ''));
const blockers = computed(() => [...new Set([...(day.value?.close_blockers ?? []), ...(day.value?.financial_close_blockers ?? [])])]);

function humanize(value: string): string {
  const label = value.replaceAll('_', ' ');
  return label.charAt(0).toUpperCase() + label.slice(1);
}

async function transition(): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    await store.transitionDay(isLive.value ? 'close' : 'open');
  } catch (error: unknown) {
    actionError.value = axios.isAxiosError(error) && error.apiError
      ? error.apiError.message
      : 'The branch-day state could not be changed.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => { void store.fetchOverview(); });
</script>

<template>
  <section
    class="mx-auto max-w-5xl"
    data-testid="branch-day"
  >
    <SvPageHeader
      title="Branch day"
      eyebrow="Branch operations"
      :description="store.overview ? `${store.overview.branch.name} · ${store.overview.business_date}` : 'Open, monitor and close the Nairobi business day.'"
    >
      <template #actions>
        <SvButton
          variant="primary"
          :loading="busy"
          :disabled="isLive && blockers.length > 0"
          @click="transition"
        >
          {{ isLive ? 'Close branch day' : 'Open branch day' }}
        </SvButton>
      </template>
    </SvPageHeader>
    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No assigned branch is available."
      @retry="store.fetchOverview()"
    >
      <template v-if="store.overview && day">
        <SvAlert
          v-if="actionError"
          severity="error"
          title="Day action unavailable"
          class="mb-4"
        >
          {{ actionError }}
        </SvAlert>
        <div class="grid gap-4 md:grid-cols-3">
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-brand"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Current state
            </p><p class="mt-3 text-2xl font-bold capitalize text-heading">
              {{ day.status.replaceAll('_', ' ') }}
            </p><p class="text-sm text-text-muted">
              Queue {{ day.queue_is_open ? 'open' : 'closed' }}
            </p>
          </SvCard>
          <SvCard as="article">
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Opened
            </p><p class="mt-3 text-lg font-bold text-heading">
              {{ day.opened_at ? new Date(day.opened_at).toLocaleString() : 'Not opened' }}
            </p>
          </SvCard>
          <SvCard as="article">
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Cash-up
            </p><p class="mt-3 text-lg font-bold capitalize text-heading">
              {{ store.overview.cash_up?.status.replaceAll('_', ' ') ?? 'Not prepared' }}
            </p><RouterLink
              class="mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'branch.cash-up' }"
            >
              Prepare cash-up
            </RouterLink>
          </SvCard>
        </div>
        <SvCard
          as="section"
          class="mt-4"
        >
          <h2 class="font-display text-lg font-bold text-heading">
            Close readiness
          </h2>
          <p
            v-if="blockers.length === 0"
            class="mt-3 rounded-control bg-sv-success-bg px-3 py-2 text-sm font-semibold text-sv-success-fg"
            role="status"
          >
            No current close blockers.
          </p>
          <ul
            v-else
            class="mt-3 grid gap-2 sm:grid-cols-2"
            aria-label="Close blockers"
          >
            <li
              v-for="blocker in blockers"
              :key="blocker"
              class="rounded-control border border-sv-warning-border bg-sv-warning-bg px-3 py-2 text-sm font-semibold text-sv-warning-fg"
            >
              {{ humanize(blocker) }}
            </li>
          </ul>
          <p class="mt-4 text-sm text-text-muted">
            Branch submits the cash-up as maker. Finance approval remains the independent checker before financial close.
          </p>
        </SvCard>
      </template>
    </SvStateBoundary>
  </section>
</template>
