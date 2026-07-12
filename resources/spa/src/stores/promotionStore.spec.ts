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

import { usePromotionStore } from '@/stores/promotionStore';

const promo = {
  id: '01PROMO',
  name: 'Launch 10%',
  type: 'percentage',
  value: 1000,
  currency: null,
  target_scope: 'all_new_merchants',
  effective_from: '2026-07-12',
  effective_to: null,
  status: 'draft',
  approved_at: null,
  change_reason: null,
  targets: [],
};

describe('promotionStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
  });

  it('lists promotions and applies the status filter', async () => {
    get.mockResolvedValueOnce({ data: { data: [promo] } });
    const store = usePromotionStore();
    store.filterStatus = 'active';
    await store.fetchPromotions();
    expect(get).toHaveBeenCalledWith('/platform/promotional-discounts', { params: { status: 'active' } });
    expect(store.promotions).toHaveLength(1);
  });

  it('surfaces a friendly error when the list fails', async () => {
    get.mockRejectedValueOnce(new Error('boom'));
    const store = usePromotionStore();
    await store.fetchPromotions();
    expect(store.error).toBe('Unable to load promotional discounts.');
    expect(store.loading).toBe(false);
  });

  it('creates a draft promotion', async () => {
    post.mockResolvedValueOnce({ data: { data: promo } });
    const store = usePromotionStore();
    const created = await store.createPromotion({
      name: 'Launch 10%',
      type: 'percentage',
      value: 1000,
      target_scope: 'all_new_merchants',
      effective_from: '2026-07-12',
    });
    expect(post).toHaveBeenCalledWith('/platform/promotional-discounts', expect.objectContaining({ type: 'percentage' }));
    expect(created.status).toBe('draft');
  });

  it('edits a draft via PATCH', async () => {
    patch.mockResolvedValueOnce({ data: { data: { ...promo, value: 2000 } } });
    const store = usePromotionStore();
    const updated = await store.updateDraft('01PROMO', {
      name: 'Launch 20%',
      type: 'percentage',
      value: 2000,
      target_scope: 'all_new_merchants',
      effective_from: '2026-07-12',
    });
    expect(patch).toHaveBeenCalledWith('/platform/promotional-discounts/01PROMO', expect.objectContaining({ value: 2000 }));
    expect(updated.value).toBe(2000);
  });

  it('sends the reason on a lifecycle transition', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...promo, status: 'active' } } });
    const store = usePromotionStore();
    const result = await store.transition('01PROMO', 'approve', 'Approved for launch');
    expect(post).toHaveBeenCalledWith('/platform/promotional-discounts/01PROMO/approve', {
      change_reason: 'Approved for launch',
    });
    expect(result.status).toBe('active');
  });
});
