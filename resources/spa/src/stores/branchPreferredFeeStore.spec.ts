import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import { useBranchPreferredFeeStore } from '@/stores/branchPreferredFeeStore';

const effective = {
  calculation_type: 'fixed_amount',
  fixed_amount_minor: 5000,
  percentage_basis_points: null,
  currency: 'KES',
  calculation_basis: 'service_item_net_amount',
  effective_from: '2026-07-10',
  effective_to: null,
};

describe('branchPreferredFeeStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('is read-only: it exposes no mutation methods', () => {
    const store = useBranchPreferredFeeStore();
    expect((store as unknown as Record<string, unknown>).createRule).toBeUndefined();
    expect((store as unknown as Record<string, unknown>).updateRule).toBeUndefined();
  });

  it('fetches the effective rule (no service param by default)', async () => {
    get.mockResolvedValueOnce({ data: { data: effective } });
    const store = useBranchPreferredFeeStore();
    await store.fetchEffective();
    expect(get).toHaveBeenCalledWith('/branch/preferred-personnel-fee-rule', { params: {} });
    expect(store.rule?.fixed_amount_minor).toBe(5000);
    expect(store.loaded).toBe(true);
  });

  it('narrows to a service-scoped rule when a service ULID is given', async () => {
    get.mockResolvedValueOnce({ data: { data: effective } });
    const store = useBranchPreferredFeeStore();
    await store.fetchEffective('01SERVICE');
    expect(get).toHaveBeenCalledWith('/branch/preferred-personnel-fee-rule', {
      params: { service: '01SERVICE' },
    });
  });

  it('treats a null rule as an empty (no-fee) state, not an error', async () => {
    get.mockResolvedValueOnce({ data: { data: null } });
    const store = useBranchPreferredFeeStore();
    await store.fetchEffective();
    expect(store.rule).toBeNull();
    expect(store.loaded).toBe(true);
    expect(store.error).toBeNull();
  });
});
