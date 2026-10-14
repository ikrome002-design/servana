<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import { SvIconCheck, SvIconLocked, SvIconWarning } from '@/design-system/icons';
import { navigationIcon } from '@/navigation/navigationIcons';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCashUpStore } from '@/stores/cashUpStore';
import { usePermissionStore } from '@/stores/permissionStore';
import { useAuthStore } from '@/stores/authStore';
import { PAYMENT_METHODS } from '@/stores/paymentStore';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import { statusLabel } from '@/utils/statusLabel';

/**
 * Branch Manager cash-up (Plan §45; Phase 18B). The Branch Manager (maker) enters the
 * counted cash for each concrete method on the branch-day; the EXPECTED amounts are
 * server-derived and never editable here. Save draft → submit → (correction) resubmit.
 * Submitted / approved / locked cash-ups are READ-ONLY. The Branch Manager can NEVER
 * approve — that is Finance, enforced by the server.
 */
const store = useCashUpStore();
const permissions = usePermissionStore();
const auth = useAuthStore();

const today = new Date().toISOString().slice(0, 10);
const date = ref(today);
const busy = ref(false);
const actionError = ref<string | null>(null);
const counts = ref<Record<string, number>>({});

const canSubmit = computed(() => permissions.can('branch.cash_up.submit'));
const hasBranch = computed(() => auth.branchIds.length > 0);
const branchId = computed(() => auth.branchIds[0] ?? '');
const current = computed(() => store.current);
const status = computed(() => current.value?.status ?? 'draft');
const editable = computed(() => status.value === 'draft' || status.value === 'correction_requested');
const methods = PAYMENT_METHODS.filter((m) => m.value !== 'split_payment');

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (!canSubmit.value || !hasBranch.value) return 'empty';
  if (store.loading && current.value === null) return 'loading';
  if (store.error) return 'error';
  return 'success';
});

function expectedFor(method: string): number {
  // `lines` is a whenLoaded relation: absent unless the endpoint eager-loaded it.
  return current.value?.lines?.find((l) => l.method === method)?.expected_minor ?? 0;
}
function varianceFor(method: string): number {
  return (counts.value[method] ?? 0) - expectedFor(method);
}
const countedTotal = computed(() => Object.values(counts.value).reduce((a, b) => a + (b || 0), 0));
const expectedTotal = computed(() => methods.reduce((a, m) => a + expectedFor(m.value), 0));
const varianceTotal = computed(() => countedTotal.value - expectedTotal.value);

function seedCounts(): void {
  const next: Record<string, number> = {};
  for (const m of methods) {
    next[m.value] = current.value?.lines?.find((l) => l.method === m.value)?.counted_minor ?? 0;
  }
  counts.value = next;
}

async function load(): Promise<void> {
  if (!canSubmit.value || !hasBranch.value) return;
  await store.fetchBranchDay(branchId.value, date.value);
  seedCounts();
}

async function saveDraft(): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    const payload = methods
      .filter((m) => (counts.value[m.value] ?? 0) > 0)
      .map((m) => ({ method: m.value, counted_minor: counts.value[m.value] ?? 0 }));
    await store.saveDraft(branchId.value, date.value, payload);
    seedCounts();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The cash-up could not be saved.';
  } finally {
    busy.value = false;
  }
}

async function submitOrResubmit(): Promise<void> {
  if (current.value?.id == null) {
    await saveDraft();
  }
  const id = store.current?.id;
  if (id == null) return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.action(id, status.value === 'correction_requested' ? 'resubmit' : 'submit');
    seedCounts();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The cash-up could not be submitted.';
  } finally {
    busy.value = false;
  }
}

watch(date, () => void load());
onMounted(load);
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — cash-up with the expected-versus-counted SEPARATION dominant. Three
    figures lead: what Servana expects (server-derived, locked), what you counted (you, the maker),
    and the variance between them, toned when it is not zero; the per-method reconciliation follows,
    then the draft/submit actions. The rail carries the business date, the status and the
    maker/checker boundary: Finance approves, never Branch.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Cash-up and reconciliation"
      eyebrow="Branch operations"
      description="Count the day’s cash as the maker. Expected totals come from Servana; Finance checks and approves the cash-up."
    />

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load the cash-up."
      :empty-message="!hasBranch ? 'You are not assigned to a branch yet.' : 'Cash-up is available to Branch Managers.'"
    >
      <div class="sv-r-grid sv-r-split">
        <div class="sv-r-stack min-w-0">
          <div class="sv-r-grid sv-r-cols-3-md">
            <div class="sv-r-kpi sv-r-tone-teal">
              <div class="sv-r-kpi__top">
                <p class="sv-r-kpi__label">
                  Expected
                </p>
                <span
                  aria-hidden="true"
                  class="sv-r-icon sv-r-tone-teal"
                ><SvIconLocked /></span>
              </div>
              <p class="sv-r-kpi__value">
                {{ expectedTotal }}
              </p>
              <p class="sv-r-kpi__hint">
                From Servana — read-only.
              </p>
            </div>
            <div class="sv-r-kpi sv-r-tone-warm">
              <div class="sv-r-kpi__top">
                <p class="sv-r-kpi__label">
                  Counted
                </p>
                <span
                  aria-hidden="true"
                  class="sv-r-icon sv-r-tone-warm"
                ><component :is="navigationIcon('cash-up')" /></span>
              </div>
              <p class="sv-r-kpi__value">
                {{ countedTotal }}
              </p>
              <p class="sv-r-kpi__hint">
                Your count, as the maker.
              </p>
            </div>
            <div
              class="sv-r-kpi"
              :class="varianceTotal === 0 ? 'sv-r-tone-green' : 'sv-r-tone-alert'"
            >
              <div class="sv-r-kpi__top">
                <p class="sv-r-kpi__label">
                  Variance
                </p>
                <span
                  aria-hidden="true"
                  class="sv-r-icon"
                  :class="varianceTotal === 0 ? 'sv-r-tone-green' : 'sv-r-tone-alert'"
                ><SvIconCheck v-if="varianceTotal === 0" /><SvIconWarning v-else /></span>
              </div>
              <p
                class="sv-r-kpi__value"
                :class="varianceTotal === 0 ? '' : 'text-sv-warning-fg'"
              >
                {{ varianceTotal }}
              </p>
              <p class="sv-r-kpi__hint">
                {{ varianceTotal === 0 ? 'Counted matches expected.' : 'A discrepancy Finance will review.' }}
              </p>
            </div>
          </div>

          <section class="sv-r-panel">
            <!-- Mobile (≤767px): stacked method cards — no wide table, so no page overflow.
             Expected is server-derived and read-only; Counted stays operable. -->
            <ul
              role="list"
              class="space-y-3 md:hidden"
            >
              <li
                v-for="method in methods"
                :key="method.value"
                data-testid="cash-up-card"
                class="rounded-card border border-sv-border p-3"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="font-semibold text-heading">{{ method.label }}</span>
                  <span class="text-sm text-text-muted">
                    Expected <span class="text-heading">{{ expectedFor(method.value) }}</span>
                  </span>
                </div>
                <div class="mt-2 flex items-center justify-between gap-3">
                  <label
                    :for="`counted-m-${method.value}`"
                    class="text-sm text-text-muted"
                  >Counted</label>
                  <input
                    v-if="editable"
                    :id="`counted-m-${method.value}`"
                    type="number"
                    min="0"
                    :value="counts[method.value] ?? 0"
                    :aria-label="`Counted ${method.label}`"
                    class="w-32 max-w-[55%] rounded-lg border border-sv-border bg-surface px-2 py-1 text-right text-heading focus:outline-none focus:ring-2 focus:ring-sv-focus"
                    @input="counts[method.value] = Number(($event.target as HTMLInputElement).value)"
                  >
                  <span
                    v-else
                    class="text-heading"
                  >{{ counts[method.value] ?? 0 }}</span>
                </div>
                <p
                  class="mt-1 text-right text-sm"
                  :class="varianceFor(method.value) === 0 ? 'text-text-muted' : 'text-sv-warning-fg'"
                >
                  Variance {{ varianceFor(method.value) }}
                </p>
              </li>
              <li
                class="rounded-card border-2 border-sv-border bg-sv-surface-subtle p-3 font-semibold text-heading"
              >
                <div class="flex items-center justify-between gap-2">
                  <span>Total</span>
                  <span>Counted {{ countedTotal }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between gap-2 text-sm text-text-muted">
                  <span>Expected {{ expectedTotal }}</span>
                  <span>Variance {{ varianceTotal }}</span>
                </div>
              </li>
            </ul>

            <!-- Tablet/desktop (≥768px): full reconciliation table. -->
            <div class="hidden overflow-x-auto md:block">
              <table class="w-full min-w-[26rem] text-sm">
                <caption class="sv-r-title pb-3 text-left">
                  Per-method reconciliation
                </caption>
                <thead>
                  <tr class="text-left text-xs uppercase tracking-wide text-text-muted">
                    <th class="py-1">
                      Method
                    </th>
                    <th class="py-1 text-right">
                      Expected
                    </th>
                    <th class="py-1 text-right">
                      Counted
                    </th>
                    <th class="py-1 text-right">
                      Variance
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="method in methods"
                    :key="method.value"
                    class="border-t border-sv-border"
                    data-testid="cash-up-line"
                  >
                    <td class="py-2.5 font-semibold text-heading">
                      {{ method.label }}
                    </td>
                    <td class="py-2.5 text-right sv-numeric">
                      {{ expectedFor(method.value) }}
                    </td>
                    <td class="py-2.5 text-right sv-numeric">
                      <input
                        v-if="editable"
                        :id="`counted-${method.value}`"
                        type="number"
                        min="0"
                        :value="counts[method.value] ?? 0"
                        :aria-label="`Counted ${method.label}`"
                        :data-testid="`counted-${method.value}`"
                        class="w-28 rounded-lg border border-sv-border bg-surface px-2 py-1 text-right text-heading focus:outline-none focus:ring-2 focus:ring-sv-focus"
                        @input="counts[method.value] = Number(($event.target as HTMLInputElement).value)"
                      >
                      <span v-else>{{ counts[method.value] ?? 0 }}</span>
                    </td>
                    <td
                      class="py-1 text-right"
                      :class="varianceFor(method.value) === 0 ? '' : 'text-sv-warning-fg'"
                    >
                      {{ varianceFor(method.value) }}
                    </td>
                  </tr>
                  <tr class="border-t-2 border-sv-border font-semibold text-heading">
                    <td class="py-1">
                      Total
                    </td>
                    <td class="py-2.5 text-right sv-numeric">
                      {{ expectedTotal }}
                    </td>
                    <td class="py-2.5 text-right sv-numeric">
                      {{ countedTotal }}
                    </td>
                    <td class="py-2.5 text-right sv-numeric">
                      {{ varianceTotal }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              v-if="editable"
              class="mt-4 flex flex-wrap justify-end gap-2"
            >
              <SvButton
                variant="secondary"
                data-testid="cash-up-save"
                :loading="busy"
                @click="saveDraft"
              >
                Save draft
              </SvButton>
              <SvButton
                data-testid="cash-up-submit"
                :loading="busy"
                @click="submitOrResubmit"
              >
                {{ status === 'correction_requested' ? 'Resubmit' : 'Submit for review' }}
              </SvButton>
            </div>
            <p
              v-else
              class="mt-4 text-sm text-text-muted"
            >
              This cash-up is {{ status }} and can no longer be edited. Approval is a Finance action.
            </p>

            <p
              v-if="actionError"
              class="mt-3 text-sm text-sv-error-fg"
              role="alert"
            >
              {{ actionError }}
            </p>
          </section>
        </div>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Cash-up context"
        >
          <div class="sv-r-panel sv-r-panel--tonal sv-r-tone-green">
            <SvTextInput
              id="cash-up-date"
              v-model="date"
              type="date"
              label="Business date"
            />
            <p class="mt-4 text-sm text-text-muted">
              Status: <span class="sv-r-chip sv-r-chip--tone sv-r-chip--dot sv-r-tone-green">{{ statusLabel(status) }}</span>
            </p>
          </div>
          <div class="sv-r-note sv-r-tone-teal">
            <p class="sv-r-note__title">
              Maker, not checker
            </p>
            <p class="sv-r-note__body">
              You count and submit. Finance checks the cash-up against Servana's expected totals and approves or asks for a correction.
            </p>
          </div>
        </aside>
      </div>
    </SvStateBoundary>
  </section>
</template>
