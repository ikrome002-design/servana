<script setup lang="ts">
/**
 * Audit export record — supporting page of Audit Exports and Export History (Plan §13.5, §80;
 * ADR-010; Phase 19; Phase UI-15).
 *
 * Polls while the export is queued or processing; offers a private download only while it is
 * ready (a short-lived authorized link requested at the moment of use, opened once, never stored
 * or rendered) and an authorized revoke. Download counting happens on the server's authorized
 * stream and is re-read afterwards. No file id, storage path, signature or raw failure detail is
 * ever shown.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import SvBreadcrumbs from '@/components/ui/SvBreadcrumbs.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { SvIconDownload, SvIconLocked } from '@/design-system/icons';
import { useAuditExportStore, isTerminal } from '@/stores/auditExportStore';
import { exportPresentation } from '@/utils/auditPresentation';

const route = useRoute();
const store = useAuditExportStore();

const busy = ref(false);
const actionError = ref<string | null>(null);
const notice = ref<string | null>(null);
const confirmingRevoke = ref(false);
let poll: ReturnType<typeof setInterval> | null = null;

const exportId = computed(() => String(route.params.exportUlid));

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && !store.current) return 'loading';
  if (store.error && !store.current) return 'error';
  if (!store.current) return 'empty';
  return 'success';
});

const exp = computed(() => store.current);
const presentation = computed(() => exportPresentation(exp.value?.status));
const canDownload = computed(() => Boolean(exp.value?.can?.download) && exp.value?.status === 'ready');
const canRevoke = computed(() => Boolean(exp.value?.can?.revoke) && exp.value?.status === 'ready');

const STEPS = [
  { key: 'queued', label: 'Requested' },
  { key: 'processing', label: 'Generating' },
  { key: 'ready', label: 'Ready' },
  { key: 'closed', label: 'Expired or revoked' },
] as const;

const stepIndex = computed(() => {
  switch (exp.value?.status) {
    case 'queued': return 0;
    case 'processing': return 1;
    case 'ready': return 2;
    case 'expired':
    case 'revoked': return 3;
    default: return -1;
  }
});

const crumbs = computed(() => [
  { label: 'Export History', to: { name: 'audit.exports-history' } },
  { label: 'Export record' },
]);

function stopPolling(): void {
  if (poll !== null) {
    clearInterval(poll);
    poll = null;
  }
}

function maybePoll(): void {
  stopPolling();
  if (exp.value && !isTerminal(exp.value.status)) {
    poll = setInterval(() => {
      if (exp.value && isTerminal(exp.value.status)) {
        stopPolling();
        return;
      }
      void store.fetchExport(exportId.value).then(maybePoll);
    }, 4000);
  }
}

async function download(): Promise<void> {
  actionError.value = null;
  notice.value = null;
  try {
    const url = await store.downloadLink(exportId.value);
    window.open(url, '_blank', 'noopener');
    notice.value = 'Download started in a new tab. This download is counted and audited.';
    await store.fetchExport(exportId.value);
  } catch {
    actionError.value = 'This export is not available for download — it may have expired or been revoked.';
    await store.fetchExport(exportId.value);
  }
}

async function revoke(): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    await store.revoke(exportId.value);
    confirmingRevoke.value = false;
    notice.value = 'Export revoked. The file can no longer be downloaded.';
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The export could not be revoked.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  store.current = null;
  void store.fetchExport(exportId.value).then(maybePoll);
});

onUnmounted(stopPolling);
</script>

<template>
  <section
    class="mx-auto max-w-5xl"
    data-testid="audit-export-detail"
  >
    <SvBreadcrumbs
      :items="crumbs"
      class="mb-3"
    />

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load this export."
      empty-message="This export is not available in your branch scope."
      @retry="() => store.fetchExport(exportId)"
    >
      <div
        v-if="exp"
        class="flex flex-col gap-5"
      >
        <header class="sv-export-hero relative isolate overflow-hidden rounded-card border border-sv-border p-5 shadow-raised md:p-7">
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
            Audit export
          </p>
          <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <h1 class="font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl">
              {{ exp.branch?.name ?? 'Branch export' }}
            </h1>
            <span
              data-testid="audit-export-detail-status"
              :data-status="exp.status"
            >
              <SvStatusBadge
                :label="presentation.label"
                :tone="presentation.tone"
                sr-prefix="Export status:"
              />
            </span>
          </div>
          <p
            class="mt-2 text-sm text-sv-text-secondary"
            role="status"
            aria-live="polite"
            data-testid="audit-export-status-message"
          >
            {{ presentation.description }}
          </p>

          <ol
            class="mt-5 grid grid-cols-2 gap-2 md:grid-cols-4"
            aria-label="Export lifecycle"
          >
            <li
              v-for="(step, index) in STEPS"
              :key="step.key"
              class="sv-step rounded-control border px-3 py-2 text-sm"
              :data-state="index < stepIndex ? 'done' : index === stepIndex ? 'current' : 'pending'"
              :aria-current="index === stepIndex ? 'step' : undefined"
            >
              <span class="block text-xs font-semibold text-sv-text-muted">Step {{ index + 1 }}</span>
              <span class="font-semibold text-sv-text-heading">{{ step.label }}</span>
            </li>
          </ol>

          <div class="mt-5 flex flex-wrap gap-2">
            <SvButton
              v-if="canDownload"
              data-testid="audit-export-download"
              @click="download"
            >
              <template #icon-leading>
                <SvIconDownload
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </template>
              Download private file
            </SvButton>
            <SvButton
              v-if="canRevoke"
              variant="secondary"
              data-testid="audit-export-revoke"
              @click="confirmingRevoke = true"
            >
              Revoke
            </SvButton>
            <RouterLink
              v-if="exp.status === 'failed' || exp.status === 'expired' || exp.status === 'revoked'"
              :to="{ name: 'audit.exports' }"
              class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-sv-border px-4 text-sm font-semibold text-sv-text"
            >
              Request a new export
            </RouterLink>
          </div>
          <p
            v-if="notice"
            class="mt-3 text-sm font-semibold text-sv-success-fg"
            role="status"
          >
            {{ notice }}
          </p>
          <p
            v-if="actionError"
            class="mt-3 text-sm font-semibold text-sv-error-fg"
            role="alert"
          >
            {{ actionError }}
          </p>
        </header>

        <section
          class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
          aria-labelledby="audit-export-facts-heading"
        >
          <h2
            id="audit-export-facts-heading"
            class="font-display text-lg font-bold text-sv-text-heading"
          >
            Request and file
          </h2>
          <dl class="mt-3 grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
            <div class="md:col-span-3">
              <dt class="text-xs font-semibold text-sv-text-muted">
                Reason
              </dt>
              <dd class="mt-0.5 break-words text-sv-text">
                {{ exp.reason }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Event groups
              </dt>
              <dd class="mt-0.5 text-sv-text">
                {{ exp.scope.domains.length > 0 ? exp.scope.domains.join(', ') : 'general' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Severities
              </dt>
              <dd class="mt-0.5 text-sv-text">
                {{ exp.scope.severities.length > 0 ? exp.scope.severities.join(', ') : 'Every severity' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Date window
              </dt>
              <dd class="mt-0.5 text-sv-text">
                {{ exp.scope.has_date_from || exp.scope.has_date_to ? 'Limited by date' : 'All recorded history' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Requested
              </dt>
              <dd class="mt-0.5 text-sv-text">
                <SvDateTime :value="exp.requested_at ?? exp.created_at" />
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Generated
              </dt>
              <dd class="mt-0.5 text-sv-text">
                <SvDateTime
                  :value="exp.generated_at"
                  unavailable-label="Not yet"
                />
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Expires
              </dt>
              <dd class="mt-0.5 text-sv-text">
                <SvDateTime
                  :value="exp.expires_at"
                  unavailable-label="Set when ready"
                />
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Rows
              </dt>
              <dd class="mt-0.5 font-display text-lg font-bold text-sv-text-heading">
                {{ exp.row_count ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Downloads
              </dt>
              <dd
                class="mt-0.5 font-display text-lg font-bold text-sv-text-heading"
                data-testid="audit-export-download-count"
              >
                {{ exp.download_count }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold text-sv-text-muted">
                Last downloaded
              </dt>
              <dd class="mt-0.5 text-sv-text">
                <SvDateTime
                  :value="exp.last_downloaded_at"
                  unavailable-label="Never"
                />
              </dd>
            </div>
          </dl>
          <p
            v-if="exp.status === 'failed' && exp.failure_message"
            class="mt-4 rounded-control border border-sv-error-border bg-sv-error-bg p-3 text-sm text-sv-error-fg"
          >
            {{ exp.failure_message }}
          </p>
          <p class="mt-4 flex items-start gap-2 text-xs text-sv-text-muted">
            <SvIconLocked
              aria-hidden="true"
              class="mt-0.5 h-3.5 w-3.5 shrink-0"
            />The file is private. A download link is issued only at the moment you download and is never shown or saved here.
          </p>
        </section>
      </div>
    </SvStateBoundary>

    <SvDialog
      :open="confirmingRevoke"
      title="Revoke this export?"
      description="Revoking permanently prevents any further download of this export. The request and its history stay on record."
      @close="confirmingRevoke = false"
    >
      <template #footer>
        <div class="flex flex-col-reverse gap-2 md:flex-row md:justify-end">
          <SvButton
            variant="ghost"
            @click="confirmingRevoke = false"
          >
            Cancel
          </SvButton>
          <SvButton
            variant="destructive"
            data-testid="audit-export-revoke-confirm"
            :loading="busy"
            @click="revoke"
          >
            Revoke export
          </SvButton>
        </div>
      </template>
    </SvDialog>
  </section>
</template>

<style scoped>
.sv-export-hero {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-secondary) 18%, transparent), transparent 45%),
    var(--sv-color-surface-raised);
  border-top: 4px solid color-mix(in srgb, var(--sv-color-brand-secondary) 70%, var(--sv-color-border-default));
}
.sv-step {
  border-color: var(--sv-color-border-default);
  background: var(--sv-color-surface-raised);
}
.sv-step[data-state='done'] {
  border-color: color-mix(in srgb, var(--sv-color-growth) 55%, var(--sv-color-border-default));
  background: color-mix(in srgb, var(--sv-color-growth) 10%, var(--sv-color-surface-raised));
}
.sv-step[data-state='current'] {
  border-color: var(--sv-color-brand-secondary);
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 14%, var(--sv-color-surface-raised));
  box-shadow: inset 0 0 0 1px var(--sv-color-brand-secondary);
}
</style>
