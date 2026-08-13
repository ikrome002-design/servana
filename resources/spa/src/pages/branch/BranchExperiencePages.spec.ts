import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BranchDashboard from './BranchDashboard.vue';
import FinancialVisibility from './FinancialVisibility.vue';
import { apiClient } from '@/services/apiClient';
import type { BranchOverview } from '@/stores/branchExperienceStore';

const branch = {
  id: 'branch-1', name: 'Westlands Studio', code: 'WST', address: 'Woodvale Grove', town: 'Nairobi',
  phone: '+254700000001', email: null, business_category: 'Salon', status: 'active' as const,
  status_reason: null, archived_at: null,
};

const overview: BranchOverview = {
  branch,
  business_date: '2026-08-12',
  day: { id: 'day-1', status: 'open', opened_at: '2026-08-12T05:00:00Z', closed_at: null, queue_is_open: true, close_blockers: ['active_queue_entries'], financial_close_blockers: ['cash_up_not_approved'] },
  services: { total: 8, active: 7, archived: 1 },
  staff: { active: 5 },
  queue: { total: 4, active: 3, by_status: { waiting: 3, completed: 1 } },
  appointments: { today: 6, active_today: 4, by_status: { confirmed: 4, cancelled: 2 } },
  financial: { invoices_total: 4, invoices_by_status: { issued: 2, paid: 2 }, invoices_with_balance: 2, pending_payment_validations: 1, receipts_issued_today: 2, validated_revenue_by_currency: [{ currency: 'KES', amount_minor: 125000 }] },
  cash_up: null,
  billing: { status: 'active', next_invoice: null, payment_runtime: { available: false, reason: 'External Gate W — Wallet by Citrus collections readiness' } },
  reporting: { available: false, reason: 'Phase 21N reporting runtime is not implemented' },
  notifications: { available: false, reason: 'External Gate W' },
  get_started: { profile_complete: true, calendar_configured: true, service_catalogue_ready: true, staff_ready: true, day_opened: true, cash_up_prepared: false, reports: { available: false, reason: 'Phase 21N' } },
};

const routes = [
  { path: '/dashboard', name: 'branch.dashboard', component: { template: '<div />' } },
  { path: '/branch/day', name: 'branch.branch-day', component: { template: '<div />' } },
  { path: '/operations/queue', name: 'branch.operations-queue', component: { template: '<div />' } },
  { path: '/finance/payments', name: 'branch.finance-payments', component: { template: '<div />' } },
  { path: '/cash-up', name: 'branch.cash-up', component: { template: '<div />' } },
  { path: '/finance/invoices', name: 'branch.finance-invoices', component: { template: '<div />' } },
];

async function routerAt(path: string) {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(path);
  await router.isReady();
  return router;
}

describe('UI-10 Branch experience pages', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('renders real branch workload and names unavailable Gate W/Phase 21N capability', async () => {
    vi.spyOn(apiClient, 'get').mockImplementation((url: string) => Promise.resolve(url === '/branches'
      ? { data: { data: [branch] } }
      : { data: { data: { overview } } }) as never);
    const wrapper = mount(BranchDashboard, { global: { plugins: [await routerAt('/dashboard')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Westlands Studio');
    expect(wrapper.text()).toContain('3');
    expect(wrapper.text()).toContain('Ksh');
    expect(wrapper.text()).toContain('External Gate W');
    expect(wrapper.text()).toContain('Phase 21N');
    expect(wrapper.text()).not.toContain('Payment successful');
  });

  it('renders financial context with explicit read-only ownership and no mutation controls', async () => {
    const row = {
      id: 'invoice-1', invoice_number: 'INV-001', status: 'issued', total_minor: 250000,
      validated_paid_minor: 100000, balance_minor: 150000, currency: 'KES', finalized_at: null,
      created_at: '2026-08-12T05:00:00Z', can: { create: false, update: false, finalize: false, void: false, adjust: false },
    };
    vi.spyOn(apiClient, 'get').mockImplementation((url: string) => Promise.resolve(url === '/branches'
      ? { data: { data: [branch] } }
      : { data: { data: [row], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } }) as never);
    const wrapper = mount(FinancialVisibility, {
      props: { kind: 'invoices' },
      global: { plugins: [await routerAt('/finance/invoices')] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('INV-001');
    expect(wrapper.text()).toContain('Front Office creates invoices');
    expect(wrapper.text()).toContain('Context only');
    expect(wrapper.text()).not.toContain('Create invoice');
    expect(wrapper.text()).not.toContain('Validate payment');
  });
});
