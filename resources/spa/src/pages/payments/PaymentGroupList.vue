<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue';
import { useRoute } from 'vue-router';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { SvIconChevronRight } from '@/design-system/icons';
import { usePaymentStore } from '@/stores/paymentStore';
import { formatDateTime } from '@/utils/dates';

/**
 * Finance payment recordings (Plan §41; Phase 18A). Read-only list of recording
 * groups awaiting Finance action (customer_payment.view). There is NO validation,
 * rejection, or receipt control here — those are Phase 18B. A group held for a
 * duplicate-reference review is flagged; the override lives on the detail page.
 */
const store = usePaymentStore();
const route = useRoute();
const filters = reactive({ page: 1 });
const validationView = computed(() => route.name === 'finance.payments-validations');
const pageTitle = computed(() => validationView.value ? 'Pending validations' : 'Payment records');
const pageDescription = computed(() => validationView.value
  ? 'Whole payment groups awaiting a Finance checker decision, oldest first. Each decision remains atomic across every component.'
  : 'Recorded groups across their lifecycle. References stay masked and recognized money changes only after server validation.');

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.groups.length === 0) return 'empty';
  return 'success';
});

function statusLabel(status: string): string {
  return status === 'pending_validation' ? 'Pending validation' : status === 'recorded' ? 'Held — duplicate review' : status;
}

function statusClass(status: string): string {
  if (status === 'recorded') return 'border-sv-warning-border bg-sv-warning-bg text-sv-warning-fg';
  if (status === 'pending_validation') return 'border-sv-info-border bg-sv-info-bg text-sv-info-fg';
  return 'border-sv-border bg-surface-alt text-text';
}

function when(iso: string | null): string {
  return iso ? formatDateTime(iso) : 'Not recorded';
}

function load(page = filters.page): void {
  filters.page = page;
  const params: Record<string, string | number> = {
    page,
    per_page: 20,
    sort: validationView.value ? 'recorded_at' : '-recorded_at',
  };
  if (validationView.value) params.status = 'pending_validation';
  void store.fetchGroups(params);
}

onMounted(() => load());
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — one component, two compositions by route.
    Pending validations is a CHECKER QUEUE: numbered oldest-first rows that say how long each group
    has waited (the server's recorded time), who made it, the whole-group total and the one action —
    review the group — beside a rail that states the whole-group and maker/checker rules.
    Payment records is a LEDGER: invoice, maker, recorded time, total and lifecycle status in
    columns, newest first. Neither composition validates, rejects or issues a receipt.
  -->
  <section
    class="sv-r-page"
    :data-testid="validationView ? 'finance-pending-validations' : 'finance-payment-records'"
  >
    <SvPageHeader
      :title="pageTitle"
      eyebrow="Merchant-client finance"
      :description="pageDescription"
    />

    <SvStateBoundary
      :state="boundaryState"
      empty-message="No payment recordings yet."
      error-message="We couldn’t load payment recordings."
      @retry="load()"
    >
      <!-- Checker queue -------------------------------------------------------------------------- -->
      <div
        v-if="validationView"
        class="sv-r-grid sv-r-split"
      >
        <section
          class="min-w-0"
          aria-labelledby="validation-queue-heading"
        >
          <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
              <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal">
                Oldest first
              </p>
              <h2
                id="validation-queue-heading"
                class="sv-r-title mt-1"
              >
                Checker queue
              </h2>
            </div>
            <span
              v-if="store.groupMeta"
              class="sv-r-chip"
            >{{ store.groupMeta.total }} awaiting a decision</span>
          </div>
          <ol
            class="sv-r-queue"
            aria-label="Payment recordings"
          >
            <li
              v-for="(group, index) in store.groups"
              :key="group.id"
              class="sv-r-queue__row sv-r-tone-teal"
            >
              <span
                aria-hidden="true"
                class="sv-r-queue__count"
              >{{ ((store.groupMeta?.current_page ?? 1) - 1) * (store.groupMeta?.per_page ?? 20) + index + 1 }}</span>
              <span class="sv-r-queue__label">
                <span class="block">
                  <RouterLink
                    :to="{ name: 'finance.payments-validation-detail', params: { groupUlid: group.id } }"
                    class="sv-focus-ring rounded-control hover:underline focus-visible:underline"
                  >
                    Invoice {{ group.invoice?.invoice_number ?? '—' }}
                  </RouterLink>
                </span>
                <span class="sv-r-queue__detail">
                  Waiting since {{ when(group.submitted_for_validation_at ?? group.recorded_at) }} · Recorded by {{ group.maker?.name ?? '—' }}
                </span>
              </span>
              <span class="flex shrink-0 flex-col items-end gap-1">
                <span class="sv-numeric font-display text-base font-extrabold text-heading">{{ group.total.formatted }}</span>
                <span
                  class="inline-flex rounded-pill border px-2.5 py-0.5 text-xs font-semibold"
                  :class="statusClass(group.status)"
                  data-testid="group-status-badge"
                >{{ statusLabel(group.status) }}</span>
              </span>
              <RouterLink
                class="sv-r-action sv-r-action--quiet sv-focus-ring shrink-0"
                :to="{ name: 'finance.payments-validation-detail', params: { groupUlid: group.id } }"
                :aria-label="`Review group for invoice ${group.invoice?.invoice_number ?? 'without number'}`"
              >
                Review<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </li>
          </ol>
        </section>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Validation rules"
        >
          <section class="sv-r-note sv-r-tone-teal">
            <h2 class="sv-r-note__title">
              Whole-group decision
            </h2>
            <p class="sv-r-note__body">
              A group is validated or rejected as one: every component moves together, and one original receipt follows a validation automatically.
            </p>
          </section>
          <section class="sv-r-note sv-r-tone-sun">
            <h2 class="sv-r-note__title">
              Maker and checker
            </h2>
            <p class="sv-r-note__body">
              The person who recorded a group can never validate it. The server enforces this on every decision.
            </p>
          </section>
          <section class="sv-r-note sv-r-tone-neutral">
            <h2 class="sv-r-note__title">
              Masked references
            </h2>
            <p class="sv-r-note__body">
              Payment references stay masked on every Finance surface. Duplicate-held groups are reviewed from the duplicate queue.
            </p>
            <RouterLink
              class="sv-r-link sv-focus-ring mt-2 rounded-control"
              :to="{ name: 'finance.payments-duplicates' }"
            >
              Duplicate review<SvIconChevronRight
                aria-hidden="true"
                class="h-4 w-4"
              />
            </RouterLink>
          </section>
        </aside>
      </div>

      <!-- Records ledger ------------------------------------------------------------------------- -->
      <section
        v-else
        class="sv-r-ledger"
        style="--sv-r-ledger-cols: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 0.9fr) minmax(0, 1fr)"
        aria-labelledby="payment-records-heading"
      >
        <div class="sv-r-ledger__head">
          <h2
            id="payment-records-heading"
            class="sv-r-title"
          >
            Recorded groups
          </h2>
          <span class="sv-r-chip">Newest first · references masked</span>
        </div>
        <div
          class="sv-r-ledger__colhead"
          aria-hidden="true"
        >
          <span>Invoice</span><span>Recorded by</span><span>Recorded</span><span class="text-right">Total</span><span class="text-right">Status</span>
        </div>
        <ul aria-label="Payment recordings">
          <li
            v-for="group in store.groups"
            :key="group.id"
            class="sv-r-ledger__row"
          >
            <span class="min-w-0 font-semibold text-heading">
              <RouterLink
                :to="{ name: 'finance.payments-validation-detail', params: { groupUlid: group.id } }"
                class="sv-focus-ring rounded-control hover:underline focus-visible:underline"
              >
                Invoice {{ group.invoice?.invoice_number ?? '—' }}
              </RouterLink>
            </span>
            <span class="min-w-0 text-sm text-text-muted">Recorded by {{ group.maker?.name ?? '—' }}</span>
            <span class="text-sm text-text-muted">{{ when(group.recorded_at) }}</span>
            <span class="sv-numeric font-display font-extrabold text-heading md:text-right">{{ group.total.formatted }}</span>
            <span class="md:text-right">
              <span
                class="inline-flex rounded-pill border px-2.5 py-0.5 text-xs font-semibold"
                :class="statusClass(group.status)"
                data-testid="group-status-badge"
              >{{ statusLabel(group.status) }}</span>
            </span>
          </li>
        </ul>
      </section>

      <SvPagination
        v-if="store.groupMeta && store.groupMeta.last_page > 1"
        class="mt-5"
        :current-page="store.groupMeta.current_page"
        :last-page="store.groupMeta.last_page"
        :total="store.groupMeta.total"
        :per-page="store.groupMeta.per_page"
        @change="load"
      />
    </SvStateBoundary>
  </section>
</template>
