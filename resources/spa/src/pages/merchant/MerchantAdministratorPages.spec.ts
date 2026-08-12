import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard.vue';
import MerchantBranchDetail from './MerchantBranchDetail.vue';
import StaffOverview from './StaffOverview.vue';
import SubscriptionInvoiceDetail from './SubscriptionInvoiceDetail.vue';
import { apiClient } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';
import type { MerchantDashboardOverview } from '@/stores/merchantDashboardStore';

const stub = { template: '<div />' };
const routes = [
  { path: '/', name: 'home', component: stub },
  { path: '/dashboard', name: 'merchant.dashboard', component: stub },
  { path: '/branches', name: 'merchant.branches', component: stub },
  { path: '/branches/:branchUlid', name: 'merchant.branch-detail', component: stub },
  { path: '/staff', name: 'merchant.staff', component: stub },
  { path: '/subscription', name: 'merchant.subscription', component: stub },
  { path: '/subscription/invoices', name: 'merchant.subscription-invoices', component: stub },
  { path: '/subscription/invoices/:invoiceUlid', name: 'merchant.subscription-invoice-detail', component: stub },
  { path: '/compensation', name: 'merchant.compensation', component: stub },
];

const dashboard: MerchantDashboardOverview = {
  subscription: null,
  billing: { next_invoice: null, outstanding_by_currency: [], payment_runtime: { available: false, reason: 'External Gate W — Wallet by Citrus collections readiness' } },
  branches: { total: 1, active: 1, suspended: 0, archived: 0, limit: 2, remaining_capacity: 1 },
  staff: { active: 3, invited: 0, suspended: 0, deactivated: 0, pending_owner_invitations: 0 },
  get_started: { setup_complete: true, subscription_selected: true, profile_complete: true, logo_uploaded: false, billing_phone_confirmed: true, first_branch_created: true, initial_team_invited: true, initial_team_active: true, operational_roles_active: false, daily_reports: { available: false, reason: 'External Gate W' } },
  compensation: null,
  reporting: { available: false, reason: 'External Gate W — Wallet by Citrus collections readiness', omitted_metrics: ['validated_revenue'] },
};

const branch = { id: 'branch-1', name: 'Kilimani', code: 'KIL', address: 'Nairobi', town: 'Nairobi', phone: null, email: null, business_category: null, status: 'active', status_reason: null, archived_at: null };
const row = { id: 'membership-1', staff_profile_id: 'staff-1', display_name: 'Branch Owner', email: 'owner@example.test', role: 'branch_manager', status: 'active', account_status: 'active', activated_at: null, last_login_at: null, branches: [{ id: 'branch-1', name: 'Kilimani', code: 'KIL' }], active_session_count: 1, assignment_history: [], status_history: [], can: { manage_lifecycle: true } };
const invoice = { id: 'invoice-1', invoice_number: 'SUB-001', status: 'issued', period_start: '2026-08-01', period_end: '2026-08-31', subtotal_minor: 250000, discount_minor: 0, total_minor: 250000, balance_minor: 250000, currency: 'KES', issued_at: '2026-08-01T00:00:00Z', due_at: '2026-08-15T00:00:00Z', payment_reference_pending: true, account_reference: null, has_pdf: false };

async function routerAt(path: string) {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(path);
  await router.isReady();
  return router;
}

describe('Merchant Administrator canonical pages', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
    sessionStorage.clear();
    useAuthStore().permissions = ['merchant.subscription.invoice.download', 'branches.manage_users_lifecycle'];
  });

  it('renders real owner facts and names Gate W instead of zero-valued reports', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: { overview: dashboard } } } as never);
    const wrapper = mount(Dashboard, { global: { plugins: [await routerAt('/dashboard')] } });
    await flushPromises();

    expect(wrapper.find('[data-testid="dashboard-branches"]').text()).toContain('1 active');
    expect(wrapper.text()).toContain('External Gate W');
    expect(wrapper.text()).not.toContain('KES 0.00 revenue');
  });

  it('renders owner branch context without operational mutation controls', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: branch } } as never);
    const wrapper = mount(MerchantBranchDetail, { global: { plugins: [await routerAt('/branches/branch-1')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Kilimani');
    expect(wrapper.text()).toContain('Responsibility boundaries');
    expect(wrapper.text()).not.toContain('Edit service');
    expect(wrapper.text()).not.toContain('Validate payment');
  });

  it('renders the phone-free owner directory with impact-aware lifecycle controls', async () => {
    vi.spyOn(apiClient, 'get').mockImplementation((url: string) => Promise.resolve(url.startsWith('/merchant/staff-overview')
      ? { data: { data: [row], meta: { total: 1, current_page: 1, last_page: 1 } } }
      : { data: { data: url === '/branches' ? [branch] : [] } }) as never);
    const wrapper = mount(StaffOverview, { global: { plugins: [await routerAt('/staff')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Phone numbers and client data are not included');
    expect(wrapper.text()).toContain('Active sessions: 1');
    expect(wrapper.text()).not.toContain('Service eligibility');
    await wrapper.findAll('button').find((button) => button.text().includes('Suspend access'))!.trigger('click');
    await flushPromises();
    expect(document.body.textContent).toContain('unused Magic Links');
  });

  it('renders immutable invoice amounts and a truthful unavailable payment state', async () => {
    vi.spyOn(apiClient, 'get').mockImplementation((url: string) => Promise.resolve(url === '/subscription'
      ? { data: { data: { billing_read_only: false, scheduled_plan_change: null } } }
      : { data: { data: invoice } }) as never);
    const wrapper = mount(SubscriptionInvoiceDetail, { global: { plugins: [await routerAt('/subscription/invoices/invoice-1')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('SUB-001');
    expect(wrapper.text()).toContain('External Gate W');
    expect(wrapper.text()).not.toContain('Payment successful');
  });
});
