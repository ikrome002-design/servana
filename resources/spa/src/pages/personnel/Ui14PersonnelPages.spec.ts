import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const route = { query: {} as Record<string, string> };
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRoute: () => route }));

import AvailabilityStatus from '@/pages/personnel/AvailabilityStatus.vue';
import ClientSms from '@/pages/personnel/ClientSms.vue';
import EarningsOverview from '@/pages/personnel/EarningsOverview.vue';
import EarningsQueries from '@/pages/personnel/EarningsQueries.vue';
import ServedClients from '@/pages/personnel/ServedClients.vue';
import { useAuthStore } from '@/stores/authStore';
import { personnelStatusTone } from '@/utils/personnelStatus';

const meta = { current_page: 1, last_page: 1, per_page: 20, total: 1 };
const CLIENT = { id: '01HZZCLIENT000000000000001', full_name: 'Njeri Kamau', phone_masked: '+2547•••••678' };
const stubs = { RouterLink: RouterLinkStub, SvDialog: { template: '<div v-if="open"><slot /></div>', props: ['open', 'title'] } };
const opts = { global: { stubs } };

/** Routes GET calls by path so each page receives only the payload it asked for. */
function respond(map: Record<string, unknown>): void {
  get.mockImplementation((url: string) => {
    const key = Object.keys(map).find((path) => url === path);
    return key ? Promise.resolve({ data: map[key] }) : Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

beforeEach(() => {
  setActivePinia(createPinia());
  get.mockReset();
  post.mockReset();
  route.query = {};
});

describe('UI-14 served clients privacy', () => {
  it('shows masked relationship cards with no export, copy, print, tel or raw-number surface', async () => {
    respond({ '/personnel/me/served-clients': { data: [{ ...CLIENT, last_served_at: '2026-07-15T09:00:00Z', visit_count: 4, services: [], sms_consent: 'granted', sms_eligible: true }], meta } });
    const wrapper = mount(ServedClients, opts);
    await flushPromises();

    expect(wrapper.text()).toContain('+2547•••••678');
    expect(wrapper.text()).toMatch(/Masked contact/);
    expect(wrapper.html()).not.toMatch(/tel:|sms:|mailto:/);
    const controls = wrapper.findAll('button, a').map((node) => node.text());
    expect(controls.join(' ')).not.toMatch(/export|download|copy|print|csv/i);
    const compose = wrapper.findAllComponents(RouterLinkStub).find((link) => link.text().startsWith('Message'));
    expect(compose?.props('to')).toEqual({ name: 'personnel.messages-compose', query: { client: CLIENT.id } });
  });
});

describe('UI-14 SMS composer', () => {
  function grant(): void {
    useAuthStore().permissions = ['personnel.my_served_clients.view', 'personnel.my_sms.send'];
  }

  it('preselects only a served client named by an opaque ?client ULID', async () => {
    grant();
    route.query = { client: CLIENT.id };
    respond({ '/personnel/me/served-clients/sms': { data: [CLIENT], meta } });
    const wrapper = mount(ClientSms, { ...opts, props: { mode: 'compose' } });
    await flushPromises();

    expect((wrapper.get(`[aria-label="Select ${CLIENT.full_name}"]`).element as HTMLInputElement).checked).toBe(true);
    expect(wrapper.text()).toContain('1 selected');
  });

  it('ignores a ?client that is not in the caller’s served list', async () => {
    grant();
    route.query = { client: '01HZZFOREIGN0000000000000X' };
    respond({ '/personnel/me/served-clients/sms': { data: [CLIENT], meta } });
    const wrapper = mount(ClientSms, { ...opts, props: { mode: 'compose' } });
    await flushPromises();

    expect(wrapper.text()).toContain('0 selected');
  });

  it('hands off to the server-reported campaign status after a confirmed send', async () => {
    grant();
    respond({ '/personnel/me/served-clients/sms': { data: [CLIENT], meta }, '/personnel/me/sms-campaigns': { data: [] } });
    const preview = { recipient_count: 1, excluded_count: 0, excluded_reasons: {}, message_character_count: 5, segment_count: 1, requires_unicode: false, characters_remaining_in_segment: 155, estimated_cost: { amount: 150, currency: 'KES', formatted: 'KES 1.50' }, unit_cost_minor: 150, max_recipients: 200, max_message_characters: 480, billing_notice: 'SMS charges for this message will be billed to your branch together with the Servana subscription invoice. Continue?' };
    const campaign = { id: '01HZZCAMPAIGN0000000000001', status: 'queued', status_label: 'Queued', recipient_count: 1, message_character_count: 5, segment_count: 1, estimated_cost: preview.estimated_cost, final_cost: null, failure_reason_code: null, is_cancellable: false, confirmed_at: null, completed_at: null, cancelled_at: null, created_at: null };
    post.mockImplementation((url: string) => Promise.resolve({ data: { data: url.endsWith('/preview') ? preview : campaign } }));
    const wrapper = mount(ClientSms, { ...opts, props: { mode: 'compose' } });
    await flushPromises();

    await wrapper.get(`[aria-label="Select ${CLIENT.full_name}"]`).setValue(true);
    await wrapper.get('#sms-body').setValue('Hello');
    await wrapper.get('[data-testid="sms-preview-button"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="sms-billing-notice"]').text()).toBe(preview.billing_notice);
    expect(wrapper.text()).toContain('5 characters typed');

    await wrapper.get('[data-testid="sms-send-button"]').trigger('click');
    await wrapper.get('[data-testid="sms-confirm-send"]').trigger('click');
    await flushPromises();

    expect(wrapper.get('[data-testid="sms-confirmation"]').text()).toContain('Sent to 1 client through Servana.');
    expect(wrapper.get('[data-testid="sms-campaign-status"]').text()).toBe('Queued');
  });
});

describe('UI-14 earnings truthfulness', () => {
  it('renders the server totals and per-component figures without adding them in the browser', async () => {
    const row = { currency: 'KES', salary_unpaid_minor: 2850000, salary_paid_minor: 2800000, commission_unpaid_minor: 185000, commission_paid_minor: 420000, adjustment_unpaid_minor: 0, adjustment_paid_minor: 25000, unpaid_minor: 3035000, paid_minor: 3245000, net_minor: 6280000 };
    respond({
      '/personnel/me/earnings': { data: { tab_visibility: { model: 'salary_plus_commission', has_current_plan: true, conflicting: false, salary_tab: true, commission_tab: true }, currencies: [row] } },
      '/personnel/me/workspace': { data: null },
    });
    const wrapper = mount(EarningsOverview, opts);
    await flushPromises();
    const text = wrapper.text().replace(/\s+/g, ' ');

    expect(text).toContain('62,800.00');
    expect(text).toContain('30,350.00');
    expect(text).toContain('32,450.00');
    // A browser sum of salary paid + unpaid (KES 56,500.00) would be a second financial authority.
    expect(text).not.toContain('56,500.00');
    expect(text).toContain('Salary plus commission');
  });

  it('prefills an own earnings query from a record link and selects the matching question type', async () => {
    route.query = { subject_type: 'salary_ledger', subject: '01HZZSALARY000000000000001' };
    respond({ '/personnel/me/earnings-queries': { data: [], meta } });
    const wrapper = mount(EarningsQueries, opts);
    await flushPromises();

    expect((wrapper.get('#query-subject').element as HTMLSelectElement).value).toBe('salary_ledger');
    expect((wrapper.get('#query-reference').element as HTMLInputElement).value).toBe('01HZZSALARY000000000000001');
    expect((wrapper.get('#query-type').element as HTMLSelectElement).value).toMatch(/^salary/);
  });

  it('drops a malformed or foreign-shaped reference instead of prefilling it', async () => {
    route.query = { subject_type: 'payroll_export', subject: '../../etc' };
    respond({ '/personnel/me/earnings-queries': { data: [], meta } });
    const wrapper = mount(EarningsQueries, opts);
    await flushPromises();

    expect((wrapper.get('#query-subject').element as HTMLSelectElement).value).toBe('commission_ledger');
    expect((wrapper.get('#query-reference').element as HTMLInputElement).value).toBe('');
  });
});

describe('UI-14 availability authority', () => {
  it('is read-only: no button, input, select or toggle for Personnel', async () => {
    respond({ '/personnel/me/availability': { data: { staff: { id: 's', display_name: 'Ada Mwangi', employment_status: 'employed', is_active: true }, timezone: 'Africa/Nairobi', current_state: 'available', recurring: [{ weekday: 1, start_time: '08:00:00', end_time: '17:00:00', available: true }], exceptions: [], eligible_services: [], can: { update: false } } } });
    const wrapper = mount(AvailabilityStatus, opts);
    await flushPromises();

    expect(wrapper.text()).toContain('Available');
    expect(wrapper.text()).toContain('08:00–17:00');
    expect(wrapper.findAll('button, input, select, textarea')).toHaveLength(0);
  });
});

describe('UI-14 malformed earnings payload', () => {
  it('shows the retryable error state instead of applying a payload without tab_visibility', async () => {
    respond({ '/personnel/me/earnings': { data: [] }, '/personnel/me/workspace': { data: null } });
    const wrapper = mount(EarningsOverview, opts);
    await flushPromises();

    expect(wrapper.text()).toContain('Unable to load your earnings.');
    expect(wrapper.findComponent({ name: 'PersonnelEarningsNav' }).exists()).toBe(true);
  });
});

describe('UI-14 status vocabulary', () => {
  it('never promotes an unknown status to a positive tone', () => {
    expect(personnelStatusTone('paid')).toBe('success');
    expect(personnelStatusTone('reversed')).toBe('error');
    expect(personnelStatusTone('something_new')).toBe('neutral');
    expect(personnelStatusTone(null)).toBe('neutral');
  });
});
