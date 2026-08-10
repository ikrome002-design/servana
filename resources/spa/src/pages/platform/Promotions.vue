<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvEmptyState from '@/components/ui/SvEmptyState.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useCan } from '@/composables/useCan';
import {
  usePromotionStore,
  type OfferTargetInput,
  type PromotionalDiscount,
  type PromotionPayload,
} from '@/stores/promotionStore';
import {
  useFreePeriodOfferStore,
  type FreePeriodOffer,
  type FreePeriodOfferPayload,
} from '@/stores/freePeriodOfferStore';

// Phase 20C — the consolidated Super-Administrator promotions surface (Plan §27.1, §53). Two
// sections: Promotional discounts and Free-period offers. Each is gated by its resolved manage
// permission (UX only — the API enforces authorization, MFA and a fresh step-up on every mutation).
// Approved terms are immutable; a state-changing action requires a reason.
const { can } = useCan();
const promotions = usePromotionStore();
const offers = useFreePeriodOfferStore();

type TabKey = 'promotions' | 'free-periods';
interface TabDef {
  key: TabKey;
  label: string;
  permission: string;
}

/**
 * Phase UI-08: `only` renders exactly ONE section, with no page heading and no tablist.
 *
 * The UI/UX contract requires §5.4.6 (Promotional Discounts) and §5.4.7 (Free-Period Offers) to be
 * two distinct pages. Their forms, validation, precedence rules and lifecycle actions are the same
 * substantial code, so the canonical pages COMPOSE this section with `only` set rather than
 * duplicating it — the plan encourages shared form/table components and forbids six copies of the
 * same business logic. Each canonical page owns its own route, title, `h1` and tests.
 *
 * `only: null` preserves the original consolidated behaviour for the legacy `/platform/promotions`
 * route until Increment 7B retires it.
 */
const props = withDefaults(defineProps<{ only?: TabKey | null }>(), { only: null });

const allTabs: TabDef[] = [
  { key: 'promotions', label: 'Promotional discounts', permission: 'platform.promotion.manage' },
  { key: 'free-periods', label: 'Free-period offers', permission: 'platform.free_period_offer.manage' },
];
const tabs = computed<TabDef[]>(() =>
  allTabs.filter((t) => (props.only === null || t.key === props.only) && can(t.permission)),
);
const activeKey = ref<TabKey>('promotions');
const currentKey = computed<TabKey>(() => {
  if (props.only !== null) return props.only;
  return tabs.value.some((t) => t.key === activeKey.value) ? activeKey.value : (tabs.value[0]?.key ?? 'promotions');
});

const scopeOptions = [
  { value: 'all_new_merchants', label: 'All new merchants (global)' },
  { value: 'selected_merchants', label: 'Selected merchants' },
  { value: 'selected_plans', label: 'Selected plans' },
  { value: 'billing_mode', label: 'Billing mode' },
];
const billingModeOptions = [
  { value: 'fixed_amount', label: 'Fixed amount' },
  { value: 'percentage_on_merchant_client_invoice', label: 'Percentage on merchant-client invoice' },
  { value: 'fixed_amount_plus_percentage_on_merchant_client_invoice', label: 'Fixed amount plus percentage' },
];

function moneyKes(minor: number): string {
  return `KES ${(minor / 100).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function describeValue(p: PromotionalDiscount): string {
  return p.type === 'percentage' ? `${(p.value / 100).toFixed(2)}%` : moneyKes(p.value);
}

// ── Create form state ─────────────────────────────────────────────────────────────────────────
const showPromotionForm = ref(false);
const promotionForm = reactive<PromotionPayload>({
  name: '',
  type: 'percentage',
  value: 0,
  currency: null,
  target_scope: 'all_new_merchants',
  effective_from: '',
  effective_to: null,
  targets: [],
});
const promotionTargetInput = ref('');

const showOfferForm = ref(false);
const offerForm = reactive<FreePeriodOfferPayload>({
  name: '',
  free_period_days: 30,
  target_scope: 'all_new_merchants',
  effective_from: '',
  effective_to: null,
  targets: [],
});
const offerTargetInput = ref('');

const formError = ref<string | null>(null);
const submitting = ref(false);

// ── Reason modal for lifecycle transitions ──────────────────────────────────────────────────────
type Kind = 'promotion' | 'offer';
type Action = 'approve' | 'pause' | 'resume' | 'cancel';
const reasonModal = reactive<{ open: boolean; kind: Kind; action: Action; id: string; reason: string }>({
  open: false,
  kind: 'promotion',
  action: 'approve',
  id: '',
  reason: '',
});
const actionError = ref<string | null>(null);

function openReason(kind: Kind, action: Action, id: string): void {
  reasonModal.open = true;
  reasonModal.kind = kind;
  reasonModal.action = action;
  reasonModal.id = id;
  reasonModal.reason = '';
  actionError.value = null;
}

function buildTargets(scope: string, raw: string, modes: string[]): OfferTargetInput[] {
  if (scope === 'all_new_merchants') return [];
  if (scope === 'billing_mode') return modes.map((m) => ({ target_type: 'billing_mode', billing_mode: m }));
  const field = scope === 'selected_merchants' ? 'merchant_id' : 'subscription_plan_id';
  const type = scope === 'selected_merchants' ? 'merchant' : 'plan';
  return raw
    .split(/[\s,]+/)
    .filter((v) => v.length > 0)
    .map((v) => ({ target_type: type as OfferTargetInput['target_type'], [field]: v }));
}

const selectedModes = ref<string[]>([]);

async function submitPromotion(): Promise<void> {
  formError.value = null;
  submitting.value = true;
  try {
    const payload: PromotionPayload = {
      ...promotionForm,
      currency: promotionForm.type === 'fixed_amount' ? (promotionForm.currency ?? 'KES') : null,
      targets: buildTargets(promotionForm.target_scope, promotionTargetInput.value, selectedModes.value),
    };
    await promotions.createPromotion(payload);
    showPromotionForm.value = false;
    await promotions.fetchPromotions();
  } catch {
    formError.value = 'Could not create the promotion. Check the fields and your step-up, then retry.';
  } finally {
    submitting.value = false;
  }
}

async function submitOffer(): Promise<void> {
  formError.value = null;
  submitting.value = true;
  try {
    const payload: FreePeriodOfferPayload = {
      ...offerForm,
      targets: buildTargets(offerForm.target_scope, offerTargetInput.value, selectedModes.value),
    };
    await offers.createOffer(payload);
    showOfferForm.value = false;
    await offers.fetchOffers();
  } catch {
    formError.value = 'Could not create the free-period offer. Check the fields and your step-up, then retry.';
  } finally {
    submitting.value = false;
  }
}

async function confirmReason(): Promise<void> {
  if (reasonModal.reason.trim().length < 2) {
    actionError.value = 'A reason is required.';
    return;
  }
  submitting.value = true;
  try {
    if (reasonModal.kind === 'promotion') {
      await promotions.transition(reasonModal.id, reasonModal.action, reasonModal.reason.trim());
      await promotions.fetchPromotions();
    } else {
      await offers.transition(reasonModal.id, reasonModal.action, reasonModal.reason.trim());
      await offers.fetchOffers();
    }
    reasonModal.open = false;
  } catch {
    actionError.value = 'The action failed. A stale step-up or an invalid state transition may be the cause.';
  } finally {
    submitting.value = false;
  }
}

function promotionActions(p: PromotionalDiscount): Action[] {
  switch (p.status) {
    case 'draft':
    case 'scheduled':
      return ['approve', 'cancel'];
    case 'active':
      return ['pause'];
    case 'paused':
      return ['resume'];
    default:
      return [];
  }
}

function offerActions(o: FreePeriodOffer): Action[] {
  switch (o.status) {
    case 'draft':
    case 'scheduled':
      return ['approve', 'cancel'];
    case 'active':
      return ['pause'];
    case 'paused':
      return ['resume'];
    default:
      return [];
  }
}

onMounted(() => {
  if (can('platform.promotion.manage')) void promotions.fetchPromotions();
  if (can('platform.free_period_offer.manage')) void offers.fetchOffers();
});
</script>

<template>
  <section
    aria-labelledby="promotions-heading"
    class="promotions"
  >
    <!-- Suppressed when composed into a canonical page: that page owns the single `h1`. -->
    <header
      v-if="only === null"
      class="promotions__header"
    >
      <h1
        id="promotions-heading"
        class="promotions__title"
      >
        Promotions &amp; free periods
      </h1>
      <p class="promotions__subtitle">
        Platform-governed promotional discounts and free-period offers. Approved terms are immutable and
        every change requires a fresh step-up.
      </p>
    </header>

    <div v-if="tabs.length === 0">
      <SvEmptyState
        title="No access"
        description="You do not have permission to manage promotions."
      />
    </div>

    <template v-else>
      <!-- A single-concern page has nothing to switch between, so it renders no tablist. -->
      <div
        v-if="only === null"
        class="promotions__tabs"
        role="tablist"
        aria-label="Promotion sections"
      >
        <button
          v-for="tab in tabs"
          :key="tab.key"
          role="tab"
          type="button"
          :aria-selected="currentKey === tab.key"
          :class="['promotions__tab', { 'promotions__tab--active': currentKey === tab.key }]"
          @click="activeKey = tab.key"
        >
          {{ tab.label }}
        </button>
      </div>

      <!-- Promotional discounts -->
      <div
        v-if="currentKey === 'promotions'"
        role="tabpanel"
        class="promotions__panel"
      >
        <div class="promotions__toolbar">
          <SvButton
            variant="primary"
            @click="showPromotionForm = !showPromotionForm"
          >
            {{ showPromotionForm ? 'Close form' : 'New promotion' }}
          </SvButton>
        </div>

        <form
          v-if="showPromotionForm"
          class="promotions__form"
          @submit.prevent="submitPromotion"
        >
          <SvTextInput
            id="promo-name"
            v-model="promotionForm.name"
            label="Name"
            required
          />
          <SvSelect
            id="promo-type"
            v-model="promotionForm.type"
            label="Type"
            :options="[
              { value: 'percentage', label: 'Percentage' },
              { value: 'fixed_amount', label: 'Fixed amount' },
            ]"
          />
          <SvTextInput
            id="promo-value"
            v-model.number="(promotionForm.value as unknown as string)"
            type="number"
            :label="promotionForm.type === 'percentage' ? 'Value (basis points, ≤10000)' : 'Value (KES minor units)'"
            required
          />
          <SvSelect
            id="promo-scope"
            v-model="promotionForm.target_scope"
            label="Target scope"
            :options="scopeOptions"
          />
          <SvTextInput
            v-if="promotionForm.target_scope === 'selected_merchants' || promotionForm.target_scope === 'selected_plans'"
            id="promo-targets"
            v-model="promotionTargetInput"
            label="Target ULIDs (comma or space separated)"
          />
          <SvSelect
            v-if="promotionForm.target_scope === 'billing_mode'"
            id="promo-mode"
            :model-value="selectedModes[0] ?? ''"
            label="Billing mode"
            :options="billingModeOptions"
            @update:model-value="(v: string) => (selectedModes = [v])"
          />
          <SvTextInput
            id="promo-from"
            v-model="promotionForm.effective_from"
            type="date"
            label="Effective from"
            required
          />
          <SvTextInput
            id="promo-to"
            v-model="(promotionForm.effective_to as string)"
            type="date"
            label="Effective to (optional)"
          />
          <p
            v-if="formError"
            class="promotions__error"
            role="alert"
          >
            {{ formError }}
          </p>
          <SvButton
            type="submit"
            variant="primary"
            :loading="submitting"
          >
            Create draft
          </SvButton>
        </form>

        <p
          v-if="promotions.error"
          class="promotions__error"
          role="alert"
        >
          {{ promotions.error }}
        </p>
        <SvEmptyState
          v-else-if="!promotions.loading && promotions.promotions.length === 0"
          title="No promotions yet"
          description="Create a draft promotion, then approve it to make it active."
        />

        <ul
          v-else
          class="promotions__list"
        >
          <li
            v-for="p in promotions.promotions"
            :key="p.id"
          >
            <SvCard>
              <div class="promotions__item">
                <div>
                  <h3 class="promotions__item-title">
                    {{ p.name }}
                  </h3>
                  <p class="promotions__meta">
                    {{ describeValue(p) }} · scope {{ p.target_scope }} · status
                    <strong>{{ p.status }}</strong>
                  </p>
                  <p class="promotions__meta">
                    Effective {{ p.effective_from }}<span v-if="p.effective_to"> – {{ p.effective_to }}</span>
                  </p>
                </div>
                <div class="promotions__actions">
                  <SvButton
                    v-for="action in promotionActions(p)"
                    :key="action"
                    variant="secondary"
                    @click="openReason('promotion', action, p.id)"
                  >
                    {{ action }}
                  </SvButton>
                </div>
              </div>
            </SvCard>
          </li>
        </ul>
      </div>

      <!-- Free-period offers -->
      <div
        v-else
        role="tabpanel"
        class="promotions__panel"
      >
        <div class="promotions__toolbar">
          <SvButton
            variant="primary"
            @click="showOfferForm = !showOfferForm"
          >
            {{ showOfferForm ? 'Close form' : 'New free-period offer' }}
          </SvButton>
        </div>

        <form
          v-if="showOfferForm"
          class="promotions__form"
          @submit.prevent="submitOffer"
        >
          <SvTextInput
            id="offer-name"
            v-model="offerForm.name"
            label="Name"
            required
          />
          <SvTextInput
            id="offer-days"
            v-model.number="(offerForm.free_period_days as unknown as string)"
            type="number"
            label="Free period days (1–365)"
            required
          />
          <SvSelect
            id="offer-scope"
            v-model="offerForm.target_scope"
            label="Target scope"
            :options="scopeOptions"
          />
          <SvTextInput
            v-if="offerForm.target_scope === 'selected_merchants' || offerForm.target_scope === 'selected_plans'"
            id="offer-targets"
            v-model="offerTargetInput"
            label="Target ULIDs (comma or space separated)"
          />
          <SvSelect
            v-if="offerForm.target_scope === 'billing_mode'"
            id="offer-mode"
            :model-value="selectedModes[0] ?? ''"
            label="Billing mode"
            :options="billingModeOptions"
            @update:model-value="(v: string) => (selectedModes = [v])"
          />
          <SvTextInput
            id="offer-from"
            v-model="offerForm.effective_from"
            type="date"
            label="Effective from"
            required
          />
          <SvTextInput
            id="offer-to"
            v-model="(offerForm.effective_to as string)"
            type="date"
            label="Effective to (optional)"
          />
          <p
            v-if="formError"
            class="promotions__error"
            role="alert"
          >
            {{ formError }}
          </p>
          <SvButton
            type="submit"
            variant="primary"
            :loading="submitting"
          >
            Create draft
          </SvButton>
        </form>

        <p
          v-if="offers.error"
          class="promotions__error"
          role="alert"
        >
          {{ offers.error }}
        </p>
        <SvEmptyState
          v-else-if="!offers.loading && offers.offers.length === 0"
          title="No free-period offers yet"
          description="Create a draft offer, then approve it. Approval schedules it; activation follows automatically."
        />

        <ul
          v-else
          class="promotions__list"
        >
          <li
            v-for="o in offers.offers"
            :key="o.id"
          >
            <SvCard>
              <div class="promotions__item">
                <div>
                  <h3 class="promotions__item-title">
                    {{ o.name }}
                  </h3>
                  <p class="promotions__meta">
                    {{ o.free_period_days }} days · scope {{ o.target_scope }} · status <strong>{{ o.status }}</strong>
                  </p>
                  <p class="promotions__meta">
                    Effective {{ o.effective_from }}<span v-if="o.effective_to"> – {{ o.effective_to }}</span>
                  </p>
                </div>
                <div class="promotions__actions">
                  <SvButton
                    v-for="action in offerActions(o)"
                    :key="action"
                    variant="secondary"
                    @click="openReason('offer', action, o.id)"
                  >
                    {{ action }}
                  </SvButton>
                </div>
              </div>
            </SvCard>
          </li>
        </ul>
      </div>
    </template>

    <SvDialog
      :open="reasonModal.open"
      :title="`Confirm ${reasonModal.action}`"
      description="This action is audited and requires a reason. A fresh step-up is enforced by the server."
      @close="reasonModal.open = false"
    >
      <SvTextArea
        id="reason"
        v-model="reasonModal.reason"
        label="Reason"
        :rows="3"
        required
        :errors="actionError ? [actionError] : []"
      />
      <div class="promotions__modal-actions">
        <SvButton
          variant="ghost"
          @click="reasonModal.open = false"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="primary"
          :loading="submitting"
          @click="confirmReason"
        >
          Confirm
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>

<style scoped>
.promotions__header {
  margin-bottom: 1.5rem;
}
.promotions__title {
  font-family: var(--font-heading, 'Manrope', sans-serif);
  font-size: 1.5rem;
}
.promotions__subtitle {
  color: var(--sv-color-text-muted);
  max-width: 60ch;
}
.promotions__tabs {
  display: flex;
  gap: 0.5rem;
  border-bottom: 1px solid var(--sv-color-border-default);
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.promotions__tab {
  min-height: 44px;
  padding: 0 1rem;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  cursor: pointer;
  color: var(--color-text, inherit);
}
.promotions__tab--active {
  border-bottom-color: var(--sv-color-brand-primary);
  font-weight: 600;
}
.promotions__toolbar {
  margin-bottom: 1rem;
}
.promotions__form {
  display: grid;
  gap: 0.75rem;
  max-width: 40rem;
  margin-bottom: 1.5rem;
}
.promotions__list {
  list-style: none;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}
.promotions__item {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}
.promotions__item-title {
  font-weight: 600;
}
.promotions__meta {
  color: var(--sv-color-text-muted);
  font-size: 0.875rem;
}
.promotions__actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.promotions__modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 1rem;
}
.promotions__error {
  color: var(--sv-color-status-error-fg);
}
</style>
