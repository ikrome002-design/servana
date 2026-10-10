<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { useCan } from '@/composables/useCan';
import { SvIconChevronDown, SvIconClose, SvIconLocked, SvIconSuccess, SvIconWarning } from '@/design-system/icons';
import { smsExclusionLabels, usePersonnelSmsStore } from '@/stores/personnelSmsStore';
import { nairobiDate, personnelStatusTone } from '@/utils/personnelStatus';

/**
 * Client SMS — Personnel own-scope (Plan §64; ADR-010; Phase 21S; UI-14 composer/history split).
 *
 * Everything here is the ACTING personnel member's own data, derived server-side from their
 * membership: there is no staff selector and the browser never sends a staff reference.
 *
 * CONTACT PROTECTION (the defining property of this screen):
 *   - client contact is rendered ONLY as the server-supplied `phone_masked`;
 *   - there is NO export, download, print, copy-to-clipboard or "select all numbers" control, and
 *     none may be added — Plan §19.4 makes personnel contact export non-overridable;
 *   - nothing is written to localStorage/sessionStorage and no contact reaches a URL (a `?client=`
 *     preselection carries only the opaque client ULID, and the server re-checks it at preview).
 *
 * The screen computes NO authoritative value. The live character count is a typing aid only;
 * segments, eligibility and cost all come from the server preview, and Send appears only after a
 * successful preview.
 */
const store = usePersonnelSmsStore();
const route = useRoute();
const { can } = useCan();
const props = withDefaults(defineProps<{ mode?: 'compose' | 'history' | 'all' }>(), { mode: 'all' });
const showCompose = computed(() => props.mode !== 'history');
const showHistory = computed(() => props.mode !== 'compose');

const canRead = computed(() => can('personnel.my_served_clients.view'));
const canSend = computed(() => can('personnel.my_sms.send'));

/* ---------------------------------------------------------------- a11y */
const statusRegion = ref<HTMLElement | null>(null);
const statusMessage = ref('');
const lastFocused = ref<HTMLElement | null>(null);
const confirmOpen = ref(false);
const justSent = ref(false);
const openCampaign = ref<string | null>(null);

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
onMounted(async () => {
  if (!canRead.value) return;
  if (canSend.value && showHistory.value) void store.fetchCampaigns();
  if (!showCompose.value) return;
  await store.fetchClients();
  const preselect = typeof route.query.client === 'string' ? route.query.client : null;
  if (preselect && store.clients.some((client) => client.id === preselect) && !store.isSelected(preselect)) {
    store.toggle(preselect);
  }
});

const clientsState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loadingClients) return 'loading';
  if (store.error !== null && store.clients.length === 0) return 'error';
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
  justSent.value = false;
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
  justSent.value = sent;
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

async function toggleCampaign(id: string): Promise<void> {
  if (openCampaign.value === id) {
    openCampaign.value = null;
    return;
  }
  openCampaign.value = id;
  await store.fetchRecipients(id);
}
</script>

<template>
  <section class="mx-auto max-w-7xl">
    <SvTonalPageHeader
      :title="props.mode === 'history' ? 'Message history' : 'SMS composer'"
      eyebrow="My clients"
      :tone="props.mode === 'history' ? 'teal' : 'warm'"
      :description="props.mode === 'history'
        ? 'Your own in-platform SMS sends and the outcomes Servana recorded. No raw contact or export is available.'
        : 'Send inside Servana to clients you personally served. Contacts stay masked, and you confirm the branch billing notice before anything is sent.'"
    >
      <template #actions>
        <RouterLink
          v-if="props.mode === 'history'"
          :to="{ name: 'personnel.messages-compose' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center justify-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          Compose a message
        </RouterLink>
        <RouterLink
          v-else-if="props.mode === 'compose'"
          :to="{ name: 'personnel.messages' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center justify-center rounded-control border border-sv-border-input bg-sv-surface-raised px-4 text-sm font-semibold text-sv-text hover:bg-sv-surface-subtle"
        >
          Message history
        </RouterLink>
      </template>
    </SvTonalPageHeader>

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
      data-testid="sms-forbidden"
    >
      <SvCard padding="md">
        <p class="text-sm text-sv-text">
          You do not have access to client SMS. Ask your administrator if you need it.
        </p>
      </SvCard>
    </div>

    <template v-else>
      <!-- Entitlement / billing blocks: safe and actionable, never a raw error code. -->
      <div
        v-if="store.blocked"
        class="mb-5 flex items-start gap-3 rounded-card border border-sv-warning-border bg-sv-warning-bg p-4 text-sv-warning-fg"
        data-testid="sms-blocked"
      >
        <SvIconWarning
          aria-hidden="true"
          class="mt-0.5 h-5 w-5 shrink-0"
        />
        <div>
          <p class="text-sm font-semibold">
            Sending is unavailable
          </p>
          <p class="mt-1 text-sm">
            {{ store.blocked }}
          </p>
        </div>
      </div>

      <div
        v-if="showCompose"
        class="grid gap-6 lg:grid-cols-[0.85fr_1.15fr]"
      >
        <!-- ------------------------------------------------ served clients -->
        <section
          aria-labelledby="sms-clients-heading"
          class="self-start rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
        >
          <div class="flex items-center justify-between gap-2">
            <h2
              id="sms-clients-heading"
              class="font-display text-lg font-bold text-sv-text-heading"
            >
              Choose recipients
            </h2>
            <span class="rounded-full bg-sv-selected-bg px-3 py-1 text-xs font-semibold text-sv-selected-fg">{{ store.selectedCount }} selected</span>
          </div>
          <p class="mt-1 text-xs leading-5 text-sv-text-muted">
            Only clients you personally served. Consent is checked again by Servana before sending.
          </p>

          <form
            class="mt-4 flex flex-wrap items-end gap-2"
            role="search"
            @submit.prevent="runSearch"
          >
            <SvTextInput
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
              class="mb-5"
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
              class="flex max-h-[28rem] flex-col gap-2 overflow-y-auto pr-1"
              aria-label="My served clients"
              data-testid="sms-client-list"
            >
              <li
                v-for="client in store.clients"
                :key="client.id"
              >
                <label
                  class="flex min-h-sv-touch cursor-pointer items-center gap-3 rounded-control border px-3 py-2 transition-colors duration-sv-fast focus-within:ring-2 focus-within:ring-sv-focus motion-reduce:transition-none"
                  :class="store.isSelected(client.id) ? 'border-sv-brand bg-sv-selected-bg' : 'border-sv-border hover:border-sv-border-strong'"
                  :data-testid="`sms-client-${client.id}`"
                >
                  <input
                    type="checkbox"
                    class="size-5 shrink-0 accent-sv-brand"
                    :checked="store.isSelected(client.id)"
                    :aria-label="`Select ${client.full_name}`"
                    @change="store.toggle(client.id)"
                  >
                  <!-- Masked contact ONLY. The full number is never sent to the browser. -->
                  <SvMaskedIdentity
                    size="sm"
                    :name="client.full_name"
                    :phone-masked="client.phone_masked"
                  />
                </label>
              </li>
            </ul>
          </SvStateBoundary>
        </section>

        <!-- ------------------------------------------------------ composer -->
        <section
          aria-labelledby="sms-composer-heading"
          class="sv-composer rounded-card border border-sv-border p-5 shadow-raised md:p-6"
        >
          <h2
            id="sms-composer-heading"
            class="font-display text-lg font-bold text-sv-text-heading"
          >
            Compose
          </h2>

          <div
            class="mt-3 flex flex-wrap items-center gap-2"
            data-testid="sms-selected-chips"
          >
            <span
              v-if="store.selectedCount === 0"
              class="text-sm text-sv-text-muted"
            >No recipients selected yet.</span>
            <span
              v-for="client in store.selectedClients"
              :key="client.id"
              class="inline-flex items-center gap-1 rounded-full border border-sv-border bg-sv-surface-raised py-1 pl-3 pr-1 text-xs font-medium text-sv-text"
            >
              {{ client.full_name }}
              <button
                type="button"
                class="sv-focus-ring inline-flex h-7 w-7 items-center justify-center rounded-full text-sv-text-muted hover:bg-sv-surface-subtle hover:text-sv-text"
                :aria-label="`Remove ${client.full_name}`"
                @click="store.toggle(client.id)"
              >
                <SvIconClose
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </button>
            </span>
          </div>

          <p
            v-if="store.preview"
            class="mt-2 text-xs text-sv-text-muted"
            data-testid="sms-batch-limit"
          >
            {{ store.selectedCount }} of {{ store.preview.max_recipients }} maximum recipients.
          </p>

          <SvTextArea
            id="sms-body"
            class="mt-4"
            label="Message"
            :model-value="store.messageBody"
            :rows="5"
            data-testid="sms-body"
            @update:model-value="(value: string) => store.setMessageBody(value)"
          />
          <p class="mt-1 flex flex-wrap justify-between gap-2 text-xs text-sv-text-muted">
            <span>{{ store.messageBody.length }} characters typed</span>
            <span>Segments and cost are calculated by Servana when you preview.</span>
          </p>

          <!-- Server-computed metrics ONLY; the browser derives nothing. -->
          <div
            v-if="store.preview"
            class="mt-5 grid grid-cols-2 gap-3"
            data-testid="sms-preview"
          >
            <SvStatTile
              label="Recipients"
              tone="green"
            >
              <span data-testid="sms-preview-recipients">
                {{ store.preview.recipient_count }}
              </span>
            </SvStatTile>
            <SvStatTile
              label="Excluded"
              tone="clay"
            >
              <span data-testid="sms-preview-excluded">
                {{ store.preview.excluded_count }}
              </span>
            </SvStatTile>
            <SvStatTile
              label="Segments"
              tone="teal"
              :hint="`${store.preview.message_character_count} characters`"
            >
              <span data-testid="sms-preview-segments">
                {{ store.preview.segment_count }}
              </span>
            </SvStatTile>
            <SvStatTile
              label="Estimated cost"
              tone="brand"
            >
              <span data-testid="sms-preview-cost">
                {{ store.preview.estimated_cost.formatted }}
              </span>
            </SvStatTile>
          </div>

          <ul
            v-if="exclusionRows.length > 0"
            class="mt-3 flex flex-col gap-1 rounded-control bg-sv-surface-subtle p-3 text-xs text-sv-text-secondary"
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

          <div
            v-if="store.preview"
            class="mt-4 flex items-start gap-3 rounded-control border border-sv-warning-border bg-sv-warning-bg p-4 text-sv-warning-fg"
          >
            <SvIconWarning
              aria-hidden="true"
              class="mt-0.5 h-5 w-5 shrink-0"
            />
            <p
              class="text-sm font-semibold"
              data-testid="sms-billing-notice"
            >
              {{ store.preview.billing_notice }}
            </p>
          </div>

          <p
            v-if="store.error"
            role="alert"
            class="mt-3 text-sm text-sv-error-fg"
            data-testid="sms-error"
          >
            {{ store.error }}
          </p>

          <div class="mt-5 flex flex-wrap gap-2">
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

          <div
            v-if="justSent && store.lastCampaign && props.mode !== 'all'"
            class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-control border border-sv-success-border bg-sv-success-bg p-4 text-sv-success-fg"
            data-testid="sms-confirmation"
          >
            <p class="flex items-center gap-2 text-sm font-semibold">
              <SvIconSuccess
                aria-hidden="true"
                class="h-5 w-5 shrink-0"
              />
              Sent to {{ store.lastCampaign.recipient_count }} {{ store.lastCampaign.recipient_count === 1 ? 'client' : 'clients' }} through Servana.
            </p>
            <span data-testid="sms-campaign-status">{{ store.lastCampaign.status_label }}</span>
          </div>
        </section>
      </div>

      <!-- ------------------------------------------------------- campaigns -->
      <section
        v-if="canSend && showHistory"
        :class="showCompose ? 'mt-6' : ''"
        aria-labelledby="sms-campaigns-heading"
      >
        <h2
          id="sms-campaigns-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          {{ showCompose ? 'Recent messages' : 'Sent by you' }}
        </h2>

        <p
          v-if="store.campaigns.length === 0"
          class="rounded-card border border-dashed border-sv-border-strong p-5 text-sm text-sv-text-muted"
        >
          You haven’t sent any messages yet.
        </p>

        <ul
          v-else
          class="flex flex-col gap-3"
          aria-label="Recent messages"
          data-testid="sms-campaign-list"
        >
          <li
            v-for="campaign in store.campaigns"
            :key="campaign.id"
            class="rounded-card border border-sv-border bg-sv-surface-raised shadow-card"
            :data-testid="`sms-campaign-${campaign.id}`"
          >
            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 p-4 md:grid-cols-[1.2fr_0.6fr_0.6fr_0.8fr_auto] md:p-5">
              <div class="min-w-0">
                <p class="font-semibold text-sv-text-heading">
                  {{ campaign.recipient_count }} {{ campaign.recipient_count === 1 ? 'recipient' : 'recipients' }}
                </p>
                <p class="mt-0.5 text-xs text-sv-text-muted">
                  {{ nairobiDate(campaign.confirmed_at ?? campaign.created_at, true) }}
                </p>
              </div>
              <p class="hidden text-sm text-sv-text-secondary md:block">
                <span class="block text-xs text-sv-text-muted">Segments</span>{{ campaign.segment_count }}
              </p>
              <p class="hidden text-sm text-sv-text-secondary md:block">
                <span class="block text-xs text-sv-text-muted">{{ campaign.final_cost ? 'Charge' : 'Estimate' }}</span>{{ (campaign.final_cost ?? campaign.estimated_cost).formatted }}
              </p>
              <span
                class="justify-self-end md:justify-self-start"
                data-testid="sms-campaign-status"
              >
                <SvStatusBadge
                  size="sm"
                  :label="campaign.status_label"
                  :tone="personnelStatusTone(campaign.status)"
                  sr-prefix=""
                />
              </span>
              <button
                type="button"
                class="sv-focus-ring col-span-2 inline-flex min-h-sv-touch items-center gap-1 justify-self-start rounded-control text-sm font-semibold text-sv-link md:col-span-1"
                :aria-expanded="openCampaign === campaign.id"
                :aria-controls="`sms-outcomes-${campaign.id}`"
                @click="toggleCampaign(campaign.id)"
              >
                {{ openCampaign === campaign.id ? 'Hide outcomes' : 'Outcomes' }}
                <SvIconChevronDown
                  aria-hidden="true"
                  class="h-4 w-4 transition-transform duration-sv-fast motion-reduce:transition-none"
                  :class="openCampaign === campaign.id ? 'rotate-180' : ''"
                />
              </button>
            </div>
            <div
              v-if="openCampaign === campaign.id"
              :id="`sms-outcomes-${campaign.id}`"
              class="border-t border-sv-border px-4 py-4 md:px-5"
            >
              <p class="mb-3 flex items-center gap-2 text-xs text-sv-text-muted md:hidden">
                {{ campaign.segment_count }} segments · {{ (campaign.final_cost ?? campaign.estimated_cost).formatted }}
              </p>
              <p
                v-if="store.recipients.length === 0"
                class="text-sm text-sv-text-muted"
              >
                No recipient outcomes are recorded yet.
              </p>
              <ul
                v-else
                class="grid gap-2 md:grid-cols-2"
                aria-label="Recipient outcomes"
              >
                <li
                  v-for="recipient in store.recipients"
                  :key="recipient.id"
                  class="flex items-center justify-between gap-3 rounded-control bg-sv-surface-subtle px-3 py-2"
                >
                  <SvMaskedIdentity
                    size="sm"
                    :name="recipient.client?.full_name ?? 'Recipient'"
                    :phone-masked="recipient.phone_masked"
                  />
                  <SvStatusBadge
                    size="sm"
                    sr-prefix="Delivery:"
                    :label="recipient.delivery_status_label"
                    :tone="personnelStatusTone(recipient.delivery_status)"
                  />
                </li>
              </ul>
            </div>
          </li>
        </ul>
        <p class="mt-4 flex items-center gap-2 text-xs text-sv-text-muted">
          <SvIconLocked
            aria-hidden="true"
            class="h-4 w-4"
          />
          Outcomes show masked contact only. Servana keeps the audit trail; nothing here can be exported.
        </p>
      </section>
    </template>

    <!-- ------------------------------------------------ confirmation modal -->
    <SvDialog
      v-if="showCompose"
      :open="confirmOpen"
      title="Send this message?"
      @close="closeConfirm"
    >
      <div data-testid="sms-confirm-modal">
        <dl
          v-if="store.preview"
          class="grid grid-cols-2 gap-3 text-sm"
        >
          <div class="rounded-control bg-sv-success-bg p-3 text-sv-success-fg">
            <dt class="text-xs">
              Eligible recipients
            </dt>
            <dd class="mt-1 font-display text-xl font-bold">
              {{ store.preview.recipient_count }}
            </dd>
          </div>
          <div class="rounded-control bg-sv-surface-subtle p-3 text-sv-text">
            <dt class="text-xs text-sv-text-muted">
              Excluded
            </dt>
            <dd class="mt-1 font-display text-xl font-bold">
              {{ store.preview.excluded_count }}
            </dd>
          </div>
          <div class="rounded-control bg-sv-surface-subtle p-3 text-sv-text">
            <dt class="text-xs text-sv-text-muted">
              Segments each
            </dt>
            <dd class="mt-1 font-display text-xl font-bold">
              {{ store.preview.segment_count }}
            </dd>
          </div>
          <div class="rounded-control bg-sv-selected-bg p-3 text-sv-selected-fg">
            <dt class="text-xs">
              Estimated charge
            </dt>
            <dd class="mt-1 font-display text-xl font-bold">
              {{ store.preview.estimated_cost.formatted }}
            </dd>
          </div>
        </dl>
        <p class="mt-4 rounded-control border border-sv-warning-border bg-sv-warning-bg p-3 text-sm font-semibold text-sv-warning-fg">
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
    </SvDialog>
  </section>
</template>

<style scoped>
.sv-composer {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-primary) 12%, transparent), transparent 45%),
    var(--sv-color-surface-raised);
}
</style>
