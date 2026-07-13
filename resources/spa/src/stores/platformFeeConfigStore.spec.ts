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

import { usePlatformFeeConfigStore, type PlatformFeeConfigPayload } from '@/stores/platformFeeConfigStore';

const config = {
  id: '01CFG',
  billing_mode: 'percentage_on_merchant_client_invoice',
  percentage_basis_points: 250,
  fixed_component_minor: null,
  tier_behavior: 'customer_centric',
  shared_split_basis_points: null,
  fee_basis_type: 'merchant_client_invoice_service_subtotal',
  currency: 'KES',
  effective_from: '2026-08-01',
  effective_to: null,
  status: 'draft',
  approved_at: null,
  change_reason: 'Launch',
  capabilities: { editable: true, approvable: true, supersedable: false, cancellable: true },
};

const payload: PlatformFeeConfigPayload = {
  billing_mode: 'percentage_on_merchant_client_invoice',
  percentage_basis_points: 250,
  tier_behavior: 'customer_centric',
  fee_basis_type: 'merchant_client_invoice_service_subtotal',
  currency: 'KES',
  effective_from: '2026-08-01',
  change_reason: 'Launch',
};

describe('platformFeeConfigStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
  });

  it('lists configurations and applies the status filter', async () => {
    get.mockResolvedValueOnce({ data: { data: [config] } });
    const store = usePlatformFeeConfigStore();
    store.filterStatus = 'active';
    await store.fetchConfigurations();
    expect(get).toHaveBeenCalledWith('/platform/billing/platform-fee-configurations', { params: { status: 'active' } });
    expect(store.configurations).toHaveLength(1);
    expect(store.loading).toBe(false);
  });

  it('surfaces a friendly error and does not leak internals when the list fails', async () => {
    get.mockRejectedValueOnce(new Error('SQLSTATE 23505'));
    const store = usePlatformFeeConfigStore();
    await store.fetchConfigurations();
    expect(store.error).toBe('Unable to load platform-fee configurations.');
    expect(store.error).not.toContain('SQLSTATE');
  });

  it('creates a draft configuration', async () => {
    post.mockResolvedValueOnce({ data: { data: config } });
    const store = usePlatformFeeConfigStore();
    const created = await store.createConfiguration(payload);
    expect(post).toHaveBeenCalledWith('/platform/billing/platform-fee-configurations', payload);
    expect(created.status).toBe('draft');
  });

  it('updates a draft', async () => {
    patch.mockResolvedValueOnce({ data: { data: config } });
    const store = usePlatformFeeConfigStore();
    await store.updateDraft('01CFG', payload);
    expect(patch).toHaveBeenCalledWith('/platform/billing/platform-fee-configurations/01CFG', payload);
  });

  it('drives named transitions (approve/supersede/cancel) — never a generic status setter', async () => {
    post.mockResolvedValue({ data: { data: { ...config, status: 'active' } } });
    const store = usePlatformFeeConfigStore();
    await store.transition('01CFG', 'approve', { change_reason: 'ok' });
    expect(post).toHaveBeenCalledWith('/platform/billing/platform-fee-configurations/01CFG/approve', { change_reason: 'ok' });
    await store.transition('01CFG', 'cancel', { change_reason: 'stop' });
    expect(post).toHaveBeenCalledWith('/platform/billing/platform-fee-configurations/01CFG/cancel', { change_reason: 'stop' });
  });

  it('resets tenant/context state', async () => {
    get.mockResolvedValueOnce({ data: { data: [config] } });
    const store = usePlatformFeeConfigStore();
    await store.fetchConfigurations();
    store.$reset();
    expect(store.configurations).toHaveLength(0);
    expect(store.current).toBeNull();
    expect(store.filterStatus).toBe('');
  });
});
