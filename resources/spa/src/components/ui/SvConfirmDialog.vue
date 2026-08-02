<script setup lang="ts">
/**
 * SvConfirmDialog — confirm before an irreversible action (Phase UI-04; UI/UX plan §9.5).
 *
 * Built on `SvDialog`, so it inherits one focus trap rather than adding a second.
 *
 * The safety properties, each of which exists because of a specific way confirmations go wrong:
 *
 *  - **Initial focus is CANCEL for a destructive action.** Focusing "Delete" means a stray Enter
 *    or Space destroys the record. Cancel is the safe default; the user must move to confirm.
 *  - **Persistent while loading.** Escape and outside-click are disabled during submission, so a
 *    dialog cannot disappear while its request is still in flight and leave the outcome unknown.
 *  - **Confirm is disabled while loading**, so a double-click cannot submit twice.
 *  - **A server error stays visible.** The dialog does not close on failure — closing would
 *    discard the only place the user can read what went wrong.
 */
import { computed, nextTick, ref, watch } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDialog from '@/components/ui/SvDialog.vue';

const props = withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    /** What will happen. Should name the consequence, not just ask "are you sure?". */
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Destructive actions get the danger treatment AND cancel-first focus. */
    destructive?: boolean;
    loading?: boolean;
    /** Server error text. Kept visible; the dialog does not close on failure. */
    error?: string | null;
  }>(),
  {
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    destructive: false,
    loading: false,
    error: null,
  },
);

const emit = defineEmits<{ confirm: []; cancel: [] }>();

const cancelRef = ref<InstanceType<typeof SvButton> | null>(null);

/**
 * Move focus to Cancel for a destructive dialog once it is open.
 *
 * `SvDialog`'s trap focuses the first focusable element, which is the close control; for a
 * destructive confirmation the deliberate safe target is Cancel.
 */
watch(
  () => props.open,
  async (open) => {
    if (!open || !props.destructive) {
      return;
    }
    await nextTick();
    (cancelRef.value?.$el as HTMLElement | undefined)?.focus();
  },
);

/** While a request is in flight the dialog must not vanish. */
const persistent = computed(() => props.loading);

function onConfirm(): void {
  if (props.loading) {
    return;
  }
  emit('confirm');
}
</script>

<template>
  <SvDialog
    :open="open"
    :title="title"
    size="sm"
    :persistent="persistent"
    :hide-close-button="loading"
    @close="emit('cancel')"
  >
    <p class="text-sm text-sv-text">
      {{ message }}
    </p>

    <SvAlert
      v-if="error !== null"
      severity="error"
      class="mt-4"
      data-testid="sv-confirm-error"
    >
      {{ error }}
    </SvAlert>

    <template #footer>
      <SvButton
        ref="cancelRef"
        variant="secondary"
        :disabled="loading"
        data-testid="sv-confirm-cancel"
        @click="emit('cancel')"
      >
        {{ cancelLabel }}
      </SvButton>
      <SvButton
        :variant="destructive ? 'destructive' : 'primary'"
        :loading="loading"
        data-testid="sv-confirm-accept"
        @click="onConfirm"
      >
        {{ confirmLabel }}
      </SvButton>
    </template>
  </SvDialog>
</template>
