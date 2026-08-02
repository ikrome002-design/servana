<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuditExportStore, isTerminal } from '@/stores/auditExportStore';
import { SvIconBack } from '@/design-system/icons';

/**
 * Audit export detail (Plan §13.5, §80; ADR-010; Phase 19). Polls while the export
 * is queued/processing; offers a private download (short-lived signed link,
 * requested on demand, never stored) and an authorized revoke. Download accounting
 * changes server-side on the authorized stream, reflected after refresh. No
 * `file_id`, path, signature, or raw failure detail is ever shown.
 */
const route = useRoute();
const store = useAuditExportStore();

const busy = ref(false);
const actionError = ref<string | null>(null);
const confirmingRevoke = ref(false);
let poll: ReturnType<typeof setInterval> | null = null;

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && !store.current) return 'loading';
  if (store.error && !store.current) return 'error';
  if (!store.current) return 'empty';
  return 'success';
});

const exp = computed(() => store.current);
const canDownload = computed(() => Boolean(exp.value?.can?.download) && exp.value?.status === 'ready');
const canRevoke = computed(() => Boolean(exp.value?.can?.revoke) && exp.value?.status === 'ready');

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
      void store.fetchExport(String(route.params.id)).then(maybePoll);
    }, 4000);
  }
}

async function download(): Promise<void> {
  actionError.value = null;
  try {
    const url = await store.downloadLink(String(route.params.id));
    window.open(url, '_blank', 'noopener');
    // Download accounting changes on the authorized stream — refresh to reflect it.
    await store.fetchExport(String(route.params.id));
  } catch {
    actionError.value = 'This export is not available for download (it may be expired or revoked).';
  }
}

async function revoke(): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    await store.revoke(String(route.params.id));
    confirmingRevoke.value = false;
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The export could not be revoked.';
  } finally {
    busy.value = false;
  }
}

const statusMessage = computed(() => {
  switch (exp.value?.status) {
    case 'queued':
      return 'Queued — generation will begin shortly.';
    case 'processing':
      return 'Processing — this export is being generated.';
    case 'ready':
      return 'Ready to download.';
    case 'failed':
      return 'Generation failed. You can request a new export.';
    case 'expired':
      return 'This export has expired and can no longer be downloaded.';
    case 'revoked':
      return 'This export was revoked and can no longer be downloaded.';
    default:
      return '';
  }
});

onMounted(() => {
  void store.fetchExport(String(route.params.id)).then(maybePoll);
});

onUnmounted(stopPolling);
</script>

<template>
  <section class="p-4 md:p-6">
    <RouterLink
      :to="{ name: 'audit.exports' }"
      class="inline-flex min-h-[44px] items-center text-sm font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      <SvIconBack
        aria-hidden="true"
        class="mr-1 inline-block h-4 w-4 align-text-bottom"
      />Back to audit exports
    </RouterLink>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      error-message="We couldn’t load this export."
      empty-message="This export was not found."
      @retry="() => store.fetchExport(String(route.params.id))"
    >
      <div
        v-if="exp"
        class="flex flex-col gap-4"
      >
        <header class="flex flex-wrap items-center justify-between gap-2">
          <h1 class="font-display text-2xl font-bold text-heading">
            Audit export
          </h1>
          <span
            class="inline-flex items-center rounded-control bg-surface-alt px-3 py-1 text-sm font-medium"
            data-testid="audit-export-detail-status"
          >
            {{ exp.status }}
          </span>
        </header>

        <p
          class="text-sm text-text-muted"
          role="status"
          aria-live="polite"
          data-testid="audit-export-status-message"
        >
          {{ statusMessage }}
        </p>
        <p
          v-if="actionError"
          class="text-sm text-error"
          role="alert"
        >
          {{ actionError }}
        </p>

        <SvCard
          as="section"
          padding="md"
        >
          <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Branch
              </dt>
              <dd class="text-sm text-text">
                {{ exp.branch?.name ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Reason
              </dt>
              <dd class="text-sm text-text">
                {{ exp.reason }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Rows
              </dt>
              <dd class="text-sm text-text">
                {{ exp.row_count ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Downloads
              </dt>
              <dd
                class="text-sm text-text"
                data-testid="audit-export-download-count"
              >
                {{ exp.download_count }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Generated
              </dt>
              <dd class="text-sm text-text">
                {{ exp.generated_at ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Expires
              </dt>
              <dd class="text-sm text-text">
                {{ exp.expires_at ?? '—' }}
              </dd>
            </div>
          </dl>
          <p
            v-if="exp.status === 'failed' && exp.failure_message"
            class="mt-3 text-sm text-error"
          >
            {{ exp.failure_message }}
          </p>

          <div class="mt-4 flex flex-wrap gap-2">
            <SvButton
              v-if="canDownload"
              data-testid="audit-export-download"
              @click="download"
            >
              Download
            </SvButton>
            <SvButton
              v-if="canRevoke"
              variant="secondary"
              data-testid="audit-export-revoke"
              @click="confirmingRevoke = true"
            >
              Revoke
            </SvButton>
          </div>
        </SvCard>
      </div>
    </SvStateBoundary>

    <SvDialog
      :open="confirmingRevoke"
      title="Revoke this export?"
      description="Revoking permanently prevents any further download of this export."
      @close="confirmingRevoke = false"
    >
      <div class="mt-4 flex justify-end gap-2">
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
    </SvDialog>
  </section>
</template>
