<script setup lang="ts">
import { ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvEmptyState from '@/components/ui/SvEmptyState.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvThemeToggle from '@/components/ui/SvThemeToggle.vue';
import { SvIconProfile, SvIconSuccess } from '@/design-system/icons';
import { useNotificationStore } from '@/stores/notificationStore';

const notifications = useNotificationStore();

const modalOpen = ref(false);
const inputValue = ref('');
const selectValue = ref('');
const textareaValue = ref('');
const inputError = ref<string[]>([]);

function triggerError(): void {
  inputError.value = ['This field is required.'];
}

function clearError(): void {
  inputError.value = [];
}

function showToast(type: 'success' | 'error' | 'warning' | 'info'): void {
  const id = notifications.addToast({ type, message: `${type} toast — auto-dismissed after 5 s.` });
  setTimeout(() => notifications.removeToast(id), 5000);
}
</script>

<template>
  <div class="min-h-screen bg-bg p-6 text-text">
    <div class="mx-auto max-w-3xl space-y-10">
      <!-- Header -->
      <div>
        <h1 class="font-display text-2xl font-extrabold text-heading">
          Design System Demo
        </h1>
        <p class="mt-1 text-sm text-text-muted">
          Phase 4 — all core UI components in light and dark themes.
        </p>
      </div>

      <!-- Theme toggle -->
      <SvCard>
        <h2 class="mb-4 font-display text-base font-bold text-heading">
          Theme
        </h2>
        <!--
          Phase UI-04 (UI01-ASSET-001): this rendered emoji sun/moon in a button label. The
          production theme control is now SvThemeToggle, which uses Heroicons and carries a real
          accessible name — the fixture exercises the SAME component the product ships.
        -->
        <!--
          Both variants appear here because this is a component gallery. `SvThemeToggle` carries a
          single `theme-toggle` test id — correct for a product page, which ships exactly one — so
          on THIS page the id is necessarily ambiguous. Each variant is wrapped rather than having
          the component's id changed: the gallery is the anomaly, not the contract.
        -->
        <div class="flex flex-wrap items-center gap-4">
          <span data-testid="theme-toggle-icon-variant">
            <SvThemeToggle />
          </span>
          <span data-testid="theme-toggle-switch-variant">
            <SvThemeToggle variant="switch" />
          </span>
        </div>
      </SvCard>

      <!-- Buttons -->
      <SvCard>
        <h2 class="mb-4 font-display text-base font-bold text-heading">
          SvButton
        </h2>
        <div class="flex flex-wrap gap-3">
          <SvButton variant="primary">
            Primary
          </SvButton>
          <SvButton variant="secondary">
            Secondary
          </SvButton>
          <SvButton variant="ghost">
            Ghost
          </SvButton>
          <SvButton variant="destructive">
            Destructive
          </SvButton>
          <SvButton
            variant="primary"
            :loading="true"
          >
            Loading
          </SvButton>
          <SvButton
            variant="primary"
            :disabled="true"
          >
            Disabled
          </SvButton>
        </div>
      </SvCard>

      <!-- Inputs -->
      <SvCard>
        <h2 class="mb-4 font-display text-base font-bold text-heading">
          SvTextInput, SvSelect, SvTextArea
        </h2>
        <div class="space-y-4">
          <SvTextInput
            id="demo-name"
            v-model="inputValue"
            label="Display name"
            placeholder="Enter your name"
            required
            :errors="inputError"
          />
          <div class="flex gap-2">
            <SvButton
              variant="secondary"
              @click="triggerError"
            >
              Show error
            </SvButton>
            <SvButton
              variant="ghost"
              @click="clearError"
            >
              Clear error
            </SvButton>
          </div>
          <SvTextInput
            id="demo-disabled"
            model-value=""
            label="Disabled input"
            placeholder="Cannot edit"
            :disabled="true"
          />
          <SvSelect
            id="demo-role"
            v-model="selectValue"
            label="Role"
            placeholder="Select a role"
            :options="[
              { value: 'admin', label: 'Merchant Admin' },
              { value: 'hr', label: 'HR' },
              { value: 'finance', label: 'Finance' },
            ]"
          />
          <SvTextArea
            id="demo-notes"
            v-model="textareaValue"
            label="Notes"
            placeholder="Add notes…"
          />
        </div>
      </SvCard>

      <!-- SvStateBoundary -->
      <SvCard>
        <h2 class="mb-4 font-display text-base font-bold text-heading">
          SvStateBoundary
        </h2>
        <div class="grid gap-6 md:grid-cols-2">
          <div>
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-text-muted">
              Loading
            </p>
            <SvStateBoundary state="loading" />
          </div>
          <div>
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-text-muted">
              Empty
            </p>
            <SvStateBoundary
              state="empty"
              empty-message="No records found."
              empty-action="Add record"
            />
          </div>
          <div>
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-text-muted">
              Error
            </p>
            <SvStateBoundary
              state="error"
              error-message="Failed to load data."
            />
          </div>
          <div>
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-text-muted">
              Success
            </p>
            <SvStateBoundary state="success">
              <p class="flex items-center gap-2 text-sm text-sv-success-fg">
                <SvIconSuccess
                  aria-hidden="true"
                  class="h-4 w-4"
                />Data loaded successfully.
              </p>
            </SvStateBoundary>
          </div>
        </div>
      </SvCard>

      <!-- SvEmptyState -->
      <SvCard>
        <h2 class="mb-4 font-display text-base font-bold text-heading">
          SvEmptyState
        </h2>
        <SvEmptyState
          title="No clients yet"
          description="Start by adding your first client to this branch."
          action-label="Add client"
          :icon="SvIconProfile"
        />
      </SvCard>

      <!-- SvDialog -->
      <SvCard>
        <h2 class="mb-4 font-display text-base font-bold text-heading">
          SvDialog
        </h2>
        <SvButton
          variant="primary"
          data-testid="open-modal"
          @click="modalOpen = true"
        >
          Open modal
        </SvButton>
        <SvDialog
          :open="modalOpen"
          title="Confirm action"
          description="This is a demonstration modal. Press Esc or click outside to close."
          @close="modalOpen = false"
        >
          <div class="flex justify-end gap-2">
            <SvButton
              variant="ghost"
              @click="modalOpen = false"
            >
              Cancel
            </SvButton>
            <SvButton
              variant="primary"
              @click="modalOpen = false"
            >
              Confirm
            </SvButton>
          </div>
        </SvDialog>
      </SvCard>

      <!-- Toasts -->
      <SvCard>
        <h2 class="mb-4 font-display text-base font-bold text-heading">
          SvToast
        </h2>
        <div class="flex flex-wrap gap-2">
          <SvButton
            variant="secondary"
            data-testid="toast-success"
            @click="showToast('success')"
          >
            Success toast
          </SvButton>
          <SvButton
            variant="secondary"
            @click="showToast('error')"
          >
            Error toast
          </SvButton>
          <SvButton
            variant="secondary"
            @click="showToast('warning')"
          >
            Warning toast
          </SvButton>
          <SvButton
            variant="secondary"
            @click="showToast('info')"
          >
            Info toast
          </SvButton>
        </div>
      </SvCard>
    </div>
  </div>
</template>
