import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
  },
}));

import { usePlatformFeeDisputeStore } from '@/stores/platformFeeDisputeStore';

const dispute = {
  id: '01DISP',
  platform_fee_ledger_entry_id: '01ENTRY',
  subscription_invoice_id: null,
  reason: 'Overcharged',
  status: 'open',
  assigned_reviewer: null,
  resolution_note: null,
  has_evidence: false,
  created_by: '01USER',
  resolved_by: null,
  resolved_at: null,
  created_at: '2026-08-01T09:00:00+00:00',
  capabilities: { reviewable: true, resolvable: false, rejectable: true },
};

describe('platformFeeDisputeStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('lists disputes with the status filter', async () => {
    get.mockResolvedValueOnce({ data: { data: [dispute] } });
    const store = usePlatformFeeDisputeStore();
    store.filterStatus = 'under_review';
    await store.fetchDisputes();
    expect(get).toHaveBeenCalledWith('/platform-fee-disputes', { params: { status: 'under_review' } });
    expect(store.disputes).toHaveLength(1);
  });

  it('creates a dispute against a ledger entry (ULID)', async () => {
    post.mockResolvedValueOnce({ data: { data: dispute } });
    const store = usePlatformFeeDisputeStore();
    await store.createDispute({ platform_fee_ledger_entry: '01ENTRY', reason: 'Overcharged' });
    expect(post).toHaveBeenCalledWith('/platform-fee-disputes', { platform_fee_ledger_entry: '01ENTRY', reason: 'Overcharged' });
  });

  it('starts a review (bodiless)', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...dispute, status: 'under_review' } } });
    const store = usePlatformFeeDisputeStore();
    await store.startReview('01DISP');
    expect(post).toHaveBeenCalledWith('/platform-fee-disputes/01DISP/review', {});
  });

  it('resolves without a money change (no money field sent)', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...dispute, status: 'resolved' } } });
    const store = usePlatformFeeDisputeStore();
    await store.resolve('01DISP', 'Reviewed, no change');
    expect(post).toHaveBeenCalledWith('/platform-fee-disputes/01DISP/resolve', { resolution_note: 'Reviewed, no change' });
  });

  it('resolves with a signed money change → additive adjustment on the backend', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...dispute, status: 'resolved' } } });
    const store = usePlatformFeeDisputeStore();
    await store.resolve('01DISP', 'Credit due', -5000);
    expect(post).toHaveBeenCalledWith('/platform-fee-disputes/01DISP/resolve', {
      resolution_note: 'Credit due',
      money_change_amount_minor: -5000,
    });
  });

  it('rejects with a mandatory note', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...dispute, status: 'rejected' } } });
    const store = usePlatformFeeDisputeStore();
    await store.reject('01DISP', 'Not valid');
    expect(post).toHaveBeenCalledWith('/platform-fee-disputes/01DISP/reject', { resolution_note: 'Not valid' });
  });

  it('surfaces a friendly error and resets state', async () => {
    get.mockRejectedValueOnce(new Error('boom'));
    const store = usePlatformFeeDisputeStore();
    await store.fetchDisputes();
    expect(store.error).toBe('Unable to load platform-fee disputes.');
    store.$reset();
    expect(store.disputes).toHaveLength(0);
    expect(store.filterStatus).toBe('');
  });
});
