import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    patch: (...a: unknown[]) => patch(...a),
  },
}));

import { useFreePeriodOfferStore } from '@/stores/freePeriodOfferStore';

const offer = {
  id: '01OFFER',
  name: 'Free 30',
  free_period_days: 30,
  target_scope: 'all_new_merchants',
  effective_from: '2026-07-12',
  effective_to: null,
  status: 'draft',
  approved_at: null,
  change_reason: null,
  targets: [],
};

describe('freePeriodOfferStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
  });

  it('lists offers and applies the status filter', async () => {
    get.mockResolvedValueOnce({ data: { data: [offer] } });
    const store = useFreePeriodOfferStore();
    store.filterStatus = 'scheduled';
    await store.fetchOffers();
    expect(get).toHaveBeenCalledWith('/platform/free-period-offers', { params: { status: 'scheduled' } });
    expect(store.offers).toHaveLength(1);
  });

  it('surfaces a friendly error when the list fails', async () => {
    get.mockRejectedValueOnce(new Error('boom'));
    const store = useFreePeriodOfferStore();
    await store.fetchOffers();
    expect(store.error).toBe('Unable to load free-period offers.');
  });

  it('creates a draft offer', async () => {
    post.mockResolvedValueOnce({ data: { data: offer } });
    const store = useFreePeriodOfferStore();
    const created = await store.createOffer({
      name: 'Free 30',
      free_period_days: 30,
      target_scope: 'all_new_merchants',
      effective_from: '2026-07-12',
    });
    expect(post).toHaveBeenCalledWith('/platform/free-period-offers', expect.objectContaining({ free_period_days: 30 }));
    expect(created.free_period_days).toBe(30);
  });

  it('approves an offer to scheduled with a reason', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...offer, status: 'scheduled' } } });
    const store = useFreePeriodOfferStore();
    const result = await store.transition('01OFFER', 'approve', 'Approved');
    expect(post).toHaveBeenCalledWith('/platform/free-period-offers/01OFFER/approve', { change_reason: 'Approved' });
    expect(result.status).toBe('scheduled');
  });
});
