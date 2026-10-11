<script setup lang="ts">
/**
 * AuditFlagDialog — raise a review flag on one audit event (Phase UI-15; §12.4.5; Plan §13.2).
 *
 * Creating a flag is the only Audit-role mutation near source evidence, and it writes review
 * METADATA only: the event itself is append-only and hash-chained, and the dialog says so before
 * the user commits. The server derives branch scope from the event; a wrong-branch or foreign
 * event is refused there, and the refusal is reported here without guessing why.
 */
import { computed, ref, watch } from 'vue';
import AuditSeverityBadge from '@/components/audit/AuditSeverityBadge.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { SvIconLocked } from '@/design-system/icons';
import type { AuditEventView } from '@/stores/auditEventStore';
import { useFlaggedEventStore, type FlaggedEventView } from '@/stores/flaggedEventStore';
import { humanizeAction } from '@/utils/auditPresentation';

const props = defineProps<{ event: AuditEventView | null }>();
const emit = defineEmits<{ close: []; flagged: [flag: FlaggedEventView] }>();

const flags = useFlaggedEventStore();
const note = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
const NOTE_MAX = 2000;

const noteErrors = computed(() => (note.value.length > NOTE_MAX ? [`Keep the note under ${NOTE_MAX} characters.`] : []));

watch(() => props.event, () => {
  note.value = '';
  error.value = null;
});

function describe(e: unknown): string {
  const response = (e as { response?: { status?: number; data?: { error?: { message?: string } } } }).response;
  if (response?.status === 403) return 'You can view this event but are not permitted to flag it.';
  if (response?.status === 404) return 'This event is not available in your branch scope.';
  return response?.data?.error?.message ?? 'The flag could not be created. Please try again.';
}

async function submit(): Promise<void> {
  if (!props.event || noteErrors.value.length > 0) return;
  busy.value = true;
  error.value = null;
  try {
    const trimmed = note.value.trim();
    const flag = await flags.flag({ audit_log: props.event.id, ...(trimmed !== '' ? { note: trimmed } : {}) });
    emit('flagged', flag);
  } catch (e: unknown) {
    error.value = describe(e);
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <SvDialog
    :open="event !== null"
    title="Flag this event for review"
    description="A flag opens a review record for your Audit team. The event itself stays exactly as it was recorded."
    @close="emit('close')"
  >
    <div
      v-if="event"
      class="mt-2 flex flex-col gap-4"
      data-testid="audit-flag-dialog"
    >
      <div class="rounded-control border border-sv-border bg-sv-surface-subtle p-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
          Event under review
        </p>
        <p class="mt-1 break-words font-semibold text-sv-text-heading">
          {{ humanizeAction(event.action) }}
        </p>
        <div class="mt-2">
          <AuditSeverityBadge
            :severity="event.severity"
            size="sm"
          />
        </div>
      </div>
      <p class="flex items-start gap-2 text-sm text-sv-text-secondary">
        <SvIconLocked
          aria-hidden="true"
          class="mt-0.5 h-4 w-4 shrink-0"
        />
        Review metadata only — no business record, amount or audit row is changed, and this action is itself audited.
      </p>
      <SvTextArea
        id="audit-flag-note"
        v-model="note"
        label="Why does this need review? (optional)"
        :rows="4"
        :maxlength="NOTE_MAX"
        :errors="noteErrors"
        help="Describe what looks wrong. Do not paste client contact details."
      />
      <p
        v-if="error"
        class="text-sm font-medium text-sv-error-fg"
        role="alert"
        data-testid="audit-flag-error"
      >
        {{ error }}
      </p>
    </div>
    <template #footer>
      <div class="flex flex-col-reverse gap-2 md:flex-row md:justify-end">
        <SvButton
          variant="ghost"
          @click="emit('close')"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="audit-flag-confirm"
          :loading="busy"
          loading-label="Flagging"
          :disabled="noteErrors.length > 0"
          @click="submit"
        >
          Flag for review
        </SvButton>
      </div>
    </template>
  </SvDialog>
</template>
