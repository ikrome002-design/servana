<script setup lang="ts">
/**
 * SvMoneyInput — a monetary amount field (Phase UI-04; CLAUDE.md guardrail 6).
 *
 * The model value is INTEGER MINOR UNITS, never a float and never a major-unit decimal. The user
 * types major units; conversion happens once, deterministically, with explicit rounding — because
 * `parseFloat(x) * 100` produces `1234.9999999999998` for perfectly ordinary inputs, and a cent
 * lost that way is a real financial defect.
 *
 * Other properties:
 *  - the raw entry is preserved while typing, so the cursor is never fought;
 *  - an empty field emits `null` (unknown), NOT `0` — "unavailable is not zero" applies to input
 *    as much as to display;
 *  - the currency is explicit and shown, never assumed;
 *  - the component performs no business arithmetic. It converts a representation. The server
 *    remains the sole authority on every amount.
 */
import { computed, ref, watch } from 'vue';
import SvFormField, { type SvFieldStatus } from '@/components/ui/SvFormField.vue';

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    /** Integer minor units, or null when no amount has been entered. */
    modelValue?: number | null;
    currency?: string;
    help?: string;
    errors?: string[];
    message?: string;
    status?: SvFieldStatus;
    required?: boolean;
    disabled?: boolean;
    readonly?: boolean;
    /** Minor-unit bounds, used only for the native constraint — the server still validates. */
    minMinor?: number | null;
    maxMinor?: number | null;
  }>(),
  {
    modelValue: null,
    currency: 'KES',
    help: undefined,
    errors: () => [],
    message: undefined,
    status: 'default',
    required: false,
    disabled: false,
    readonly: false,
    minMinor: null,
    maxMinor: null,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

/** What the user sees and edits, in major units. Kept separate so typing is never rewritten. */
const raw = ref(props.modelValue === null ? '' : (props.modelValue / 100).toFixed(2));

// Follow external changes (a form reset, a server-supplied default) without clobbering typing.
watch(
  () => props.modelValue,
  (next) => {
    const current = raw.value === '' ? null : toMinorUnits(raw.value);
    if (next !== current) {
      raw.value = next === null ? '' : (next / 100).toFixed(2);
    }
  },
);

/**
 * Major-unit text to integer minor units.
 *
 * `Math.round` on the scaled value is the whole point: multiplying a parsed float by 100 is not
 * exact, so the result is rounded to the nearest cent rather than truncated by coercion.
 */
function toMinorUnits(text: string): number | null {
  const cleaned = text.replace(/[^0-9.-]/g, '');
  if (cleaned === '' || cleaned === '-' || cleaned === '.') {
    return null;
  }
  const major = Number.parseFloat(cleaned);
  if (!Number.isFinite(major)) {
    return null;
  }

  return Math.round(major * 100);
}

function onInput(event: Event): void {
  raw.value = (event.target as HTMLInputElement).value;
  // An empty field means UNKNOWN, not zero.
  emit('update:modelValue', raw.value.trim() === '' ? null : toMinorUnits(raw.value));
}

/** Tidy to two decimals on blur — after the user has finished, never mid-keystroke. */
function onBlur(): void {
  const minor = toMinorUnits(raw.value);
  raw.value = minor === null ? '' : (minor / 100).toFixed(2);
}

const minMajor = computed(() => (props.minMinor === null ? undefined : props.minMinor / 100));
const maxMajor = computed(() => (props.maxMinor === null ? undefined : props.maxMinor / 100));
</script>

<template>
  <SvFormField
    :id="id"
    :label="label"
    :help="help"
    :errors="errors"
    :message="message"
    :status="status"
    :required="required"
    :disabled="disabled"
    :readonly="readonly"
  >
    <template #default="field">
      <div class="flex items-stretch">
        <!-- The currency is shown, not assumed. Decorative: the label names the amount's meaning. -->
        <span
          aria-hidden="true"
          class="inline-flex min-h-sv-control items-center rounded-l-control border border-r-0 border-sv-border-input bg-sv-surface-subtle px-3 text-sm font-medium text-sv-text-muted"
        >{{ currency }}</span>
        <input
          v-bind="field"
          type="text"
          inputmode="decimal"
          :value="raw"
          :min="minMajor"
          :max="maxMajor"
          placeholder="0.00"
          class="sv-focus-ring sv-numeric min-h-sv-control w-full rounded-r-control border border-sv-border-input bg-sv-surface-raised px-3 py-2 text-right text-sm text-sv-text read-only:bg-sv-surface-subtle disabled:border-sv-disabled-border disabled:bg-sv-disabled-bg disabled:text-sv-disabled-fg aria-[invalid=true]:border-sv-error-border"
          data-testid="sv-money-input"
          @input="onInput"
          @blur="onBlur"
        >
      </div>
    </template>
  </SvFormField>
</template>
