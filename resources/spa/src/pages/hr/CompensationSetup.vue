<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { useCan } from '@/composables/useCan';
import {
  COMPENSATION_MODELS,
  SALARY_PERIODS,
  modelRequiresCommissionRule,
  modelRequiresSalary,
  useCompensationStore,
} from '@/stores/compensationStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { useStaffStore } from '@/stores/staffStore';

const route = useRoute();
const router = useRouter();
const store = useCompensationStore();
const staff = useStaffStore();
const notifications = useNotificationStore();
const { can } = useCan();
const staffId = computed(() => String(route.params.staffUlid ?? ''));
const member = computed(() => staff.current?.id === staffId.value ? staff.current : null);
const canCreate = computed(() => can('compensation.plan.create'));
const requiresSalary = computed(() => modelRequiresSalary(form.compensation_model));
const requiresRule = computed(() => modelRequiresCommissionRule(form.compensation_model));
const boundaryState = computed(() => (staff.loading ? 'loading' : staff.error ? 'error' : member.value ? 'success' : 'empty'));
const saving = ref(false);
const error = ref<string | null>(null);
const errors = reactive<Record<string, string>>({});
const form = reactive({
  compensation_model: 'salary_only',
  commission_rule_id: '',
  salary_amount_major: '',
  salary_currency: 'KES',
  salary_period: 'monthly',
  salary_payout_day: '',
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
  change_reason: '',
  notes: '',
});

const ruleOptions = computed(() => [
  { value: '', label: 'Select a commission rule' },
  ...store.rules.map((rule) => ({
    value: rule.id,
    label: rule.status_label + ' · ' + rule.calculation_type.replaceAll('_', ' ') + ' · from ' + rule.effective_from,
  })),
]);

function validate(): boolean {
  Object.keys(errors).forEach((key) => delete errors[key]);
  if (!form.effective_from) errors.effective_from = 'Choose when these terms begin.';
  if (!form.change_reason.trim()) errors.change_reason = 'Explain why these terms are being created.';
  if (requiresSalary.value) {
    const amount = Number(form.salary_amount_major);
    if (!form.salary_amount_major || Number.isNaN(amount) || amount <= 0) errors.salary_amount_major = 'Enter a salary amount greater than zero.';
    if (!form.salary_period) errors.salary_period = 'Choose a salary period.';
  }
  if (requiresRule.value && !form.commission_rule_id) errors.commission_rule_id = 'Choose a commission rule for this model.';
  if (form.effective_to && form.effective_to <= form.effective_from) errors.effective_to = 'The end date must be after the start date.';
  return Object.keys(errors).length === 0;
}

async function submit(): Promise<void> {
  if (!member.value || !canCreate.value || saving.value || !validate()) return;
  saving.value = true;
  error.value = null;
  try {
    await store.createPlan({
      staff_profile_id: member.value.id,
      compensation_model: form.compensation_model,
      commission_rule_id: requiresRule.value ? form.commission_rule_id : null,
      salary_amount_minor: requiresSalary.value ? Math.round(Number(form.salary_amount_major) * 100) : null,
      salary_currency: requiresSalary.value ? form.salary_currency : null,
      salary_period: requiresSalary.value ? form.salary_period : null,
      salary_payout_day: requiresSalary.value && form.salary_payout_day ? Number(form.salary_payout_day) : null,
      effective_from: form.effective_from,
      effective_to: form.effective_to || null,
      change_reason: form.change_reason.trim(),
      notes: form.notes.trim() || null,
    });
    notifications.addToast({ type: 'success', message: 'Compensation plan draft created.' });
    await router.push({ name: 'hr.compensation-detail', params: { staffUlid: member.value.id } });
  } catch {
    error.value = 'The server did not accept this compensation setup. Review the fields and existing effective windows.';
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  await Promise.all([
    staff.fetchStaffMember(staffId.value),
    store.fetchCommissionRules().catch(() => undefined),
  ]);
});
</script>

<template>
  <section
    class="mx-auto max-w-4xl"
    data-testid="hr-compensation-setup"
  >
    <SvPageHeader
      :title="member ? 'Set up ' + member.display_name + ' compensation' : 'Compensation setup'"
      eyebrow="Declared terms"
      description="Create an effective-dated draft using salary only, commission only or salary plus commission. Approval remains a separate maker/checker transition."
    />

    <SvStateBoundary
      :state="boundaryState"
      :error-message="staff.error ?? undefined"
      empty-message="This staff member is not available in your assigned branch."
      @retry="staff.fetchStaffMember(staffId)"
    >
      <template v-if="member">
        <SvAlert
          v-if="!canCreate"
          severity="error"
          title="Create permission required"
        >
          Your current server-authorized permission set does not allow compensation-plan creation.
        </SvAlert>
        <form
          v-else
          class="grid gap-5"
          novalidate
          @submit.prevent="submit"
        >
          <SvCard as="section">
            <h2 class="font-display text-lg font-bold text-heading">
              1. Compensation model
            </h2>
            <p class="mt-1 text-sm text-text-muted">
              Choose the shape of declared terms. The database enforces the same model rules.
            </p>
            <SvSelect
              id="compensation-model"
              v-model="form.compensation_model"
              class="mt-4"
              label="Compensation model"
              :options="[...COMPENSATION_MODELS]"
              required
            />
          </SvCard>

          <SvCard
            v-if="requiresSalary"
            as="section"
          >
            <h2 class="font-display text-lg font-bold text-heading">
              2. Salary terms
            </h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <SvTextInput
                id="salary-amount"
                v-model="form.salary_amount_major"
                label="Salary amount"
                type="number"
                inputmode="decimal"
                required
                :errors="errors.salary_amount_major ? [errors.salary_amount_major] : []"
                help="Entered in major units; the server stores integer minor units."
              />
              <SvTextInput
                id="salary-currency"
                v-model="form.salary_currency"
                label="Currency"
                readonly
              />
              <SvSelect
                id="salary-period"
                v-model="form.salary_period"
                label="Salary period"
                :options="[...SALARY_PERIODS]"
                required
                :errors="errors.salary_period ? [errors.salary_period] : []"
              />
              <SvTextInput
                id="salary-payout-day"
                v-model="form.salary_payout_day"
                label="Payout day (optional)"
                type="number"
                inputmode="numeric"
              />
            </div>
          </SvCard>

          <SvCard
            v-if="requiresRule"
            as="section"
          >
            <h2 class="font-display text-lg font-bold text-heading">
              2. Commission rule
            </h2>
            <SvSelect
              id="commission-rule"
              v-model="form.commission_rule_id"
              class="mt-4"
              label="Commission rule"
              :options="ruleOptions"
              required
              :errors="errors.commission_rule_id ? [errors.commission_rule_id] : []"
            />
            <p
              v-if="store.rules.length === 0"
              class="mt-3 text-sm text-text-muted"
            >
              No commission rule is available. Create a draft rule from the
              <RouterLink
                class="font-semibold text-heading underline"
                :to="{ name: 'hr.compensation' }"
              >
                Compensation List
              </RouterLink>
              before using a commission model.
            </p>
          </SvCard>

          <SvCard as="section">
            <h2 class="font-display text-lg font-bold text-heading">
              {{ requiresSalary || requiresRule ? '3' : '2' }}. Effective window and reason
            </h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <SvTextInput
                id="effective-from"
                v-model="form.effective_from"
                label="Effective from"
                type="date"
                required
                :errors="errors.effective_from ? [errors.effective_from] : []"
              />
              <SvTextInput
                id="effective-to"
                v-model="form.effective_to"
                label="Effective to (optional)"
                type="date"
                :errors="errors.effective_to ? [errors.effective_to] : []"
              />
            </div>
            <SvTextArea
              id="change-reason"
              v-model="form.change_reason"
              class="mt-4"
              label="Change reason"
              required
              :errors="errors.change_reason ? [errors.change_reason] : []"
            />
            <SvTextArea
              id="compensation-notes"
              v-model="form.notes"
              class="mt-4"
              label="Notes (optional)"
            />
          </SvCard>

          <SvAlert
            v-if="error"
            severity="error"
            title="Setup not saved"
          >
            {{ error }}
          </SvAlert>
          <div class="flex flex-wrap justify-end gap-2">
            <RouterLink
              class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-border px-4 py-2 text-sm font-semibold text-heading"
              :to="{ name: 'hr.compensation-detail', params: { staffUlid: member.id } }"
            >
              Cancel
            </RouterLink>
            <SvButton
              type="submit"
              :loading="saving"
            >
              Create draft
            </SvButton>
          </div>
        </form>
      </template>
    </SvStateBoundary>
  </section>
</template>
