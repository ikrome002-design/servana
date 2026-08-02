<script setup lang="ts">
import { computed, ref } from 'vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import LegalAcknowledgement from '@/components/legal/LegalAcknowledgement.vue';
import { getStartedChecklist } from '@/content/getStartedContent';
import { useGetStartedStore } from '@/stores/getStartedStore';
import type { RoleIdentity } from '@/types/roles';

/**
 * Guided get-started checklist (Plan §27.2; Scope §3.2). Persists completion,
 * dismissal, and the legal acknowledgement per user + role (getStartedStore).
 * Fully keyboard operable; progress is announced via an aria-live region. Deep
 * links target only live routes; future steps show their owning phase and never
 * link anywhere.
 */
const props = defineProps<{ identity: RoleIdentity; userId: string }>();
const emit = defineEmits<{ dismiss: [] }>();

const store = useGetStartedStore();
const items = computed(() => getStartedChecklist(props.identity));
const progress = computed(() => store.progress(props.userId, props.identity));

const ackOpen = ref(false);

function toggle(itemId: string): void {
  store.toggle(props.userId, props.identity, itemId);
}

function onAcknowledged(): void {
  store.acknowledgeLegal(props.userId, props.identity);
  ackOpen.value = false;
}
</script>

<template>
  <section aria-labelledby="get-started-heading">
    <header class="mb-4">
      <h2
        id="get-started-heading"
        class="font-display text-xl font-bold text-heading"
      >
        Get started
      </h2>
      <p class="mt-1 text-sm text-text-muted">
        A guided checklist to set up and take your first actions. Your progress is saved on
        this device.
      </p>
    </header>

    <!-- Progress -->
    <div class="mb-4">
      <div
        role="progressbar"
        :aria-valuenow="progress.percent"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-label="Get-started progress"
        class="h-2 w-full overflow-hidden rounded-full bg-surface-alt"
      >
        <div
          class="h-full rounded-full bg-primary transition-all motion-reduce:transition-none"
          :style="{ width: `${progress.percent}%` }"
        />
      </div>
      <p
        class="mt-2 text-sm font-medium text-text"
        aria-live="polite"
      >
        {{ progress.completed }} of {{ progress.total }} complete
      </p>
    </div>

    <ul class="space-y-2">
      <li
        v-for="item in items"
        :key="item.id"
        class="flex items-center justify-between gap-3 rounded-card border border-border bg-surface px-4 py-3"
      >
        <label class="flex flex-1 items-center gap-3 text-sm text-text">
          <input
            type="checkbox"
            class="h-5 w-5 rounded border-border text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-60"
            :checked="store.isCompleted(userId, identity, item.id)"
            :disabled="item.kind === 'acknowledge'"
            :data-testid="`checklist-${item.id}`"
            @change="toggle(item.id)"
          >
          <span :class="store.isCompleted(userId, identity, item.id) ? 'text-text-muted line-through' : ''">
            {{ item.label }}
          </span>
        </label>

        <RouterLink
          v-if="item.kind === 'action' && item.routeName"
          :to="{ name: item.routeName }"
          class="inline-flex min-h-[44px] items-center rounded-control px-3 py-2 text-sm font-semibold text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          Open
        </RouterLink>
        <button
          v-else-if="item.kind === 'acknowledge'"
          type="button"
          class="inline-flex min-h-[44px] items-center rounded-control border border-border px-3 py-2 text-sm font-semibold text-heading hover:bg-surface-alt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-60"
          :disabled="store.isLegalAcknowledged(userId, identity)"
          data-testid="open-acknowledgement"
          @click="ackOpen = true"
        >
          {{ store.isLegalAcknowledged(userId, identity) ? 'Acknowledged' : 'Review & acknowledge' }}
        </button>
        <span
          v-else
          class="rounded-full bg-surface-alt px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-text-muted"
          :title="`Available in ${item.phase}`"
        >{{ item.phase }}</span>
      </li>
    </ul>

    <div class="mt-4 flex justify-end">
      <button
        type="button"
        class="inline-flex min-h-[44px] items-center rounded-control px-3 py-2 text-sm font-medium text-text-muted hover:bg-surface-alt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        data-testid="dismiss-get-started"
        @click="emit('dismiss')"
      >
        Dismiss for now
      </button>
    </div>

    <SvDialog
      :open="ackOpen"
      title="Acknowledge your role's terms"
      description="Please review and accept the documents that govern your account."
      @close="ackOpen = false"
    >
      <LegalAcknowledgement
        :identity="identity"
        @acknowledged="onAcknowledged"
      />
    </SvDialog>
  </section>
</template>
