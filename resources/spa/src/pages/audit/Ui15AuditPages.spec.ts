import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
  primeCsrfCookie: vi.fn(),
}));

import AuditEventCard from '@/components/audit/AuditEventCard.vue';
import AuditFlagDialog from '@/components/audit/AuditFlagDialog.vue';
import AuditDashboard from '@/pages/audit/AuditDashboard.vue';
import AuditEventDetail from '@/pages/audit/AuditEventDetail.vue';
import AuditExportHistory from '@/pages/audit/AuditExportHistory.vue';
import AuditIntegrity from '@/pages/audit/AuditIntegrity.vue';
import { useAuthStore } from '@/stores/authStore';
import type { AuditEventView } from '@/stores/auditEventStore';

const EVENT = '01JB0000000000000000E00001';
const Stub = { template: '<div />' };
const NAMES = [
  'audit.dashboard', 'audit.audit', 'audit.audit-flags', 'audit.flag-detail', 'audit.audit-event-detail', 'audit.audit-operations',
  'audit.audit-finance', 'audit.audit-compensation', 'audit.audit-access', 'audit.audit-privacy-preferred', 'audit.audit-integrity',
  'audit.exports', 'audit.exports-history', 'audit.export-detail', 'audit.account', 'auth.mfa.challenge',
];

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: NAMES.map((name, index) => ({
      name,
      path: name === 'audit.audit-event-detail' ? '/audit/:eventUlid' : name === 'audit.flag-detail' ? '/audit/flags/:flagUlid' : name === 'audit.export-detail' ? '/exports/:exportUlid' : `/r${index}`,
      component: Stub,
    })),
  });
}

async function mountAt(component: object, path: string, props: Record<string, unknown> = {}) {
  const router = makeRouter();
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { props, global: { plugins: [router], stubs: { teleport: true } } });
  await flushPromises();
  return wrapper;
}

function event(overrides: Partial<AuditEventView> = {}): AuditEventView {
  return {
    id: EVENT,
    action: 'customer_payment.duplicate_override_approved',
    severity: 'critical',
    actor: 'j***@salon.co.ke',
    branch: '01JBRANCH00000000000000001',
    subject_type: 'PaymentRecordingGroup',
    context: { reference: '***7Q2X' },
    correlation_id: 'corr-1',
    created_at: '2026-10-11T08:30:00Z',
    review: null,
    ...overrides,
  };
}

function grant(...permissions: string[]): void {
  const auth = useAuthStore();
  auth.permissions = permissions;
  auth.branchIds = ['01JBRANCH00000000000000001'];
}

describe('Phase UI-15 Audit pages', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('shows the event severity and the review status as two different facts', async () => {
    const wrapper = await mountAt(AuditEventCard, '/r0', {
      event: event({ review: { flag: '01JFLAG0000000000000000001', status: 'under_review', flag_count: 1 } }),
      to: '/r1',
      canFlag: true,
    });

    expect(wrapper.find('[data-testid="audit-severity"]').attributes('data-severity')).toBe('critical');
    expect(wrapper.find('[data-testid="audit-review-badge"]').attributes('data-review')).toBe('under_review');
    // An event already under active review is not offered a second flag.
    expect(wrapper.find('[data-testid="audit-event-flag"]').exists()).toBe(false);
    expect(wrapper.html()).not.toMatch(/previous_hash|"hash"/);
  });

  it('offers a flag only when permitted and no active review exists', async () => {
    const permitted = await mountAt(AuditEventCard, '/r0', { event: event(), to: '/r1', canFlag: true });
    expect(permitted.find('[data-testid="audit-event-flag"]').exists()).toBe(true);

    const denied = await mountAt(AuditEventCard, '/r0', { event: event(), to: '/r1', canFlag: false });
    expect(denied.find('[data-testid="audit-event-flag"]').exists()).toBe(false);
  });

  it('creates a flag with review metadata only — the audit row id and an optional note', async () => {
    post.mockResolvedValueOnce({ data: { data: { id: 'f1', status: 'open' } } });
    const wrapper = await mountAt(AuditFlagDialog, '/r0', { event: event() });

    await wrapper.find('textarea').setValue('  Override looks wrong  ');
    await wrapper.find('[data-testid="audit-flag-confirm"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/audit-flagged-events', { audit_log: EVENT, note: 'Override looks wrong' });
    expect(wrapper.emitted('flagged')).toHaveLength(1);
  });

  it('never calls a partial chain check verified', async () => {
    grant('audit.branch_events.view');
    get.mockResolvedValueOnce({ data: { data: {
      status: 'partial', checked_at: '2026-10-11T09:00:00Z', segment_limit: 1000, complete_history: false,
      coverage_from: '2026-09-01T00:00:00Z', coverage_to: '2026-10-11T08:59:00Z', branch_records_checked: 412,
      first_break: null, scheduled_verification_exposed: false,
    } } });
    const wrapper = await mountAt(AuditIntegrity, '/r10');

    const status = wrapper.find('[data-testid="audit-integrity-status"]').text();
    expect(status).toContain('Recent records verified');
    expect(status).not.toMatch(/:\s*Verified$/);
    expect(wrapper.text()).toContain('Older records were not part of this check');
  });

  it('shows no integrity status at all when the check fails, and does not name a break outside the branch', async () => {
    grant('audit.branch_events.view');
    get.mockRejectedValueOnce({ response: { status: 500 } });
    const failed = await mountAt(AuditIntegrity, '/r10');
    expect(failed.find('[data-testid="audit-integrity-status"]').exists()).toBe(false);
    expect(failed.find('[data-testid="audit-integrity-failed"]').exists()).toBe(true);

    get.mockResolvedValueOnce({ data: { data: {
      status: 'broken', checked_at: '2026-10-11T09:00:00Z', segment_limit: 1000, complete_history: true,
      coverage_from: null, coverage_to: null, branch_records_checked: 3,
      first_break: { category: 'hash_mismatch', within_branch_scope: false, event: null, occurred_at: null },
      scheduled_verification_exposed: false,
    } } });
    const broken = await mountAt(AuditIntegrity, '/r10');
    expect(broken.find('[data-testid="audit-integrity-break"]').text()).toContain('another branch');
    expect(broken.find('[data-testid="audit-integrity-open-event"]').exists()).toBe(false);
  });

  it('reports a slice the account may not read as not permitted — never as zero', async () => {
    grant('audit.branch_events.view');
    get.mockImplementation((url: string, config?: { params?: Record<string, unknown> }) => {
      if (url === '/audit-integrity') return Promise.reject({ response: { status: 403 } });
      if (url === '/auth/account-contexts') return Promise.resolve({ data: { data: [] } });
      const total = config?.params?.severity === 'critical' ? 2 : 7;
      return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1, total } } });
    });
    const wrapper = await mountAt(AuditDashboard, '/r0');

    expect(wrapper.find('[data-testid="audit-dashboard-critical"]').text()).toContain('2');
    expect(wrapper.find('[data-testid="audit-dashboard-slice-finance"]').text()).toContain('Not permitted');
    expect(wrapper.find('[data-testid="audit-dashboard-slice-compensation"]').text()).toContain('Not permitted');
    expect(wrapper.find('[data-testid="audit-dashboard-slice-finance"]').text()).not.toMatch(/\b0\b/);
    // The finance endpoint is never requested without its permission.
    expect(get.mock.calls.some(([url]) => url === '/audit-logs/finance')).toBe(false);
  });

  it('treats an unknown or foreign event id as unavailable without rendering evidence', async () => {
    grant('audit.branch_events.view');
    get.mockRejectedValueOnce({ response: { status: 404 } });
    const wrapper = await mountAt(AuditEventDetail, `/audit/${EVENT}`);

    expect(wrapper.find('[data-testid="audit-detail-unavailable"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="audit-detail-action"]').exists()).toBe(false);
  });

  it('renders the structured change, not a raw JSON dump', async () => {
    grant('audit.branch_events.view');
    get.mockResolvedValueOnce({ data: { data: event({
      action: 'branch.profile_updated', severity: 'notice',
      context: { old_values: { name: 'Westlands' }, new_values: { name: 'Westlands Studio' } },
    }) } });
    const wrapper = await mountAt(AuditEventDetail, `/audit/${EVENT}`);

    const diff = wrapper.find('[data-testid="audit-diff"]');
    expect(diff.text()).toContain('Westlands Studio');
    expect(wrapper.html()).not.toContain('{"old_values"');
  });

  it('shows expired exports as unavailable with no download control', async () => {
    grant('audit.export');
    get.mockResolvedValueOnce({ data: { data: [{
      id: '01JB0000000000000000X00003', branch: { id: 'b1', name: 'Westlands' }, status: 'expired', reason: 'Quarterly review',
      scope: { domains: ['general'], severities: [], has_date_from: false, has_date_to: false }, row_count: 12, download_count: 2,
      requested_at: '2026-10-01T08:00:00Z', generated_at: null, expires_at: '2026-10-02T08:00:00Z', first_downloaded_at: null,
      last_downloaded_at: null, failure_code: null, failure_message: null, created_at: '2026-10-01T08:00:00Z',
      can: { view: true, download: false, revoke: false },
    }], meta: { current_page: 1, last_page: 1, total: 1 } } });
    const wrapper = await mountAt(AuditExportHistory, '/r12');

    expect(wrapper.text()).toContain('No longer available');
    expect(wrapper.find('[data-testid="audit-export-download"]').exists()).toBe(false);
    expect(wrapper.html()).not.toMatch(/signature=|\/storage\//);
  });
});
