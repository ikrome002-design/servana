import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from '@/services/apiClient';
import { useBranchExperienceStore, type BranchOverview } from './branchExperienceStore';

const branch = {
  id: 'branch-1', name: 'Westlands Studio', code: 'WST', address: 'Woodvale Grove', town: 'Nairobi',
  phone: '+254700000001', email: null, business_category: 'Salon', status: 'active' as const,
  status_reason: null, archived_at: null,
};

const overview: BranchOverview = {
  branch,
  business_date: '2026-08-12',
  day: { id: 'day-1', status: 'open', opened_at: '2026-08-12T05:00:00Z', closed_at: null, queue_is_open: true, close_blockers: [], financial_close_blockers: ['cash_up_not_approved'] },
  services: { total: 8, active: 7, archived: 1 },
  staff: { active: 5 },
  queue: { total: 4, active: 3, by_status: { waiting: 3, completed: 1 } },
  appointments: { today: 6, active_today: 4, by_status: { confirmed: 4, cancelled: 2 } },
  financial: { invoices_total: 4, invoices_by_status: { issued: 2, paid: 2 }, invoices_with_balance: 2, pending_payment_validations: 1, receipts_issued_today: 2, validated_revenue_by_currency: [{ currency: 'KES', amount_minor: 125000 }] },
  cash_up: null,
  billing: { status: 'active', next_invoice: null, payment_runtime: { available: false, reason: 'External Gate W' } },
  reporting: { available: false, reason: 'Phase 21N reporting runtime is not implemented' },
  notifications: { available: false, reason: 'External Gate W' },
  get_started: { profile_complete: true, calendar_configured: true, service_catalogue_ready: true, staff_ready: true, day_opened: true, cash_up_prepared: false, reports: { available: false, reason: 'Phase 21N' } },
};

describe('branchExperienceStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('resolves the assigned branch once and loads only server-owned workspace facts', async () => {
    vi.spyOn(apiClient, 'get').mockImplementation((url: string) => Promise.resolve(url === '/branches'
      ? { data: { data: [branch] } }
      : { data: { data: { overview } } }) as never);
    const store = useBranchExperienceStore();

    await store.fetchOverview();
    await store.fetchOverview();

    expect(apiClient.get).toHaveBeenCalledWith('/branches', { params: { per_page: 100 } });
    expect(apiClient.get).toHaveBeenCalledWith('/branches/branch-1/dashboard');
    expect(apiClient.get).toHaveBeenCalledTimes(3);
    expect(store.overview?.queue.active).toBe(3);
    expect(store.overview?.billing.payment_runtime.available).toBe(false);
    expect(store.overview).not.toHaveProperty('payment_success');
  });

  it('uses branch-scoped paginated projection endpoints for invoice, payment and audit views', async () => {
    vi.spyOn(apiClient, 'get').mockImplementation((url: string) => Promise.resolve(url === '/branches'
      ? { data: { data: [branch] } }
      : { data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } }) as never);
    const store = useBranchExperienceStore();

    await store.fetchInvoices({ sort: '-created_at' });
    await store.fetchPayments({ sort: '-created_at' });
    await store.fetchAudit({ sort: '-created_at' });

    expect(apiClient.get).toHaveBeenCalledWith('/branches/branch-1/financial-visibility/invoices', { params: { sort: '-created_at' } });
    expect(apiClient.get).toHaveBeenCalledWith('/branches/branch-1/financial-visibility/payment-records', { params: { sort: '-created_at' } });
    expect(apiClient.get).toHaveBeenCalledWith('/branches/branch-1/audit-events', { params: { sort: '-created_at' } });
    expect(store.meta?.total).toBe(0);
  });
});
