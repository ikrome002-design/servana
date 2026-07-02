<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { usePaymentStore, type PaymentRecordingGroupView } from '@/stores/paymentStore';
import { usePermissionStore } from '@/stores/permissionStore';

/**
 * Finance payment-recording detail (Plan §41; Phase 18A). Shows the group, its
 * masked components, and — when the group is held for a duplicate-reference review —
 * a capability-gated override (customer_payment.duplicate_override; the server also
 * enforces MFA + a fresh step-up + a mandatory reason). There is NO validation,
 * rejection, or receipt control; the original reference is never edited.
 */
interface DuplicateCheck {
  id: string;
  method: string;
  reference_masked: string | null;
}

const route = useRoute();
const store = usePaymentStore();
const permissions = usePermissionStore();

const group = ref<(PaymentRecordingGroupView & { duplicate_checks?: DuplicateCheck[] }) | null>(null);
const loadError = ref(false);
const overriding = ref<DuplicateCheck | null>(null);
const reason = ref('');
const busy = ref(false);
const overrideError = ref<string | null>(null);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (group.value === null && !loadError.value) return 'loading';
  if (loadError.value) return 'error';
  return 'success';
});

const canOverride = computed(() => permissions.can('customer_payment.duplicate_override'));
const duplicates = computed<DuplicateCheck[]>(() => group.value?.duplicate_checks ?? []);

async function load(): Promise<void> {
  try {
    group.value = (await store.fetchGroup(String(route.params.id))) as PaymentRecordingGroupView & {
      duplicate_checks?: DuplicateCheck[];
    };
  } catch {
    loadError.value = true;
  }
}

async function confirmOverride(): Promise<void> {
  if (overriding.value === null || reason.value.trim() === '') return;
  busy.value = true;
  overrideError.value = null;
  try {
    await store.overrideDuplicate(overriding.value.id, reason.value.trim());
    overriding.value = null;
    reason.value = '';
    await load();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    overrideError.value = err.response?.data?.error?.message ?? 'The override could not be completed.';
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Payment recording
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load this recording."
      empty-message="Recording not found."
    >
      <SvCard
        as="section"
        padding="md"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="text-xs text-text-muted">
              Invoice {{ group?.invoice?.invoice_number ?? '—' }}
            </p>
            <p class="font-display text-lg font-semibold text-heading">
              {{ group?.total.formatted }}
            </p>
            <p class="mt-0.5 text-sm text-text-muted">
              Recorded by {{ group?.maker?.name ?? '—' }} · Status: {{ group?.status }}
            </p>
          </div>
        </div>

        <ul
          class="mt-4 flex flex-col gap-2"
          aria-label="Payment components"
        >
          <li
            v-for="component in group?.components ?? []"
            :key="component.id"
            class="flex items-center justify-between rounded-lg bg-surface-alt px-3 py-2 text-sm"
          >
            <span class="font-semibold text-heading">{{ component.method }}</span>
            <span class="text-text-muted">{{ component.reference_masked ?? 'No reference' }}</span>
            <span class="font-semibold text-heading">{{ component.amount.formatted }}</span>
          </li>
        </ul>
      </SvCard>

      <!-- Held duplicate references awaiting override -->
      <SvCard
        v-if="duplicates.length > 0"
        as="section"
        padding="md"
        class="mt-4 border-l-4 border-l-[color:var(--color-warning,#d97706)]"
        data-testid="duplicate-review"
      >
        <h2 class="font-display text-base font-semibold text-heading">
          Duplicate references held for review
        </h2>
        <ul class="mt-2 flex flex-col gap-2">
          <li
            v-for="check in duplicates"
            :key="check.id"
            class="flex flex-wrap items-center justify-between gap-2 text-sm"
          >
            <span>{{ check.method }} · ends {{ check.reference_masked ?? '—' }}</span>
            <SvButton
              v-if="canOverride"
              variant="secondary"
              data-testid="override-open"
              @click="overriding = check"
            >
              Override
            </SvButton>
          </li>
        </ul>
        <p
          v-if="!canOverride"
          class="mt-2 text-sm text-text-muted"
        >
          Only Finance with the override capability can release a suspected duplicate.
        </p>
      </SvCard>
    </SvStateBoundary>

    <SvModal
      :open="overriding !== null"
      title="Override duplicate reference"
      description="This releases the held payment for validation. A reason is required and a fresh step-up may be requested. The original reference is never edited."
      @close="overriding = null"
    >
      <SvTextarea
        id="override-reason"
        v-model="reason"
        label="Reason"
        class="mt-2"
      />
      <p
        v-if="overrideError"
        class="mt-2 text-sm text-[color:var(--color-danger,#dc2626)]"
        role="alert"
      >
        {{ overrideError }}
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="overriding = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="override-confirm"
          :loading="busy"
          :disabled="reason.trim() === ''"
          @click="confirmOverride"
        >
          Override and release
        </SvButton>
      </div>
    </SvModal>
  </section>
</template>
