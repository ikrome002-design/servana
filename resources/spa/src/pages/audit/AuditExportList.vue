<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuditExportStore, AUDIT_EXPORT_DOMAINS, AUDIT_EXPORT_SEVERITIES } from '@/stores/auditExportStore';
import { usePermissionStore } from '@/stores/permissionStore';
import { useAuthStore } from '@/stores/authStore';

/**
 * Audit export list + request (Plan §13.5, §19.3, §80; ADR-010; Phase 19). Audit
 * requests a branch-scoped, reason-gated, masked export; a fresh MFA step-up is
 * server-enforced. Merchant-level exports are impossible (branch required). The
 * signed download link is requested on-demand and never stored. `file_id`, paths,
 * and signatures are never exposed.
 */
const store = useAuditExportStore();
const permissions = usePermissionStore();
const auth = useAuthStore();

const requesting = ref(false);
const busy = ref(false);
const actionError = ref<string | null>(null);
const form = ref<{ branch: string; reason: string; date_from: string; date_to: string; domains: string[]; severities: string[] }>({
  branch: '',
  reason: '',
  date_from: '',
  date_to: '',
  domains: [],
  severities: [],
});

const canExport = computed(() => permissions.can('audit.export'));
const branchOptions = computed(() => auth.branchIds.map((id) => ({ value: id, label: id })));

const statusOptions = [
  { value: '', label: 'All exports' },
  { value: 'queued', label: 'Queued' },
  { value: 'processing', label: 'Processing' },
  { value: 'ready', label: 'Ready' },
  { value: 'failed', label: 'Failed' },
  { value: 'expired', label: 'Expired' },
  { value: 'revoked', label: 'Revoked' },
];

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.exports.length === 0) return 'empty';
  return 'success';
});

const noBranch = computed(() => auth.branchIds.length === 0);

function openRequest(): void {
  actionError.value = null;
  form.value = {
    branch: auth.branchIds.length === 1 ? auth.branchIds[0] : '',
    reason: '',
    date_from: '',
    date_to: '',
    domains: [],
    severities: [],
  };
  requesting.value = true;
}

function toggle(list: string[], value: string): string[] {
  return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}

async function submitRequest(): Promise<void> {
  if (form.value.branch === '' || form.value.reason.trim().length < 3) return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.request({
      branch: form.value.branch,
      reason: form.value.reason.trim(),
      ...(form.value.date_from ? { date_from: form.value.date_from } : {}),
      ...(form.value.date_to ? { date_to: form.value.date_to } : {}),
      ...(form.value.domains.length ? { domains: form.value.domains } : {}),
      ...(form.value.severities.length ? { severities: form.value.severities } : {}),
    });
    requesting.value = false;
    await store.fetchExports();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The export could not be requested (a fresh step-up may be required).';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchExports();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-2xl font-bold text-heading">
          Audit exports
        </h1>
        <p class="mt-1 text-sm text-text-muted">
          Branch-scoped, masked, reason-gated exports. A fresh step-up is required.
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <SvSelect
          id="audit-export-status-filter"
          v-model="store.filterStatus"
          label="Filter"
          :options="statusOptions"
          class="w-full sm:w-56"
          @update:model-value="() => store.fetchExports()"
        />
        <SvButton
          v-if="canExport"
          data-testid="audit-export-open"
          :disabled="noBranch"
          @click="openRequest"
        >
          Request export
        </SvButton>
      </div>
    </div>

    <p
      v-if="noBranch"
      class="mt-3 text-sm text-text-muted"
      data-testid="audit-export-no-branch"
    >
      You have no assigned branch, so no export can be requested.
    </p>
    <p
      v-if="actionError"
      class="mt-3 text-sm text-error"
      role="alert"
    >
      {{ actionError }}
    </p>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load audit exports."
      empty-message="No audit exports match this filter."
      @retry="() => store.fetchExports()"
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="exp in store.exports"
          :key="exp.id"
        >
          <SvCard
            as="article"
            padding="md"
            data-testid="audit-export-row"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="min-w-0">
                <p class="font-display font-semibold text-heading">
                  {{ exp.branch?.name ?? 'Branch export' }}
                </p>
                <p class="text-sm text-text-muted">
                  <span data-testid="audit-export-status">{{ exp.status }}</span>
                  · {{ exp.row_count ?? 0 }} rows · {{ exp.download_count }} downloads
                </p>
                <p class="truncate text-xs text-text-muted">
                  {{ exp.reason }}
                </p>
              </div>
              <RouterLink
                :to="{ name: 'audit.export-detail', params: { id: exp.id } }"
                class="inline-flex min-h-[44px] items-center rounded-control px-3 text-sm font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                data-testid="audit-export-open-detail"
              >
                Open
              </RouterLink>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <SvModal
      :open="requesting"
      title="Request an audit export"
      description="The export is branch-scoped and masked. A fresh step-up is required. A reason is mandatory."
      @close="requesting = false"
    >
      <div class="mt-2 flex flex-col gap-3">
        <SvSelect
          id="audit-export-branch"
          v-model="form.branch"
          label="Branch"
          :options="branchOptions"
          placeholder="Select an assigned branch"
        />
        <SvTextarea
          id="audit-export-reason"
          v-model="form.reason"
          label="Reason"
          :rows="3"
        />
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <SvInput
            id="audit-export-from"
            v-model="form.date_from"
            label="From (optional)"
            type="date"
          />
          <SvInput
            id="audit-export-to"
            v-model="form.date_to"
            label="To (optional)"
            type="date"
          />
        </div>
        <fieldset>
          <legend class="text-sm font-medium text-text">
            Domains (optional)
          </legend>
          <div class="mt-1 flex flex-wrap gap-3">
            <label
              v-for="d in AUDIT_EXPORT_DOMAINS"
              :key="d.value"
              class="inline-flex min-h-[44px] items-center gap-2 text-sm"
            >
              <input
                type="checkbox"
                :checked="form.domains.includes(d.value)"
                class="h-4 w-4"
                @change="form.domains = toggle(form.domains, d.value)"
              >
              {{ d.label }}
            </label>
          </div>
        </fieldset>
        <fieldset>
          <legend class="text-sm font-medium text-text">
            Severities (optional)
          </legend>
          <div class="mt-1 flex flex-wrap gap-3">
            <label
              v-for="s in AUDIT_EXPORT_SEVERITIES"
              :key="s.value"
              class="inline-flex min-h-[44px] items-center gap-2 text-sm"
            >
              <input
                type="checkbox"
                :checked="form.severities.includes(s.value)"
                class="h-4 w-4"
                @change="form.severities = toggle(form.severities, s.value)"
              >
              {{ s.label }}
            </label>
          </div>
        </fieldset>
      </div>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="requesting = false"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="audit-export-confirm"
          :loading="busy"
          :disabled="form.branch === '' || form.reason.trim().length < 3"
          @click="submitRequest"
        >
          Request export
        </SvButton>
      </div>
    </SvModal>
  </section>
</template>
