<script setup lang="ts">
/**
 * Integrity Status — Appendix A §12.4.13 (Phase UI-15; Plan §70, ADR-008).
 *
 * Shows the result of the server's bounded, read-only segment verification of the merchant audit
 * chain (`GET /audit-integrity`). The wording is held to what was actually proved:
 *   - `verified` only when the whole chain was checked from its first record;
 *   - `partial` when the latest records verified but earlier history was outside this check;
 *   - `broken` with the failure category, and the event named only if it is in your branch;
 *   - unknown/failed is never shown as healthy.
 * The daily full-chain run persists no result, so it is described, not displayed. Repair belongs
 * to platform engineering; this page can only open evidence and raise a review flag.
 */
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvErrorState from '@/components/ui/SvErrorState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconChainLink, SvIconCritical, SvIconFingerprint, SvIconForbidden, SvIconRefresh } from '@/design-system/icons';
import { useAuditWorkspaceStore } from '@/stores/auditWorkspaceStore';
import { integrityPresentation } from '@/utils/auditPresentation';

const store = useAuditWorkspaceStore();

const result = computed(() => store.integrity);
const presentation = computed(() => integrityPresentation(result.value?.status));

const explanation = computed(() => {
  switch (result.value?.status) {
    case 'verified':
      return 'The server recomputed every record of your business’s audit chain from the first one, and each link and hash matched.';
    case 'partial':
      return `The server recomputed the latest ${result.value.segment_limit.toLocaleString('en-KE')} records of the chain and every link and hash matched. Older records were not part of this check, so it is not a full verification.`;
    case 'broken':
      return 'The server found a record that does not match the chain. Nothing has been changed or repaired; the finding is reported to you as it was found.';
    case 'empty':
      return 'No audit records exist yet, so there is nothing to verify.';
    default:
      return 'No integrity result is available.';
  }
});

const breakCopy = computed(() => {
  const category = result.value?.first_break?.category;
  if (category === 'broken_link') return 'A record does not link to the record before it — a record may be missing, reordered or inserted.';
  if (category === 'hash_mismatch') return 'A record’s contents no longer match the hash recorded when it was written.';
  return 'The chain did not verify.';
});

onMounted(() => {
  void store.fetchIntegrity();
});
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="audit-integrity-page"
  >
    <SvTonalPageHeader
      eyebrow="Audit"
      title="Integrity Status"
      description="Whether the append-only audit record still verifies — checked by the server, reported exactly as found."
      context="Shared chain · branch-safe summary"
      tone="green"
    >
      <template #actions>
        <SvButton
          variant="secondary"
          :loading="store.integrityLoading"
          loading-label="Checking"
          data-testid="audit-integrity-recheck"
          @click="store.fetchIntegrity()"
        >
          <template #icon-leading>
            <SvIconRefresh
              aria-hidden="true"
              class="h-4 w-4"
            />
          </template>
          Check again
        </SvButton>
      </template>
    </SvTonalPageHeader>

    <SvSkeleton
      v-if="store.integrityLoading && !result"
      label="Verifying the audit chain"
      shape="block"
      class="h-56"
    />

    <SvErrorState
      v-else-if="store.integrityError === 'forbidden'"
      title="Integrity status is not available to your account"
      message="It is shown to Audit users with branch audit access and an assigned branch."
      data-testid="audit-integrity-forbidden"
    />

    <SvErrorState
      v-else-if="store.integrityError === 'failed'"
      title="The integrity check did not complete"
      message="No result was produced, so no status is shown. Checking again is safe."
      retryable
      data-testid="audit-integrity-failed"
      @retry="store.fetchIntegrity()"
    />

    <div
      v-else-if="result"
      class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,0.7fr)]"
    >
      <div class="flex min-w-0 flex-col gap-5">
        <section
          class="sv-integrity-hero relative isolate overflow-hidden rounded-card border p-6 shadow-raised md:p-8"
          :data-status="result.status"
          aria-labelledby="integrity-result-heading"
          data-testid="audit-integrity-result"
        >
          <span
            aria-hidden="true"
            class="sv-integrity-hero__ring pointer-events-none absolute -right-14 -top-14 h-56 w-56 rounded-full"
          />
          <div class="relative flex items-start gap-4">
            <span
              aria-hidden="true"
              class="sv-integrity-hero__mark inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-card"
            >
              <SvIconCritical
                v-if="result.status === 'broken'"
                class="h-7 w-7"
              />
              <SvIconChainLink
                v-else
                class="h-7 w-7"
              />
            </span>
            <div class="min-w-0">
              <SvStatusBadge
                :label="presentation.label"
                :tone="presentation.tone"
                sr-prefix="Integrity:"
                data-testid="audit-integrity-status"
              />
              <h2
                id="integrity-result-heading"
                class="mt-2 font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl"
              >
                {{ presentation.headline }}
              </h2>
              <p class="mt-2 max-w-2xl text-sm leading-6 text-sv-text-secondary md:text-base">
                {{ explanation }}
              </p>
            </div>
          </div>
          <dl class="relative mt-6 grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-control border border-sv-border bg-sv-surface-raised p-3">
              <dt class="text-xs text-sv-text-muted">
                Checked
              </dt>
              <dd class="mt-1 text-sm font-semibold text-sv-text-heading">
                <SvDateTime :value="result.checked_at" />
              </dd>
            </div>
            <div class="rounded-control border border-sv-border bg-sv-surface-raised p-3">
              <dt class="text-xs text-sv-text-muted">
                Your branch’s records checked
              </dt>
              <dd class="mt-1 font-display text-xl font-extrabold text-sv-text-heading">
                {{ result.branch_records_checked.toLocaleString('en-KE') }}
              </dd>
            </div>
            <div class="rounded-control border border-sv-border bg-sv-surface-raised p-3">
              <dt class="text-xs text-sv-text-muted">
                Coverage from
              </dt>
              <dd class="mt-1 text-sm font-semibold text-sv-text-heading">
                <SvDateTime
                  :value="result.coverage_from"
                  unavailable-label="No records"
                />
              </dd>
            </div>
            <div class="rounded-control border border-sv-border bg-sv-surface-raised p-3">
              <dt class="text-xs text-sv-text-muted">
                Coverage to
              </dt>
              <dd class="mt-1 text-sm font-semibold text-sv-text-heading">
                <SvDateTime
                  :value="result.coverage_to"
                  unavailable-label="No records"
                />
              </dd>
            </div>
          </dl>
          <p class="relative mt-4 text-xs text-sv-text-muted">
            {{ result.complete_history ? 'This check reached the first record of the chain.' : `This check covered the latest ${result.segment_limit.toLocaleString('en-KE')} records of the shared chain, anchored to the record before them.` }}
            Records from other branches are part of the shared chain and are checked, but never counted or shown here.
          </p>
        </section>

        <section
          v-if="result.status === 'broken' && result.first_break"
          class="rounded-card border border-sv-error-border bg-sv-error-bg p-5"
          aria-labelledby="integrity-break-heading"
          data-testid="audit-integrity-break"
        >
          <h2
            id="integrity-break-heading"
            class="flex items-center gap-2 font-display text-lg font-bold text-sv-error-fg"
          >
            <SvIconCritical
              aria-hidden="true"
              class="h-5 w-5"
            />First break found
          </h2>
          <p class="mt-2 text-sm text-sv-text">
            {{ breakCopy }}
          </p>
          <template v-if="result.first_break.within_branch_scope && result.first_break.event">
            <p class="mt-2 text-sm text-sv-text">
              The affected record is in your branch, recorded <SvDateTime :value="result.first_break.occurred_at" />.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
              <RouterLink
                :to="{ name: 'audit.audit-event-detail', params: { eventUlid: result.first_break.event } }"
                class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
                data-testid="audit-integrity-open-event"
              >
                Open the affected event and flag it
              </RouterLink>
            </div>
          </template>
          <p
            v-else
            class="mt-2 text-sm text-sv-text"
          >
            The affected record belongs to another branch of your business, so it is not shown here. Citrus Labs platform engineering is responsible for investigation and repair.
          </p>
        </section>
      </div>

      <aside class="flex flex-col gap-5">
        <section class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
          <h2 class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
            <SvIconFingerprint
              aria-hidden="true"
              class="h-4 w-4"
            />How the chain works
          </h2>
          <ol class="mt-3 flex flex-col gap-3 text-sm text-sv-text-secondary">
            <li><strong class="text-sv-text-heading">1. Written once.</strong> Each audit record is appended; the database refuses edits and deletions.</li>
            <li><strong class="text-sv-text-heading">2. Sealed.</strong> Each record carries a hash of its contents and of the record before it.</li>
            <li><strong class="text-sv-text-heading">3. Re-checked.</strong> Recomputing the hashes reveals any change, gap or insertion.</li>
          </ol>
        </section>
        <section class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
          <h2 class="font-display text-base font-bold text-sv-text-heading">
            Daily full verification
          </h2>
          <p class="mt-2 text-sm text-sv-text-secondary">
            Servana also verifies every chain in full once a day. That run’s result goes to platform engineering and is not stored for this account to read, so it is not shown here.
          </p>
          <p class="mt-2 text-xs text-sv-text-muted">
            Status: not exposed to Audit accounts.
          </p>
        </section>
        <section class="rounded-card border border-sv-border bg-sv-surface-subtle p-5">
          <h2 class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
            <SvIconForbidden
              aria-hidden="true"
              class="h-4 w-4"
            />Not available to Audit
          </h2>
          <ul class="mt-2 flex flex-col gap-1.5 text-sm text-sv-text-secondary">
            <li>Repair, re-hash or reorder records</li>
            <li>Run or schedule the full verification</li>
            <li>See hash values or other branches’ records</li>
          </ul>
        </section>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.sv-integrity-hero {
  --sv-integrity: var(--sv-color-border-strong);
  border-color: color-mix(in srgb, var(--sv-integrity) 45%, var(--sv-color-border-default));
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-integrity) 20%, transparent), transparent 46%),
    radial-gradient(circle at 0% 100%, color-mix(in srgb, var(--sv-color-accent) 10%, transparent), transparent 40%),
    var(--sv-color-surface-raised);
}
.sv-integrity-hero[data-status='verified'] { --sv-integrity: var(--sv-color-growth); }
.sv-integrity-hero[data-status='partial'] { --sv-integrity: var(--sv-color-brand-secondary); }
.sv-integrity-hero[data-status='broken'] { --sv-integrity: var(--sv-color-status-error-border); }
.sv-integrity-hero__ring {
  border: 26px solid color-mix(in srgb, var(--sv-integrity) 10%, transparent);
}
.sv-integrity-hero__mark {
  background: color-mix(in srgb, var(--sv-integrity) 18%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
</style>
