import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import { usePlatformFeeStore } from '@/stores/platformFeeStore';

const entry = {
  id: '01ENTRY',
  merchant_id: '01MERCH',
  branch_id: '01BRANCH',
  source_invoice_id: '01INV',
  source_invoice_item_id: '01ITEM',
  entry_type: 'earned',
  status: 'invoiced',
  billing_mode: 'percentage_on_merchant_client_invoice',
  service_fee_tier: 'shared',
  fee_basis_type: 'merchant_client_invoice_service_subtotal',
  fee_basis_amount_minor: 500000,
  percentage_rate_basis_points: 250,
  shared_split_basis_points: 5000,
  gross_platform_fee_minor: 12500,
  client_shifted_amount_minor: 6250,
  merchant_absorbed_amount_minor: 6250,
  merchant_liability_minor: 12500,
  currency: 'KES',
  subscription_invoice_item_id: '01ROLL',
  billable_at: '2026-07-05T10:00:00+00:00',
};

const summaryRow = {
  currency: 'KES',
  entry_count: 3,
  gross_platform_fee_minor: 30000,
  client_shifted_amount_minor: 15000,
  merchant_absorbed_amount_minor: 15000,
};

describe('platformFeeStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('lists entries with a ULID-only shape and applies the entry-type filter', async () => {
    get.mockResolvedValueOnce({ data: { data: [entry] } });
    const store = usePlatformFeeStore();
    store.filterEntryType = 'reversal';
    await store.fetchEntries();
    expect(get).toHaveBeenCalledWith('/platform-fees', { params: { entry_type: 'reversal' } });
    expect(store.entries[0].id).toBe('01ENTRY');
    // ULID references only — no internal numeric ids exposed by the contract.
    expect(typeof store.entries[0].merchant_id).toBe('string');
  });

  it('loads the server-authoritative per-currency summary (no browser recomputation)', async () => {
    get.mockResolvedValueOnce({ data: { data: [summaryRow] } });
    const store = usePlatformFeeStore();
    await store.fetchSummary();
    expect(get).toHaveBeenCalledWith('/platform-fees/summary');
    expect(store.summary[0].gross_platform_fee_minor).toBe(30000);
  });

  it('fetches a single entry detail', async () => {
    get.mockResolvedValueOnce({ data: { data: entry } });
    const store = usePlatformFeeStore();
    const fetched = await store.fetchEntry('01ENTRY');
    expect(get).toHaveBeenCalledWith('/platform-fees/01ENTRY');
    expect(fetched.currency).toBe('KES');
  });

  it('surfaces a friendly error without leaking internals', async () => {
    get.mockRejectedValueOnce(new Error('constraint platform_fee_ledger_entries_pk'));
    const store = usePlatformFeeStore();
    await store.fetchEntries();
    expect(store.error).toBe('Unable to load platform fees.');
    expect(store.error).not.toContain('constraint');
  });

  it('clears stale role/tenant data on reset', async () => {
    get.mockResolvedValueOnce({ data: { data: [entry] } });
    const store = usePlatformFeeStore();
    await store.fetchEntries();
    store.$reset();
    expect(store.entries).toHaveLength(0);
    expect(store.summary).toHaveLength(0);
    expect(store.filterEntryType).toBe('');
  });
});
