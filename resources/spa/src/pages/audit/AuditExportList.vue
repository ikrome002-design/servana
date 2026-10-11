<script setup lang="ts">
/**
 * Audit Exports — Appendix A §12.4.11 (Phase 19 lifecycle; Phase UI-15 experience).
 *
 * Request a branch-scoped, reason-required, masked audit export through the existing Phase 19 API
 * (`audit.export` + a fresh MFA step-up, both enforced by the server). The form previews exactly
 * which columns are included, how each protected value is masked and what is omitted before the
 * user commits. The record estimate is a live server count for the same filters, labelled as an
 * estimate. No signed URL, file id or storage path ever reaches this page; downloads happen from
 * the export record, on demand.
 *
 * Generation, expiry, download counting and revocation are server-owned. There is no cancel or
 * retry endpoint, so neither is offered — a failed export is replaced by a new request.
 */
import axios from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import AuditExportStatusCard from '@/components/audit/AuditExportStatusCard.vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCheckbox from '@/components/ui/SvCheckbox.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconForbidden, SvIconLocked, SvIconMasked, SvIconSecurity } from '@/design-system/icons';
import { useAccountContextStore } from '@/stores/accountContextStore';
import { fetchAuditEventPage, type AuditDomain } from '@/stores/auditEventStore';
import { AUDIT_EXPORT_SEVERITIES, useAuditExportStore, type AuditExportView } from '@/stores/auditExportStore';
import { useAuthStore } from '@/stores/authStore';
import { usePermissionStore } from '@/stores/permissionStore';
import { shortReference } from '@/utils/auditPresentation';

const store = useAuditExportStore();
const permissions = usePermissionStore();
const auth = useAuthStore();
const contexts = useAccountContextStore();
const router = useRouter();

const REASON_MIN = 3;
const REASON_MAX = 500;

const canExport = computed(() => permissions.can('audit.export'));
const noBranch = computed(() => auth.branchIds.length === 0);

const domainOptions = computed(() => ([
  { value: 'general' as AuditDomain, label: 'Branch events', hint: 'Operations, access, contact protection and review activity', permitted: permissions.can('audit.branch_events.view') },
  { value: 'finance' as AuditDomain, label: 'Finance', hint: 'Invoices, payments, receipts, refunds, cash-up, period locks', permitted: permissions.can('audit.finance.view') },
  { value: 'compensation' as AuditDomain, label: 'Compensation', hint: 'Plans, ledgers, payout runs and earnings queries', permitted: permissions.can('audit.compensation.view') },
]).filter((option) => option.permitted));

const branchOptions = computed(() => {
  const name = contexts.currentContext?.branch_name ?? null;
  return auth.branchIds.map((id, index) => ({
    value: id,
    label: auth.branchIds.length === 1 && name ? name : `Assigned branch ${index + 1} (${shortReference(id)})`,
  }));
});

const form = reactive<{ branch: string; reason: string; date_from: string; date_to: string; domains: string[]; severities: string[] }>({
  branch: '',
  reason: '',
  date_from: '',
  date_to: '',
  domains: [],
  severities: [],
});
const busy = ref(false);
const submitError = ref<string | null>(null);
const stepUpRequired = ref(false);
const created = ref<AuditExportView | null>(null);
const estimate = ref<{ state: 'idle' | 'loading' | 'ready' | 'unavailable' | 'failed'; total?: number; note?: string }>({ state: 'idle' });
const touched = ref(false);

const reasonErrors = computed(() => {
  if (!touched.value) return [];
  const length = form.reason.trim().length;
  if (length < REASON_MIN) return ['Give a reason of at least three characters — it is recorded with the export.'];
  if (length > REASON_MAX) return [`Keep the reason under ${REASON_MAX} characters.`];
  return [];
});
const dateErrors = computed(() => (form.date_from && form.date_to && form.date_to < form.date_from ? ['The end date must be on or after the start date.'] : []));
const branchErrors = computed(() => (touched.value && form.branch === '' ? ['Choose your assigned branch.'] : []));
const canSubmit = computed(() => canExport.value && !noBranch.value && form.branch !== '' && form.reason.trim().length >= REASON_MIN
  && form.reason.trim().length <= REASON_MAX && dateErrors.value.length === 0);
const selectedDomains = computed<AuditDomain[]>(() => (form.domains.length > 0 ? form.domains as AuditDomain[] : ['general']));

const recent = computed(() => store.exports.slice(0, 4));
const listState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && store.exports.length === 0) return 'loading';
  if (store.error) return 'error';
  if (store.exports.length === 0) return 'empty';
  return 'success';
});

function toggle(list: string[], value: string, on: boolean): string[] {
  return on ? [...new Set([...list, value])] : list.filter((item) => item !== value);
}

async function runEstimate(): Promise<void> {
  if (form.severities.length > 1) {
    estimate.value = { state: 'unavailable', note: 'An estimate is only available for one severity or for all severities.' };
    return;
  }
  estimate.value = { state: 'loading' };
  try {
    const filters = { date_from: form.date_from, date_to: form.date_to, severity: form.severities[0] ?? '' };
    const pages = await Promise.all(selectedDomains.value.map((domain) => fetchAuditEventPage(domain, filters, 1)));
    estimate.value = { state: 'ready', total: pages.reduce((sum, page) => sum + page.meta.total, 0) };
  } catch {
    estimate.value = { state: 'failed', note: 'The estimate could not be calculated. The export can still be requested.' };
  }
}

async function submit(): Promise<void> {
  touched.value = true;
  if (!canSubmit.value) return;
  busy.value = true;
  submitError.value = null;
  stepUpRequired.value = false;
  try {
    created.value = await store.request({
      branch: form.branch,
      reason: form.reason.trim(),
      ...(form.date_from ? { date_from: form.date_from } : {}),
      ...(form.date_to ? { date_to: form.date_to } : {}),
      ...(form.domains.length ? { domains: form.domains } : {}),
      ...(form.severities.length ? { severities: form.severities } : {}),
    });
    form.reason = '';
    touched.value = false;
    await store.fetchExports();
  } catch (err: unknown) {
    const apiError = axios.isAxiosError(err) ? (err as { apiError?: { code?: string; message?: string } }).apiError : undefined;
    if (apiError?.code === 'step_up_required' || apiError?.code === 'mfa_challenge_required' || apiError?.code === 'privileged_mfa_required') {
      stepUpRequired.value = true;
    } else {
      submitError.value = apiError?.message ?? 'The export could not be requested. Please try again.';
    }
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  if (auth.branchIds.length === 1) form.branch = auth.branchIds[0];
  if (!contexts.loaded) void contexts.fetchContexts();
  if (canExport.value) void store.fetchExports();
});
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="audit-exports-page"
  >
    <SvTonalPageHeader
      eyebrow="Export"
      title="Audit Exports"
      description="Request a masked, branch-scoped copy of the audit record. Every request needs a reason and a fresh security check, and every download is counted."
      context="Assigned branch · private files"
      tone="teal"
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'audit.exports-history' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-sv-border bg-sv-surface-raised px-4 text-sm font-semibold text-sv-text"
        >
          Export history
        </RouterLink>
      </template>
    </SvTonalPageHeader>

    <SvAlert
      v-if="!canExport"
      severity="info"
      title="Exports are not enabled for your account"
      data-testid="audit-export-denied"
    >
      Your account can review audit events but has not been granted audit export. Ask your merchant administrator if you need it.
    </SvAlert>

    <template v-else>
      <p
        v-if="noBranch"
        class="mb-4 rounded-control border border-sv-warning-border bg-sv-warning-bg p-3 text-sm font-medium text-sv-warning-fg"
        data-testid="audit-export-no-branch"
      >
        You have no assigned branch, so no export can be requested.
      </p>

      <SvAlert
        v-if="created"
        class="mb-4"
        severity="success"
        title="Export requested"
        dismissible
        data-testid="audit-export-created"
        @dismiss="created = null"
      >
        Generation runs in the background.
        <RouterLink
          :to="{ name: 'audit.export-detail', params: { exportUlid: created.id } }"
          class="sv-focus-ring font-semibold text-sv-link underline"
        >
          Follow its progress
        </RouterLink>
      </SvAlert>

      <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
        <form
          class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card md:p-6"
          aria-labelledby="audit-export-form-heading"
          novalidate
          @submit.prevent="submit"
        >
          <h2
            id="audit-export-form-heading"
            class="font-display text-lg font-bold text-sv-text-heading"
          >
            New export request
          </h2>
          <div class="mt-4 flex flex-col gap-4">
            <SvSelect
              id="audit-export-branch"
              v-model="form.branch"
              label="Branch"
              :options="branchOptions"
              placeholder="Select your assigned branch"
              :errors="branchErrors"
              :disabled="noBranch"
              required
            />
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <SvTextInput
                id="audit-export-from"
                v-model="form.date_from"
                label="From date (optional)"
                type="date"
              />
              <SvTextInput
                id="audit-export-to"
                v-model="form.date_to"
                label="To date (optional)"
                type="date"
                :errors="dateErrors"
              />
            </div>
            <fieldset>
              <legend class="text-sm font-semibold text-sv-text-heading">
                Event groups
              </legend>
              <p class="text-xs text-sv-text-muted">
                Leave all unticked to export branch events only.
              </p>
              <div class="mt-2 grid grid-cols-1 gap-2">
                <div
                  v-for="option in domainOptions"
                  :key="option.value"
                  class="rounded-control border border-sv-border p-3"
                >
                  <SvCheckbox
                    :id="`audit-export-domain-${option.value}`"
                    :model-value="form.domains.includes(option.value)"
                    :label="option.label"
                    :help="option.hint"
                    @update:model-value="(on) => { form.domains = toggle(form.domains, option.value, on); estimate = { state: 'idle' }; }"
                  />
                </div>
              </div>
            </fieldset>
            <fieldset>
              <legend class="text-sm font-semibold text-sv-text-heading">
                Severities (optional)
              </legend>
              <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1">
                <SvCheckbox
                  v-for="severity in AUDIT_EXPORT_SEVERITIES"
                  :id="`audit-export-severity-${severity.value}`"
                  :key="severity.value"
                  :model-value="form.severities.includes(severity.value)"
                  :label="severity.label"
                  @update:model-value="(on) => { form.severities = toggle(form.severities, severity.value, on); estimate = { state: 'idle' }; }"
                />
              </div>
            </fieldset>
            <SvTextArea
              id="audit-export-reason"
              v-model="form.reason"
              label="Reason for this export"
              :rows="3"
              :maxlength="REASON_MAX"
              :errors="reasonErrors"
              :help="`Required. Recorded with the export and visible in export history. ${form.reason.trim().length}/${REASON_MAX}`"
              required
              @blur="touched = true"
            />

            <div class="flex flex-col gap-2 rounded-control border border-sv-border bg-sv-surface-subtle p-3 md:flex-row md:items-center md:justify-between">
              <p
                class="text-sm text-sv-text-secondary"
                role="status"
                aria-live="polite"
                data-testid="audit-export-estimate"
              >
                <template v-if="estimate.state === 'ready'">
                  About <strong class="text-sv-text-heading">{{ estimate.total?.toLocaleString('en-KE') }}</strong> matching events right now.
                </template>
                <template v-else-if="estimate.state === 'loading'">
                  Counting matching events…
                </template>
                <template v-else-if="estimate.note">
                  {{ estimate.note }}
                </template>
                <template v-else>
                  Check how many events match before you request.
                </template>
              </p>
              <SvButton
                variant="secondary"
                size="sm"
                type="button"
                :loading="estimate.state === 'loading'"
                @click="runEstimate"
              >
                Estimate size
              </SvButton>
            </div>

            <SvAlert
              v-if="stepUpRequired"
              severity="warning"
              title="Confirm it’s you"
              data-testid="audit-export-step-up"
            >
              Exports need a recent authenticator check. Verify, then return to Audit Exports to send the request.
              <template #actions>
                <SvButton
                  size="sm"
                  type="button"
                  @click="router.push({ name: 'auth.mfa.challenge' })"
                >
                  Verify now
                </SvButton>
              </template>
            </SvAlert>
            <p
              v-if="submitError"
              class="text-sm font-semibold text-sv-error-fg"
              role="alert"
            >
              {{ submitError }}
            </p>

            <SvButton
              type="submit"
              data-testid="audit-export-open"
              :disabled="noBranch"
              :loading="busy"
              loading-label="Requesting export"
            >
              Request masked export
            </SvButton>
          </div>
        </form>

        <aside
          class="flex flex-col gap-4"
          aria-label="What the export will contain"
          data-testid="audit-export-preview"
        >
          <section class="sv-preview rounded-card border p-5">
            <h2 class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
              <SvIconSecurity
                aria-hidden="true"
                class="h-4 w-4"
              />Included
            </h2>
            <p class="mt-1 text-sm text-sv-text-secondary">
              {{ selectedDomains.map((d) => domainOptions.find((o) => o.value === d)?.label ?? d).join(', ') }}
              · {{ form.date_from || 'earliest' }} to {{ form.date_to || 'latest' }}
              · {{ form.severities.length === 0 ? 'every severity' : form.severities.join(', ') }}
            </p>
            <ul class="mt-3 flex flex-wrap gap-1.5">
              <li
                v-for="column in ['Reference', 'Action', 'Severity', 'Actor', 'Branch', 'Subject type', 'Correlation reference', 'Recorded at', 'Context']"
                :key="column"
                class="rounded-pill border border-sv-border bg-sv-surface-raised px-2.5 py-0.5 text-xs font-semibold text-sv-text"
              >
                {{ column }}
              </li>
            </ul>
          </section>
          <section class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
            <h2 class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
              <SvIconLocked
                aria-hidden="true"
                class="h-4 w-4"
              />Masked in the file
            </h2>
            <dl class="mt-3 grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-2 text-sm">
              <dt class="text-sv-text-secondary">
                Email addresses
              </dt><dd class="font-mono text-sv-text">
                j***@e***.com
              </dd>
              <dt class="text-sv-text-secondary">
                Phone numbers
              </dt><dd class="font-mono text-sv-text">
                ***123
              </dd>
              <dt class="text-sv-text-secondary">
                Payment references
              </dt><dd class="font-mono text-sv-text">
                ***7Q2X
              </dd>
              <dt class="text-sv-text-secondary">
                Salary and pay values
              </dt><dd class="font-mono text-sv-text">
                [restricted]
              </dd>
              <dt class="text-sv-text-secondary">
                Tokens and secrets
              </dt><dd class="font-mono text-sv-text">
                [redacted]
              </dd>
            </dl>
            <p class="mt-3 text-xs text-sv-text-muted">
              Illustrative formats — the server applies the same masking it uses on screen.
            </p>
          </section>
          <section class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
            <h2 class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
              <SvIconMasked
                aria-hidden="true"
                class="h-4 w-4"
              />Never included
            </h2>
            <ul class="mt-2 flex flex-col gap-1.5 text-sm text-sv-text-secondary">
              <li>Events from other branches or merchant-wide records</li>
              <li>Hash-chain values, IP addresses and internal identifiers</li>
              <li>Client contact lists or message bodies</li>
            </ul>
          </section>
          <section class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
            <h2 class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
              <SvIconForbidden
                aria-hidden="true"
                class="h-4 w-4"
              />After you request
            </h2>
            <ol class="mt-2 flex flex-col gap-1.5 text-sm text-sv-text-secondary">
              <li>Queued, then processed in the background.</li>
              <li>Ready as a private file with an expiry time.</li>
              <li>Each download is counted and audited; it can be revoked.</li>
            </ol>
          </section>
        </aside>
      </div>

      <section
        class="mt-6"
        aria-labelledby="audit-export-recent-heading"
      >
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
          <h2
            id="audit-export-recent-heading"
            class="font-display text-lg font-bold text-sv-text-heading"
          >
            Recent requests
          </h2>
          <RouterLink
            :to="{ name: 'audit.exports-history' }"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline-offset-4 hover:underline"
          >
            See all export history
          </RouterLink>
        </div>
        <SvStateBoundary
          :state="listState"
          error-message="We couldn’t load your recent exports."
          empty-message="No audit exports have been requested for your branch yet."
          @retry="() => store.fetchExports()"
        >
          <ul class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <li
              v-for="exp in recent"
              :key="exp.id"
            >
              <AuditExportStatusCard :export-record="exp" />
            </li>
          </ul>
        </SvStateBoundary>
      </section>
    </template>
  </section>
</template>

<style scoped>
.sv-preview {
  border-color: color-mix(in srgb, var(--sv-color-brand-secondary) 40%, var(--sv-color-border-default));
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-secondary) 16%, transparent), transparent 50%),
    var(--sv-color-surface-raised);
}
</style>
