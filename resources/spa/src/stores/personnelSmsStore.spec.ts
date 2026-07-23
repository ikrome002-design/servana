import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from '@/services/apiClient';
import { usePersonnelSmsStore, type SmsCampaignPreview } from '@/stores/personnelSmsStore';

vi.mock('@/services/apiClient', () => ({
  apiClient: { get: vi.fn(), post: vi.fn() },
}));

const get = vi.mocked(apiClient.get);
const post = vi.mocked(apiClient.post);

/** The full number a server must never send. Present here so the assertions are meaningful. */
const FULL_PHONE = '+254712345678';

const client = { id: '01HZZCLIENT0000000000000001', full_name: 'Amina Wanjiru', phone_masked: '••• ••• 5678' };

const preview: SmsCampaignPreview = {
  recipient_count: 1,
  excluded_count: 2,
  excluded_reasons: { consent_opted_out: 1, unknown_client: 1 },
  message_character_count: 20,
  segment_count: 1,
  requires_unicode: false,
  characters_remaining_in_segment: 140,
  estimated_cost: { amount: 100, currency: 'KES', formatted: 'KES 1.00' },
  unit_cost_minor: 100,
  max_recipients: 200,
  max_message_characters: 480,
  billing_notice: 'Sending this campaign adds an SMS charge to your Servana billing.',
};

const campaign = {
  id: '01HZZCAMPAIGN00000000000001',
  status: 'completed',
  status_label: 'Completed',
  recipient_count: 1,
  message_character_count: 20,
  segment_count: 1,
  estimated_cost: { amount: 100, currency: 'KES', formatted: 'KES 1.00' },
  final_cost: { amount: 100, currency: 'KES', formatted: 'KES 1.00' },
  failure_reason_code: null,
  is_cancellable: false,
  confirmed_at: '2026-07-22T10:00:00+00:00',
  completed_at: '2026-07-22T10:00:05+00:00',
  cancelled_at: null,
  created_at: '2026-07-22T09:59:00+00:00',
};

describe('personnelSmsStore (Phase 21S)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    localStorage.clear();
    sessionStorage.clear();
  });

  it('loads served clients and holds only the masked contact', async () => {
    get.mockResolvedValueOnce({ data: { data: [client] } } as never);

    const store = usePersonnelSmsStore();
    await store.fetchClients();

    expect(store.clients).toHaveLength(1);
    expect(store.clients[0].phone_masked).toBe('••• ••• 5678');
    // No full number, and no field that could hold one.
    expect(JSON.stringify(store.clients)).not.toContain('712345678');
    expect(store.clients[0]).not.toHaveProperty('phone');
    expect(store.clients[0]).not.toHaveProperty('phone_encrypted');
  });

  it('sends the search term as a parameter, never a phone', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } } as never);

    const store = usePersonnelSmsStore();
    store.search = 'Amina';
    await store.fetchClients();

    expect(get).toHaveBeenCalledWith('/personnel/me/served-clients/sms', {
      params: { sort: 'full_name', search: 'Amina' },
    });
  });

  it('toggles selection and invalidates a stale preview on any change', async () => {
    post.mockResolvedValueOnce({ data: { data: preview } } as never);

    const store = usePersonnelSmsStore();
    store.clients = [client];
    store.toggle(client.id);
    store.setMessageBody('Thank you for visiting.');
    await store.fetchPreview();

    expect(store.preview).not.toBeNull();
    expect(store.canSend).toBe(true);

    // Changing the selection or the body throws the server's verdict away — a send can never run
    // against a preview that no longer describes what is being sent.
    store.toggle(client.id);
    expect(store.preview).toBeNull();
    expect(store.canSend).toBe(false);

    store.toggle(client.id);
    post.mockResolvedValueOnce({ data: { data: preview } } as never);
    await store.fetchPreview();
    store.setMessageBody('A different message.');
    expect(store.preview).toBeNull();
  });

  it('displays server-computed metrics and never derives its own', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...preview, segment_count: 3, estimated_cost: { amount: 900, currency: 'KES', formatted: 'KES 9.00' } } } } as never);

    const store = usePersonnelSmsStore();
    store.clients = [client];
    store.toggle(client.id);
    // A body whose real segment count is 1 — the store must still show the server's 3.
    store.setMessageBody('short');
    await store.fetchPreview();

    expect(store.preview?.segment_count).toBe(3);
    expect(store.preview?.estimated_cost.formatted).toBe('KES 9.00');
  });

  it('posts only the ULIDs, the body and an acknowledgement — never cost, count or staff id', async () => {
    post
      .mockResolvedValueOnce({ data: { data: preview } } as never)
      .mockResolvedValueOnce({ data: { data: campaign } } as never)
      .mockResolvedValueOnce({ data: { data: campaign } } as never);
    get.mockResolvedValueOnce({ data: { data: [campaign] } } as never);

    const store = usePersonnelSmsStore();
    store.clients = [client];
    store.toggle(client.id);
    store.setMessageBody('Thank you for visiting.');
    await store.fetchPreview();

    expect(await store.send()).toBe(true);

    const createBody = post.mock.calls[1][1] as Record<string, unknown>;
    expect(Object.keys(createBody).sort()).toEqual(['client_ulids', 'message_body']);

    const confirmBody = post.mock.calls[2][1] as Record<string, unknown>;
    expect(confirmBody).toEqual({ acknowledged: true });

    // Nothing cost-, count- or identity-shaped ever leaves the browser.
    const everythingSent = JSON.stringify(post.mock.calls);
    expect(everythingSent).not.toContain('estimated_cost_minor');
    expect(everythingSent).not.toContain('recipient_count');
    expect(everythingSent).not.toContain('staff_profile');
    expect(everythingSent).not.toContain(FULL_PHONE);
  });

  it('clears the composer from the SERVER response after a successful send', async () => {
    post
      .mockResolvedValueOnce({ data: { data: preview } } as never)
      .mockResolvedValueOnce({ data: { data: campaign } } as never)
      .mockResolvedValueOnce({ data: { data: campaign } } as never);
    get.mockResolvedValueOnce({ data: { data: [campaign] } } as never);

    const store = usePersonnelSmsStore();
    store.clients = [client];
    store.toggle(client.id);
    store.setMessageBody('Thank you for visiting.');
    await store.fetchPreview();
    await store.send();

    expect(store.selectedIds).toEqual([]);
    expect(store.messageBody).toBe('');
    expect(store.preview).toBeNull();
    expect(store.lastCampaign?.status_label).toBe('Completed');
    expect(store.campaigns).toHaveLength(1);
  });

  it('surfaces entitlement and billing refusals as actionable copy, not raw codes', async () => {
    const store = usePersonnelSmsStore();
    store.clients = [client];
    store.toggle(client.id);
    store.setMessageBody('Hello');

    for (const [code, expected] of [
      ['entitlement_disabled', 'Your plan does not include SMS. Ask your administrator about upgrading.'],
      ['no_active_plan', 'This merchant has no active subscription, so SMS is unavailable.'],
      ['billing_read_only', 'Billing is read-only, so new messages cannot be sent right now.'],
    ] as const) {
      post.mockRejectedValueOnce({ response: { data: { error: { code, message: 'raw' } } } });
      await store.fetchPreview();

      expect(store.blocked).toBe(expected);
      expect(store.error).toBeNull();
      expect(store.preview).toBeNull();
      expect(store.canSend).toBe(false);
    }
  });

  it('reports a validation refusal as an error without blocking the screen', async () => {
    post.mockRejectedValueOnce({
      response: { data: { error: { code: 'no_eligible_recipients', message: 'None of the selected clients can receive this message.' } } },
    });

    const store = usePersonnelSmsStore();
    store.clients = [client];
    store.toggle(client.id);
    store.setMessageBody('Hello');
    await store.fetchPreview();

    expect(store.error).toBe('None of the selected clients can receive this message.');
    expect(store.blocked).toBeNull();
  });

  it('never writes anything to localStorage or sessionStorage', async () => {
    post
      .mockResolvedValueOnce({ data: { data: preview } } as never)
      .mockResolvedValueOnce({ data: { data: campaign } } as never)
      .mockResolvedValueOnce({ data: { data: campaign } } as never);
    get.mockResolvedValueOnce({ data: { data: [campaign] } } as never);

    const store = usePersonnelSmsStore();
    store.clients = [client];
    store.toggle(client.id);
    store.setMessageBody('Thank you for visiting.');
    await store.fetchPreview();
    await store.send();

    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
  });

  it('exposes recipients with masked contact only', async () => {
    get.mockResolvedValueOnce({
      data: {
        data: [{
          id: client.id,
          phone_masked: '••• ••• 5678',
          delivery_status: 'sent',
          delivery_status_label: 'Sent',
          consent_status: 'opted_in',
          exclusion_reason: null,
        }],
      },
    } as never);

    const store = usePersonnelSmsStore();
    await store.fetchRecipients(campaign.id);

    expect(store.recipients[0].phone_masked).toBe('••• ••• 5678');
    expect(JSON.stringify(store.recipients)).not.toContain('712345678');
  });

  it('offers no export, download, print or clipboard action', () => {
    const store = usePersonnelSmsStore();

    for (const forbidden of ['export', 'download', 'print', 'copy', 'toCsv', 'copyNumbers']) {
      expect(store).not.toHaveProperty(forbidden);
    }
  });
});
