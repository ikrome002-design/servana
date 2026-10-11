<script setup lang="ts">
/**
 * Flag review — supporting page of Flagged Events (Plan §13.2, §80; Phase 19; Phase UI-15).
 *
 * The review workflow (start review / resolve / dismiss / reopen) mutates ONLY review metadata;
 * the linked source audit row is immutable and shown as a masked summary. Controls render only
 * when the server-derived capability map AND the current state permit the transition — frontend
 * gating is UX; the backend state machine is authoritative and a stale transition is reported,
 * not retried.
 */
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import AuditReviewBadge from '@/components/audit/AuditReviewBadge.vue';
import AuditSeverityBadge from '@/components/audit/AuditSeverityBadge.vue';
import SvBreadcrumbs from '@/components/ui/SvBreadcrumbs.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { SvIconLocked } from '@/design-system/icons';
import { useFlaggedEventStore } from '@/stores/flaggedEventStore';
import { humanizeAction, humanizeSubject } from '@/utils/auditPresentation';

const route = useRoute();
const store = useFlaggedEventStore();

const busy = ref(false);
const actionError = ref<string | null>(null);
const success = ref<string | null>(null);
const notes = ref('');
const confirming = ref<null | 'resolve' | 'dismiss'>(null);

const flagId = computed(() => String(route.params.flagUlid));

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
const noteErrors = computed(() => (notes.value.length > 0 && notes.value.trim().length < 3 ? ['Write at least three characters.'] : []));

const crumbs = computed(() => [
  { label: 'Flagged Events', to: { name: 'audit.audit-flags' } },
  { label: 'Review' },
]);

const DONE: Record<string, string> = {
  'start-review': 'Review started — you are now the reviewer.',
  resolve: 'Flag resolved with your review note.',
  dismiss: 'Flag dismissed with your review note.',
  reopen: 'Flag reopened for review.',
};

function extractError(e: unknown, fallback: string): string {
  const err = e as { response?: { status?: number; data?: { error?: { code?: string; message?: string } } } };
  if (err.response?.data?.error?.code === 'invalid_state_transition') {
    return 'That action is no longer valid for this flag’s current status. The latest state is shown.';
  }
  if (err.response?.status === 403) return 'Your account cannot make this review change.';
  return err.response?.data?.error?.message ?? fallback;
}

async function run(action: 'start-review' | 'resolve' | 'dismiss' | 'reopen'): Promise<void> {
  busy.value = true;
  actionError.value = null;
  success.value = null;
  try {
    const payload: Record<string, string> =
      action === 'resolve' || action === 'dismiss' ? { review_notes: notes.value.trim() } : {};
    await store.transition(flagId.value, action, payload);
    confirming.value = null;
    notes.value = '';
    success.value = DONE[action];
  } catch (e: unknown) {
    actionError.value = extractError(e, 'The action could not be completed.');
    confirming.value = null;
    void store.fetchOne(flagId.value);
  } finally {
    busy.value = false;
  }
}

function openConfirm(kind: 'resolve' | 'dismiss'): void {
  actionError.value = null;
  confirming.value = kind;
}

onMounted(() => {
  void store.fetchOne(flagId.value);
});
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="audit-flag-detail"
  >
    <SvBreadcrumbs
      :items="crumbs"
      class="mb-3"
    />

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load this flag."
      empty-message="This flag is not available in your branch scope."
      @retry="() => store.fetchOne(flagId)"
    >
      <div
        v-if="store.current"
        class="flex flex-col gap-5"
      >
        <header class="sv-review-hero relative isolate overflow-hidden rounded-card border border-sv-border p-5 shadow-raised md:p-7">
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
            Flag review
          </p>
          <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <h1 class="break-words font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl">
              {{ store.current.audit_event ? humanizeAction(store.current.audit_event.action) : 'Flagged event' }}
            </h1>
            <span data-testid="flagged-detail-status">
              <AuditReviewBadge
                :status="store.current.status"
                size="md"
              />
            </span>
          </div>
          <dl class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Flagged
              </dt>
              <dd class="mt-0.5 text-sv-text">
                <SvDateTime :value="store.current.created_at" />
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Reviewer
              </dt>
              <dd class="mt-0.5 text-sv-text">
                {{ store.current.assigned_to ?? 'Not yet started' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Outcome recorded by
              </dt>
              <dd class="mt-0.5 text-sv-text">
                {{ store.current.resolved_by ?? 'No outcome yet' }}
              </dd>
            </div>
          </dl>

          <div class="mt-5 flex flex-wrap gap-2">
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
          <p
            v-if="success"
            class="mt-3 text-sm font-semibold text-sv-success-fg"
            role="status"
            data-testid="flagged-action-success"
          >
            {{ success }}
          </p>
          <p
            v-if="actionError"
            class="mt-3 text-sm font-semibold text-sv-error-fg"
            role="alert"
            data-testid="flagged-action-error"
          >
            {{ actionError }}
          </p>
        </header>

        <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
          <section
            class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            data-testid="flagged-source"
            aria-labelledby="flag-source-heading"
          >
            <h2
              id="flag-source-heading"
              class="flex items-center gap-2 font-display text-lg font-bold text-sv-text-heading"
            >
              <SvIconLocked
                aria-hidden="true"
                class="h-4 w-4"
              />Source event — read-only
            </h2>
            <template v-if="store.current.audit_event">
              <div class="mt-3 flex flex-wrap items-center gap-2">
                <AuditSeverityBadge :severity="store.current.audit_event.severity" />
                <span class="break-all font-mono text-xs text-sv-text-muted">{{ store.current.audit_event.action }}</span>
              </div>
              <dl class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
                <div>
                  <dt class="text-xs font-semibold text-sv-text-muted">
                    Actor
                  </dt>
                  <dd class="mt-0.5 text-sv-text">
                    {{ store.current.audit_event.actor ?? 'System' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-semibold text-sv-text-muted">
                    Subject
                  </dt>
                  <dd class="mt-0.5 text-sv-text">
                    {{ humanizeSubject(store.current.audit_event.subject_type) ?? 'Not recorded' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs font-semibold text-sv-text-muted">
                    Occurred
                  </dt>
                  <dd class="mt-0.5 text-sv-text">
                    <SvDateTime :value="store.current.audit_event.occurred_at" />
                  </dd>
                </div>
              </dl>
              <RouterLink
                :to="{ name: 'audit.audit-event-detail', params: { eventUlid: store.current.audit_event.id } }"
                class="sv-focus-ring mt-4 inline-flex min-h-sv-touch items-center rounded-control border border-sv-border px-4 text-sm font-semibold text-sv-text"
              >
                Open full evidence
              </RouterLink>
            </template>
            <p
              v-else
              class="mt-2 text-sm text-sv-text-secondary"
            >
              The source event is not available in your branch scope.
            </p>
          </section>

          <section
            class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            aria-labelledby="flag-notes-heading"
          >
            <h2
              id="flag-notes-heading"
              class="font-display text-lg font-bold text-sv-text-heading"
            >
              Review notes
            </h2>
            <p
              v-if="store.current.review_notes"
              class="mt-2 whitespace-pre-line break-words text-sm text-sv-text"
            >
              {{ store.current.review_notes }}
            </p>
            <p
              v-else
              class="mt-2 text-sm text-sv-text-secondary"
            >
              No note recorded yet. A note is required to resolve or dismiss.
            </p>
            <p class="mt-4 text-xs text-sv-text-muted">
              Reviews change only this flag. Every change is recorded in the audit log.
            </p>
          </section>
        </div>
      </div>
    </SvStateBoundary>

    <SvDialog
      :open="confirming !== null"
      :title="confirming === 'dismiss' ? 'Dismiss this flag?' : 'Resolve this flag?'"
      description="This records a review outcome on the flag. The source event is not changed. A review note is required."
      @close="confirming = null"
    >
      <div class="mt-2">
        <SvTextArea
          id="flagged-review-notes"
          v-model="notes"
          label="Review notes"
          :rows="4"
          :maxlength="2000"
          :errors="noteErrors"
        />
      </div>
      <template #footer>
        <div class="flex flex-col-reverse gap-2 md:flex-row md:justify-end">
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
            {{ confirming === 'dismiss' ? 'Dismiss flag' : 'Resolve flag' }}
          </SvButton>
        </div>
      </template>
    </SvDialog>
  </section>
</template>

<style scoped>
.sv-review-hero {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-primary) 16%, transparent), transparent 44%),
    var(--sv-color-surface-raised);
  border-top: 4px solid color-mix(in srgb, var(--sv-color-brand-primary) 70%, var(--sv-color-border-default));
}
</style>
