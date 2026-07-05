<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useFlaggedEventStore } from '@/stores/flaggedEventStore';

/**
 * Flagged-event detail + review (Plan §13.2, §80; Phase 19). The review workflow
 * (start review / resolve / dismiss / reopen) mutates ONLY review metadata; the
 * linked source audit row is immutable and shown as a masked summary. Controls
 * render only when the server-derived capability map AND the current state permit
 * the transition — frontend gating is UX; the backend state machine is authoritative.
 */
const route = useRoute();
const store = useFlaggedEventStore();

const busy = ref(false);
const actionError = ref<string | null>(null);
const notes = ref('');
const confirming = ref<null | 'resolve' | 'dismiss'>(null);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (!store.current) return 'empty';
  return 'success';
});

const status = computed(() => store.current?.status ?? null);
const can = computed(() => store.current?.can ?? {});

const canStartReview = computed(() => Boolean(can.value.update_status) && (status.value === 'open' || status.value === 'reopened'));
const canResolveOrDismiss = computed(() => Boolean(can.value.resolve_metadata) && status.value === 'under_review');
const canReopen = computed(() => Boolean(can.value.update_status) && (status.value === 'resolved' || status.value === 'dismissed'));

function extractError(e: unknown, fallback: string): string {
  const err = e as { response?: { status?: number; data?: { error?: { code?: string; message?: string } } } };
  if (err.response?.data?.error?.code === 'invalid_state_transition') {
    return 'That action is no longer valid for this event’s current status.';
  }
  return err.response?.data?.error?.message ?? fallback;
}

async function run(action: 'start-review' | 'resolve' | 'dismiss' | 'reopen'): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    const payload: Record<string, string> =
      action === 'resolve' || action === 'dismiss' ? { review_notes: notes.value.trim() } : {};
    await store.transition(String(route.params.id), action, payload);
    confirming.value = null;
    notes.value = '';
  } catch (e: unknown) {
    actionError.value = extractError(e, 'The action could not be completed.');
  } finally {
    busy.value = false;
  }
}

function openConfirm(kind: 'resolve' | 'dismiss'): void {
  actionError.value = null;
  confirming.value = kind;
}

onMounted(() => {
  void store.fetchOne(String(route.params.id));
});
</script>

<template>
  <section class="p-4 md:p-6">
    <RouterLink
      :to="{ name: 'audit.flagged-events' }"
      class="inline-flex min-h-[44px] items-center text-sm font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      ← Back to flagged events
    </RouterLink>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      error-message="We couldn’t load this flagged event."
      empty-message="This flagged event was not found."
      @retry="() => store.fetchOne(String(route.params.id))"
    >
      <div
        v-if="store.current"
        class="flex flex-col gap-4"
      >
        <header class="flex flex-wrap items-center justify-between gap-2">
          <h1 class="font-display text-2xl font-bold text-heading">
            Flagged event review
          </h1>
          <span
            class="inline-flex items-center rounded-control bg-surface-alt px-3 py-1 text-sm font-medium"
            data-testid="flagged-detail-status"
          >
            {{ store.current.status }}
          </span>
        </header>

        <p
          v-if="actionError"
          class="text-sm text-error"
          role="alert"
          data-testid="flagged-action-error"
        >
          {{ actionError }}
        </p>

        <!-- Immutable source audit row (masked). No mutation controls here. -->
        <SvCard
          as="section"
          padding="md"
          data-testid="flagged-source"
        >
          <h2 class="font-display text-lg font-semibold text-heading">
            Source event (read-only)
          </h2>
          <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Action
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.audit_event?.action ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Severity
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.audit_event?.severity ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Actor
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.audit_event?.actor ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Occurred
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.audit_event?.occurred_at ?? '—' }}
              </dd>
            </div>
          </dl>
        </SvCard>

        <!-- Review metadata + actions. -->
        <SvCard
          as="section"
          padding="md"
        >
          <h2 class="font-display text-lg font-semibold text-heading">
            Review
          </h2>
          <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Assigned to
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.assigned_to ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Resolved by
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.resolved_by ?? '—' }}
              </dd>
            </div>
          </dl>
          <p
            v-if="store.current.review_notes"
            class="mt-2 text-sm text-text"
          >
            {{ store.current.review_notes }}
          </p>

          <div class="mt-4 flex flex-wrap gap-2">
            <SvButton
              v-if="canStartReview"
              data-testid="flagged-start-review"
              :loading="busy"
              @click="run('start-review')"
            >
              Start review
            </SvButton>
            <SvButton
              v-if="canResolveOrDismiss"
              data-testid="flagged-resolve"
              :loading="busy"
              @click="openConfirm('resolve')"
            >
              Resolve
            </SvButton>
            <SvButton
              v-if="canResolveOrDismiss"
              variant="secondary"
              data-testid="flagged-dismiss"
              :loading="busy"
              @click="openConfirm('dismiss')"
            >
              Dismiss
            </SvButton>
            <SvButton
              v-if="canReopen"
              variant="secondary"
              data-testid="flagged-reopen"
              :loading="busy"
              @click="run('reopen')"
            >
              Reopen
            </SvButton>
          </div>
        </SvCard>
      </div>
    </SvStateBoundary>

    <SvModal
      :open="confirming !== null"
      :title="confirming === 'dismiss' ? 'Dismiss this flagged event?' : 'Resolve this flagged event?'"
      description="This records a review outcome. Review notes are required."
      @close="confirming = null"
    >
      <div class="mt-2">
        <SvTextarea
          id="flagged-review-notes"
          v-model="notes"
          label="Review notes"
          :rows="4"
        />
      </div>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="confirming = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="flagged-confirm"
          :loading="busy"
          :disabled="notes.trim().length < 3"
          @click="run(confirming === 'dismiss' ? 'dismiss' : 'resolve')"
        >
          {{ confirming === 'dismiss' ? 'Dismiss' : 'Resolve' }}
        </SvButton>
      </div>
    </SvModal>
  </section>
</template>
