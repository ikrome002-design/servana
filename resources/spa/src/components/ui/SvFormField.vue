<script setup lang="ts">
/**
 * SvFormField — the SINGLE owner of label/help/error association (Phase UI-04; UI/UX plan §14.1).
 *
 * Before UI-04 each input invented its own association strategy: `SvInput` built a `describedby`
 * from a hint id and an error id, `SvSelect` and `SvTextarea` handled only the error id, and none
 * of them agreed on what happens when both a hint and a warning are present. Three strategies for
 * one contract is how a field ends up announcing its error to nobody.
 *
 * This component owns, once:
 *  - the control id;
 *  - the help id and the error id;
 *  - the required marker;
 *  - the composed `aria-describedby` (help first, then message — reading order matters);
 *  - `aria-invalid`;
 *  - the reserved message row, so a field does not change height when an error appears and push
 *    the submit button out from under the user's finger.
 *
 * The control is supplied through a scoped slot receiving exactly the attributes it must bind, so
 * an input physically cannot wire itself up differently.
 */
import { computed } from 'vue';

export type SvFieldStatus = 'default' | 'error' | 'warning' | 'success';

const props = withDefaults(
  defineProps<{
    /** Unique control id. Callers must keep it unique on the page; ids are never auto-invented. */
    id: string;
    /** Persistent visible label. A placeholder is never a substitute (UI/UX plan §14.2). */
    label: string;
    /** Supporting guidance, announced before any error. */
    help?: string;
    /**
     * Validation messages. The FIRST is announced; the rest remain visible. Server-side messages
     * are passed straight through — the component never rewrites or suppresses them.
     */
    errors?: string[];
    /** Non-error status message, e.g. a warning or a success confirmation. */
    message?: string;
    status?: SvFieldStatus;
    required?: boolean;
    disabled?: boolean;
    readonly?: boolean;
    /** Hide the label visually while keeping it for assistive technology. Use sparingly. */
    labelHidden?: boolean;
  }>(),
  {
    help: undefined,
    errors: () => [],
    message: undefined,
    status: 'default',
    required: false,
    disabled: false,
    readonly: false,
    labelHidden: false,
  },
);

const helpId = computed(() => `${props.id}-help`);
const messageId = computed(() => `${props.id}-message`);

const hasError = computed(() => props.errors.length > 0);
const resolvedStatus = computed<SvFieldStatus>(() => (hasError.value ? 'error' : props.status));

const visibleMessage = computed(() => (hasError.value ? props.errors[0] : props.message));

/** Help first, then the message: assistive technology reads them in DOM-reference order. */
const describedBy = computed(() => {
  const ids: string[] = [];
  if (props.help !== undefined && props.help !== '') {
    ids.push(helpId.value);
  }
  if (visibleMessage.value !== undefined && visibleMessage.value !== '') {
    ids.push(messageId.value);
  }

  return ids.length > 0 ? ids.join(' ') : undefined;
});

/**
 * Everything a control must bind. Exposed as a scoped slot so no input can invent its own wiring.
 */
const controlAttrs = computed(() => ({
  id: props.id,
  'aria-describedby': describedBy.value,
  'aria-invalid': hasError.value ? true : undefined,
  'aria-required': props.required ? true : undefined,
  required: props.required,
  disabled: props.disabled,
  readonly: props.readonly,
}));
</script>

<template>
  <div
    class="flex flex-col gap-1"
    :data-status="resolvedStatus"
    data-testid="sv-form-field"
  >
    <label
      :for="id"
      class="text-sm font-medium text-sv-text"
      :class="labelHidden ? 'sr-only' : ''"
    >
      {{ label }}
      <!--
        The asterisk is decorative; `aria-required` on the control is what announces the
        requirement. Announcing "star" would add noise without adding information.
      -->
      <span
        v-if="required"
        aria-hidden="true"
        class="ml-0.5 text-sv-error-fg"
      >*</span>
    </label>

    <p
      v-if="help"
      :id="helpId"
      class="text-xs text-sv-text-muted"
    >
      {{ help }}
    </p>

    <slot v-bind="controlAttrs" />

    <!--
      The message row is ALWAYS reserved, so a field does not grow when an error appears and shove
      the submit button out from under the user's finger.
    -->
    <p
      :id="messageId"
      class="min-h-4 text-xs"
      :class="{
        'text-sv-error-fg': resolvedStatus === 'error',
        'text-sv-warning-fg': resolvedStatus === 'warning',
        'text-sv-success-fg': resolvedStatus === 'success',
        'text-sv-text-muted': resolvedStatus === 'default',
      }"
      :role="hasError ? 'alert' : undefined"
      data-testid="sv-form-field-message"
    >
      {{ visibleMessage }}
    </p>

    <!-- Additional server messages remain visible; only the first is announced. -->
    <ul
      v-if="errors.length > 1"
      class="list-disc pl-4 text-xs text-sv-error-fg"
    >
      <li
        v-for="extra in errors.slice(1)"
        :key="extra"
      >
        {{ extra }}
      </li>
    </ul>
  </div>
</template>
