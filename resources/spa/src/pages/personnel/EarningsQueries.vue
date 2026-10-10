<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import PersonnelEarningsNav from '@/components/personnel/PersonnelEarningsNav.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { EARNINGS_QUERY_SUBJECT_OPTIONS, EARNINGS_QUERY_TYPE_OPTIONS, earningsQueryStatusLabel, earningsQuerySubjectLabel, earningsQueryTypeLabel } from '@/content/payout';
import { useEarningsQueryStore } from '@/stores/earningsQueryStore';
import { nairobiDate, personnelStatusTone } from '@/utils/personnelStatus';

/*
 * Earnings queries — own only (UI-14). A query asks Finance about one of the caller's own
 * compensation facts; it never edits a ledger, and any correction is a separate adjustment.
 * Commission, Salary and Payout records link here with `?subject_type=&subject=` so the opaque own
 * reference is pre-filled; the server still rejects any reference that is not the caller's own.
 * There is no Personnel reply/reopen contract, so none is offered.
 */
const store = useEarningsQueryStore();
const route = useRoute();
const SUBJECTS = new Set(EARNINGS_QUERY_SUBJECT_OPTIONS.map((option) => option.value));
const initialSubject = typeof route.query.subject_type === 'string' && SUBJECTS.has(route.query.subject_type) ? route.query.subject_type : 'commission_ledger';
const initialReference = typeof route.query.subject === 'string' && /^[0-9A-Z]{26}$/i.test(route.query.subject) ? route.query.subject : '';
const prefix = initialSubject.split('_')[0];
const form = reactive({
  subject_type: initialSubject,
  subject_ulid: initialReference,
  query_type: EARNINGS_QUERY_TYPE_OPTIONS.find((option) => option.value.startsWith(prefix))?.value ?? 'commission_disagreement',
  body: '',
});
const prefilled = initialReference !== '';
const formError = ref('');
const success = ref('');
const state = computed(() => store.listLoading ? 'loading' : store.listError ? 'error' : store.queries.length ? 'success' : 'empty');
async function submit(): Promise<void> {
  formError.value = ''; success.value = '';
  if (form.subject_ulid.trim().length !== 26 || form.body.trim().length < 3) { formError.value = 'Enter a 26-character own reference and a short description.'; return; }
  try { await store.createQuery({ ...form, subject_ulid: form.subject_ulid.trim(), body: form.body.trim() }); form.subject_ulid = ''; form.body = ''; success.value = 'Your query was submitted to Finance.'; await store.fetchQueries('personnel', 1); }
  catch { formError.value = 'The query could not be submitted. Confirm the reference belongs to your own earnings.'; }
}
onMounted(() => { void store.fetchQueries('personnel', 1); });
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="personnel-earnings-queries"
  >
    <SvTonalPageHeader
      title="Earnings queries"
      eyebrow="My earnings"
      tone="green"
      description="Ask Finance about one of your own compensation facts. A query never edits a ledger; any correction is recorded as a separate adjustment."
    />
    <PersonnelEarningsNav />
    <div class="grid gap-6 lg:grid-cols-[0.8fr_1.2fr]">
      <section
        aria-labelledby="raise-query-heading"
        class="sv-query-form self-start rounded-card border border-sv-border p-5 shadow-raised md:p-6 lg:sticky lg:top-6"
      >
        <h2
          id="raise-query-heading"
          class="font-display text-lg font-bold text-sv-text-heading"
        >
          Raise a query
        </h2>
        <p
          v-if="prefilled"
          class="mt-2 rounded-control bg-sv-selected-bg px-3 py-2 text-xs font-semibold text-sv-selected-fg"
        >
          Linked to the {{ earningsQuerySubjectLabel(form.subject_type).toLowerCase() }} you selected.
        </p>
        <form
          class="mt-4 space-y-4"
          @submit.prevent="submit"
        >
          <SvSelect
            id="query-subject"
            v-model="form.subject_type"
            label="What is this about?"
            :options="EARNINGS_QUERY_SUBJECT_OPTIONS"
          /><SvTextInput
            id="query-reference"
            v-model="form.subject_ulid"
            label="Own earnings reference"
            help="Filled in when you start from a commission, salary or payout record."
          /><SvSelect
            id="query-type"
            v-model="form.query_type"
            label="Question type"
            :options="EARNINGS_QUERY_TYPE_OPTIONS"
          /><SvTextArea
            id="query-body"
            v-model="form.body"
            label="Describe the issue"
            :rows="4"
          /><p
            v-if="formError"
            role="alert"
            class="text-sm text-sv-error-fg"
          >
            {{ formError }}
          </p><p
            v-if="success"
            role="status"
            class="rounded-control bg-sv-success-bg p-3 text-sm font-semibold text-sv-success-fg"
          >
            {{ success }}
          </p><button
            type="submit"
            :disabled="store.mutating"
            class="sv-focus-ring min-h-sv-touch w-full rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover disabled:opacity-60"
          >
            Submit to Finance
          </button>
        </form>
      </section>
      <section aria-labelledby="query-history-heading">
        <h2
          id="query-history-heading"
          class="font-display text-lg font-bold text-sv-text-heading"
        >
          Your queries
        </h2>
        <SvStateBoundary
          class="mt-3"
          :state="state"
          :error-message="store.listError ?? undefined"
          empty-message="You have not raised an earnings query."
          @retry="store.fetchQueries('personnel', 1)"
        >
          <ul
            class="flex flex-col gap-3"
            aria-label="Your earnings queries"
          >
            <li
              v-for="query in store.queries"
              :key="query.id"
              class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                    {{ earningsQuerySubjectLabel(query.subject_type) }}<template v-if="query.created_at">
                      · {{ nairobiDate(query.created_at) }}
                    </template>
                  </p>
                  <h3 class="mt-1 font-display text-base font-bold text-sv-text-heading">
                    {{ earningsQueryTypeLabel(query.query_type) }}
                  </h3>
                </div>
                <SvStatusBadge
                  size="sm"
                  :label="earningsQueryStatusLabel(query.status)"
                  :tone="personnelStatusTone(query.status)"
                />
              </div>
              <p class="mt-3 text-sm leading-6 text-sv-text">
                {{ query.body }}
              </p>
              <div
                v-if="query.resolution_note"
                class="mt-3 border-l-4 border-sv-info-border bg-sv-info-bg p-3 text-sm text-sv-info-fg"
              >
                <p class="text-xs font-semibold uppercase tracking-wide">
                  Response<template v-if="query.responded_at">
                    · {{ nairobiDate(query.responded_at, true) }}
                  </template>
                </p>
                <p class="mt-1">
                  {{ query.resolution_note }}
                </p>
              </div>
              <p
                v-if="query.resolved_adjustment_id"
                class="mt-2 text-xs text-sv-text-muted"
              >
                Correction recorded as a separate adjustment.
              </p>
            </li>
          </ul>
        </SvStateBoundary>
      </section>
    </div>
  </section>
</template>

<style scoped>
.sv-query-form {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-primary) 10%, transparent), transparent 50%),
    var(--sv-color-surface-raised);
}
</style>
