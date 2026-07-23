<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useCan } from '@/composables/useCan';
import { smsExclusionLabels, usePersonnelSmsStore } from '@/stores/personnelSmsStore';

/**
 * Client SMS — Personnel own-scope (Plan §64; ADR-010; Phase 21S).
 *
 * Everything here is the ACTING personnel member's own data, derived server-side from their
 * membership: there is no staff selector and the browser never sends a staff reference.
 *
 * CONTACT PROTECTION (the defining property of this screen):
 *   - client contact is rendered ONLY as the server-supplied `phone_masked`;
 *   - there is NO export, download, print, copy-to-clipboard or "select all numbers" control, and
 *     none may be added — Plan §19.4 makes personnel contact export non-overridable;
 *   - nothing is written to localStorage/sessionStorage and no contact reaches a URL.
 *
 * The screen computes NO authoritative value. Character count, segment count, eligibility and cost
 * all come from the server preview, and the send button only appears after a successful preview.
 */
const store = usePersonnelSmsStore();
const { can } = useCan();

const canRead = computed(() => can('personnel.my_served_clients.view'));
const canSend = computed(() => can('personnel.my_sms.send'));

/* ---------------------------------------------------------------- a11y */
const statusRegion = ref<HTMLElement | null>(null);
const statusMessage = ref('');
const lastFocused = ref<HTMLElement | null>(null);
const confirmOpen = ref(false);

function rememberFocus(): void {
  lastFocused.value = document.activeElement instanceof HTMLElement ? document.activeElement : null;
}

function restoreFocus(): void {
  void nextTick(() => lastFocused.value?.focus());
}

async function announce(message: string): Promise<void> {
  statusMessage.value = message;
  await nextTick();
}

/* ---------------------------------------------------------------- load */
onMounted(() => {
  if (!canRead.value) return;
  void store.fetchClients();
  if (canSend.value) void store.fetchCampaigns();
});

const clientsState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loadingClients) return 'loading';
  if (store.error !== null) return 'error';
  if (store.clients.length === 0) return 'empty';
  return 'success';
});

const exclusionRows = computed(() =>
  Object.entries(store.preview?.excluded_reasons ?? {}).map(([code, count]) => ({
    code,
    count,
    label: smsExclusionLabels[code] ?? 'Not eligible',
  })),
);

async function runPreview(): Promise<void> {
  await store.fetchPreview();

  if (store.preview !== null) {
    await announce(
      `${store.preview.recipient_count} recipients, ${store.preview.segment_count} segments, ${store.preview.estimated_cost.formatted}.`,
    );
    return;
  }

  await announce(store.blocked ?? store.error ?? 'Preview failed.');
}

function openConfirm(): void {
  rememberFocus();
  confirmOpen.value = true;
}

function closeConfirm(): void {
  confirmOpen.value = false;
  restoreFocus();
}

async function confirmSend(): Promise<void> {
  const sent = await store.send();
  confirmOpen.value = false;
  restoreFocus();

  await announce(
    sent
      ? `Message queued to ${store.lastCampaign?.recipient_count ?? 0} recipients.`
      : (store.blocked ?? store.error ?? 'Send failed.'),
  );
}

async function runSearch(): Promise<void> {
  await store.fetchClients();
  await announce(`${store.clients.length} served clients found.`);
}
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Client SMS
    </h1>
    <p class="mt-1 max-w-2xl text-sm text-text-muted">
      Send a message to clients you have personally served. Contact details stay masked — Servana
      never exports client contacts.
    </p>

    <!-- Live region for preview + send outcomes (a11y). -->
    <p
      ref="statusRegion"
      class="sr-only"
      role="status"
      aria-live="polite"
      tabindex="-1"
      data-testid="sms-status-region"
    >
      {{ statusMessage }}
    </p>

    <div
      v-if="!canRead"
      class="mt-6"
      data-testid="sms-forbidden"
    >
      <SvCard padding="md">
        <p class="text-sm text-text">
          You do not have access to client SMS. Ask your administrator if you need it.
        </p>
      </SvCard>
    </div>

    <template v-else>
      <!-- Entitlement / billing blocks: safe and actionable, never a raw error code. -->
      <SvCard
        v-if="store.blocked"
        class="mt-6"
        padding="md"
        data-testid="sms-blocked"
      >
        <p class="text-sm font-semibold text-heading">
          Sending is unavailable
        </p>
        <p class="mt-1 text-sm text-text-muted">
          {{ store.blocked }}
        </p>
      </SvCard>

      <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <!-- ------------------------------------------------ served clients -->
        <SvCard
          as="section"
          padding="md"
          aria-labelledby="sms-clients-heading"
        >
          <h2
            id="sms-clients-heading"
            class="font-display text-base font-semibold text-heading"
          >
            My served clients
          </h2>

          <form
            class="mt-3 flex flex-wrap items-end gap-2"
            @submit.prevent="runSearch"
          >
            <SvInput
              id="sms-search"
              v-model="store.search"
              label="Search by name"
              type="search"
              autocomplete="off"
              class="min-w-[12rem] flex-1"
              data-testid="sms-search"
            />
            <SvButton
              type="submit"
              variant="secondary"
              data-testid="sms-search-submit"
            >
              Search
            </SvButton>
          </form>

          <SvStateBoundary
            class="mt-4"
            :state="clientsState"
            empty-message="You have no completed sessions with any client yet."
            error-message="We couldn’t load your served clients."
            @retry="() => store.fetchClients()"
          >
            <ul
              class="flex flex-col gap-2"
              aria-label="My served clients"
              data-testid="sms-client-list"
            >
              <li
                v-for="client in store.clients"
                :key="client.id"
              >
                <label
                  class="flex min-h-[44px] cursor-pointer items-center gap-3 rounded-lg border border-border px-3 py-2 focus-within:ring-2 focus-within:ring-focus"
                  :data-testid="`sms-client-${client.id}`"
                >
                  <input
                    type="checkbox"
                    class="size-5"
                    :checked="store.isSelected(client.id)"
                    :aria-label="`Select ${client.full_name}`"
                    @change="store.toggle(client.id)"
                  >
                  <span class="min-w-0">
                    <span class="block truncate text-sm font-medium text-text">{{ client.full_name }}</span>
                    <!-- Masked contact ONLY. The full number is never sent to the browser. -->
                    <span class="block text-xs text-text-muted">{{ client.phone_masked }}</span>
                  </span>
                </label>
              </li>
            </ul>
          </SvStateBoundary>
        </SvCard>

        <!-- ------------------------------------------------------ composer -->
        <SvCard
          as="section"
          padding="md"
          aria-labelledby="sms-composer-heading"
        >
          <h2
            id="sms-composer-heading"
            class="font-display text-base font-semibold text-heading"
          >
            Compose
          </h2>

          <div
            class="mt-3 flex flex-wrap items-center gap-2"
            data-testid="sms-selected-chips"
          >
            <span
              v-if="store.selectedCount === 0"
              class="text-sm text-text-muted"
            >No recipients selected yet.</span>
            <span
              v-for="client in store.selectedClients"
              :key="client.id"
              class="inline-flex items-center gap-2 rounded-full bg-surface-alt px-3 py-1 text-xs text-text"
            >
              {{ client.full_name }}
              <button
                type="button"
                class="min-h-[24px] min-w-[24px] rounded-full px-1 text-text-muted hover:text-text"
                :aria-label="`Remove ${client.full_name}`"
                @click="store.toggle(client.id)"
              >×</button>
            </span>
          </div>

          <p
            v-if="store.preview"
            class="mt-2 text-xs text-text-muted"
            data-testid="sms-batch-limit"
          >
            {{ store.selectedCount }} of {{ store.preview.max_recipients }} maximum recipients.
          </p>

          <SvTextarea
            id="sms-body"
            class="mt-4"
            label="Message"
            :model-value="store.messageBody"
            :rows="4"
            data-testid="sms-body"
            @update:model-value="(value: string) => store.setMessageBody(value)"
          />

          <!-- Server-computed metrics ONLY; the browser derives nothing. -->
          <dl
            v-if="store.preview"
            class="mt-4 grid grid-cols-2 gap-3 text-sm"
            data-testid="sms-preview"
          >
            <div>
              <dt class="text-text-muted">
                Recipients
              </dt>
              <dd
                class="font-semibold text-heading"
                data-testid="sms-preview-recipients"
              >
                {{ store.preview.recipient_count }}
              </dd>
            </div>
            <div>
              <dt class="text-text-muted">
                Excluded
              </dt>
              <dd
                class="font-semibold text-heading"
                data-testid="sms-preview-excluded"
              >
                {{ store.preview.excluded_count }}
              </dd>
            </div>
            <div>
              <dt class="text-text-muted">
                Characters
              </dt>
              <dd class="font-semibold text-heading">
                {{ store.preview.message_character_count }}
              </dd>
            </div>
            <div>
              <dt class="text-text-muted">
                Segments
              </dt>
              <dd
                class="font-semibold text-heading"
                data-testid="sms-preview-segments"
              >
                {{ store.preview.segment_count }}
              </dd>
            </div>
            <div class="col-span-2">
              <dt class="text-text-muted">
                Estimated cost
              </dt>
              <dd
                class="font-semibold text-heading"
                data-testid="sms-preview-cost"
              >
                {{ store.preview.estimated_cost.formatted }}
              </dd>
            </div>
          </dl>

          <ul
            v-if="exclusionRows.length > 0"
            class="mt-3 flex flex-col gap-1 text-xs text-text-muted"
            aria-label="Why some clients were excluded"
            data-testid="sms-exclusions"
          >
            <li
              v-for="row in exclusionRows"
              :key="row.code"
            >
              {{ row.label }} — {{ row.count }}
            </li>
          </ul>

          <p
            v-if="store.preview"
            class="mt-3 text-xs text-text-muted"
            data-testid="sms-billing-notice"
          >
            {{ store.preview.billing_notice }}
          </p>

          <p
            v-if="store.error"
            class="mt-3 text-sm text-danger"
            data-testid="sms-error"
          >
            {{ store.error }}
          </p>

          <div class="mt-4 flex flex-wrap gap-2">
            <SvButton
              type="button"
              variant="secondary"
              :disabled="!store.canPreview"
              data-testid="sms-preview-button"
              @click="runPreview"
            >
              Preview
            </SvButton>
            <!-- Sending is only offered after a successful preview (Plan §64). -->
            <SvButton
              v-if="canSend && store.canSend"
              type="button"
              data-testid="sms-send-button"
              @click="openConfirm"
            >
              Send message
            </SvButton>
          </div>
        </SvCard>
      </div>

      <!-- ------------------------------------------------------- campaigns -->
      <SvCard
        v-if="canSend"
        as="section"
        class="mt-6"
        padding="md"
        aria-labelledby="sms-campaigns-heading"
      >
        <h2
          id="sms-campaigns-heading"
          class="font-display text-base font-semibold text-heading"
        >
          Recent messages
        </h2>

        <p
          v-if="store.campaigns.length === 0"
          class="mt-2 text-sm text-text-muted"
        >
          You haven’t sent any messages yet.
        </p>

        <ul
          v-else
          class="mt-3 flex flex-col gap-2"
          aria-label="Recent messages"
          data-testid="sms-campaign-list"
        >
          <li
            v-for="campaign in store.campaigns"
            :key="campaign.id"
            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border px-3 py-2"
            :data-testid="`sms-campaign-${campaign.id}`"
          >
            <span class="text-sm text-text">
              {{ campaign.recipient_count }} recipients ·
              {{ (campaign.final_cost ?? campaign.estimated_cost).formatted }}
            </span>
            <span
              class="rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
              data-testid="sms-campaign-status"
            >{{ campaign.status_label }}</span>
          </li>
        </ul>
      </SvCard>
    </template>

    <!-- ------------------------------------------------ confirmation modal -->
    <SvModal
      :open="confirmOpen"
      title="Send this message?"
      @close="closeConfirm"
    >
      <div data-testid="sms-confirm-modal">
        <p
          v-if="store.preview"
          class="text-sm text-text"
        >
          This sends to {{ store.preview.recipient_count }} clients at
          {{ store.preview.segment_count }} segments each, for
          {{ store.preview.estimated_cost.formatted }}.
        </p>
        <p class="mt-2 text-xs text-text-muted">
          {{ store.preview?.billing_notice }}
        </p>

        <div class="mt-6 flex flex-wrap justify-end gap-2">
          <SvButton
            type="button"
            variant="secondary"
            data-testid="sms-confirm-cancel"
            @click="closeConfirm"
          >
            Cancel
          </SvButton>
          <SvButton
            type="button"
            :disabled="store.sending"
            data-testid="sms-confirm-send"
            @click="confirmSend"
          >
            Yes, send now
          </SvButton>
        </div>
      </div>
    </SvModal>
  </section>
</template>
