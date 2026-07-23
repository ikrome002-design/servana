import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Personnel bulk SMS (Plan §64; ADR-010; Phase 21S). UX state only — the API
 * (`personnel.my_served_clients.view` / `personnel.my_sms.send` + the entitlement, billing-status,
 * own-scope, consent and batch gates) is the boundary.
 *
 * CONTACT PROTECTION, enforced in this file as well as on the server:
 *   - the only contact field this store ever holds is `phone_masked`, which the server builds from
 *     the last four digits — a full number is never received, never derived and never stored;
 *   - nothing is persisted to localStorage/sessionStorage and no phone ever reaches a route param
 *     or a query string;
 *   - there is no export, download, print or clipboard action, and none may be added.
 *
 * The store computes NO authoritative value: eligibility and cost come from the server preview, and
 * `sendable` is a UX affordance only — the server re-validates everything at confirm.
 */

export type ServedClientForSms = {
  id: string;
  full_name: string;
  /** Masked display only, e.g. "••• ••• 1234". Never a full number. */
  phone_masked: string;
};

export type SmsCampaignPreview = {
  recipient_count: number;
  excluded_count: number;
  /** Safe reason code → count. Never a per-client list. */
  excluded_reasons: Record<string, number>;
  message_character_count: number;
  segment_count: number;
  requires_unicode: boolean;
  characters_remaining_in_segment: number;
  estimated_cost: { amount: number; currency: string; formatted: string };
  unit_cost_minor: number;
  max_recipients: number;
  max_message_characters: number;
  billing_notice: string;
};

export type PersonnelSmsCampaign = {
  id: string;
  status: string;
  status_label: string;
  recipient_count: number;
  message_character_count: number;
  segment_count: number;
  estimated_cost: { amount: number; currency: string; formatted: string };
  final_cost: { amount: number; currency: string; formatted: string } | null;
  failure_reason_code: string | null;
  is_cancellable: boolean;
  confirmed_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  created_at: string | null;
};

export type PersonnelSmsRecipient = {
  id: string;
  phone_masked: string;
  delivery_status: string;
  delivery_status_label: string;
  consent_status: string;
  exclusion_reason: string | null;
  client?: { id: string; full_name: string; phone_masked: string };
};

/** Contact-free explanations for the closed exclusion vocabulary (mirrors the PHP enum labels). */
export const smsExclusionLabels: Record<string, string> = {
  unknown_client: 'Not available to you',
  not_served: 'You have no completed session with this client',
  consent_opted_out: 'Client opted out of SMS',
  consent_missing: 'No SMS consent on record',
  client_archived: 'Client is archived',
  duplicate_selection: 'Selected more than once',
  campaign_cancelled: 'Campaign was cancelled before sending',
};

/** Blocking states the server can return, mapped to actionable copy. */
const blockingMessages: Record<string, string> = {
  no_active_plan: 'This merchant has no active subscription, so SMS is unavailable.',
  entitlement_absent: 'Your plan does not include SMS. Ask your administrator about upgrading.',
  entitlement_disabled: 'Your plan does not include SMS. Ask your administrator about upgrading.',
  entitlement_limit_exceeded: 'Your plan’s SMS limit has been reached.',
  billing_read_only: 'Billing is read-only, so new messages cannot be sent right now.',
};

export const usePersonnelSmsStore = defineStore('personnelSms', () => {
  const clients = ref<ServedClientForSms[]>([]);
  const selectedIds = ref<string[]>([]);
  const messageBody = ref('');
  const preview = ref<SmsCampaignPreview | null>(null);
  const campaigns = ref<PersonnelSmsCampaign[]>([]);
  const recipients = ref<PersonnelSmsRecipient[]>([]);

  const search = ref('');
  const loadingClients = ref(false);
  const previewing = ref(false);
  const sending = ref(false);
  const loadingCampaigns = ref(false);
  const error = ref<string | null>(null);
  /** Set when the server refuses on entitlement/billing grounds — actionable, not a raw code. */
  const blocked = ref<string | null>(null);
  const lastCampaign = ref<PersonnelSmsCampaign | null>(null);

  function $reset(): void {
    clients.value = [];
    selectedIds.value = [];
    messageBody.value = '';
    preview.value = null;
    campaigns.value = [];
    recipients.value = [];
    search.value = '';
    loadingClients.value = false;
    previewing.value = false;
    sending.value = false;
    loadingCampaigns.value = false;
    error.value = null;
    blocked.value = null;
    lastCampaign.value = null;
  }

  const selectedCount = computed(() => selectedIds.value.length);
  const isSelected = (id: string): boolean => selectedIds.value.includes(id);
  /** UX affordance only — the server re-validates the batch cap at preview AND confirm. */
  const atBatchLimit = computed(
    () => preview.value !== null && selectedCount.value >= preview.value.max_recipients,
  );
  const canPreview = computed(
    () => selectedCount.value > 0 && messageBody.value.trim().length > 0 && !previewing.value,
  );
  /** Sending is only offered AFTER a successful preview that found at least one recipient. */
  const canSend = computed(
    () => preview.value !== null && preview.value.recipient_count > 0 && !sending.value,
  );

  /** The selected clients, for the recipient chips. Masked contact only. */
  const selectedClients = computed(() =>
    clients.value.filter((client) => selectedIds.value.includes(client.id)),
  );

  function toggle(id: string): void {
    const index = selectedIds.value.indexOf(id);
    if (index === -1) selectedIds.value = [...selectedIds.value, id];
    else selectedIds.value = selectedIds.value.filter((selected) => selected !== id);
    // Any selection change invalidates the server's preview — never send against a stale one.
    preview.value = null;
  }

  function clearSelection(): void {
    selectedIds.value = [];
    preview.value = null;
  }

  function setMessageBody(value: string): void {
    messageBody.value = value;
    preview.value = null;
  }

  function capture(e: unknown, fallback: string): void {
    const payload = (e as { response?: { data?: { error?: { code?: string; message?: string } } } })
      ?.response?.data?.error;
    const code = payload?.code ?? '';

    if (code in blockingMessages) {
      blocked.value = blockingMessages[code];
      error.value = null;
      return;
    }

    blocked.value = null;
    error.value = payload?.message ?? fallback;
  }

  async function fetchClients(): Promise<void> {
    loadingClients.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { sort: 'full_name' };
      if (search.value.trim() !== '') params.search = search.value.trim();
      const { data } = await apiClient.get<{ data: ServedClientForSms[] }>(
        '/personnel/me/served-clients/sms',
        { params },
      );
      clients.value = data.data;
    } catch (e) {
      capture(e, 'Unable to load your served clients.');
    } finally {
      loadingClients.value = false;
    }
  }

  async function fetchPreview(): Promise<void> {
    previewing.value = true;
    error.value = null;
    blocked.value = null;
    try {
      const { data } = await apiClient.post<{ data: SmsCampaignPreview }>(
        '/personnel/me/sms-campaigns/preview',
        { client_ulids: selectedIds.value, message_body: messageBody.value },
      );
      // Local state updates ONLY from the server response.
      preview.value = data.data;
    } catch (e) {
      preview.value = null;
      capture(e, 'Unable to preview this message.');
    } finally {
      previewing.value = false;
    }
  }

  /**
   * Compose the draft and confirm it. Both steps are server-authoritative; the client sends only
   * the selected ULIDs, the body, and an explicit acknowledgement.
   */
  async function send(): Promise<boolean> {
    sending.value = true;
    error.value = null;
    blocked.value = null;
    try {
      const { data: created } = await apiClient.post<{ data: PersonnelSmsCampaign }>(
        '/personnel/me/sms-campaigns',
        { client_ulids: selectedIds.value, message_body: messageBody.value },
      );
      const { data: confirmed } = await apiClient.post<{ data: PersonnelSmsCampaign }>(
        `/personnel/me/sms-campaigns/${created.data.id}/confirm`,
        { acknowledged: true },
      );

      lastCampaign.value = confirmed.data;
      selectedIds.value = [];
      messageBody.value = '';
      preview.value = null;
      await fetchCampaigns();

      return true;
    } catch (e) {
      capture(e, 'Unable to send this message.');
      return false;
    } finally {
      sending.value = false;
    }
  }

  async function fetchCampaigns(): Promise<void> {
    loadingCampaigns.value = true;
    try {
      const { data } = await apiClient.get<{ data: PersonnelSmsCampaign[] }>(
        '/personnel/me/sms-campaigns',
        { params: { sort: '-created_at' } },
      );
      campaigns.value = data.data;
    } catch (e) {
      capture(e, 'Unable to load your campaigns.');
    } finally {
      loadingCampaigns.value = false;
    }
  }

  async function fetchRecipients(campaignId: string): Promise<void> {
    try {
      const { data } = await apiClient.get<{ data: PersonnelSmsRecipient[] }>(
        `/personnel/me/sms-campaigns/${campaignId}/recipients`,
      );
      recipients.value = data.data;
    } catch (e) {
      capture(e, 'Unable to load the recipients for this campaign.');
    }
  }

  return {
    clients,
    selectedIds,
    selectedClients,
    messageBody,
    preview,
    campaigns,
    recipients,
    search,
    loadingClients,
    previewing,
    sending,
    loadingCampaigns,
    error,
    blocked,
    lastCampaign,
    selectedCount,
    atBatchLimit,
    canPreview,
    canSend,
    isSelected,
    toggle,
    clearSelection,
    setMessageBody,
    fetchClients,
    fetchPreview,
    send,
    fetchCampaigns,
    fetchRecipients,
    $reset,
  };
});
