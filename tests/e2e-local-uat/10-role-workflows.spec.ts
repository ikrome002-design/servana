/**
 * LOCAL-UAT-001 §7C — representative business workflows through the REAL stack, each performed by the
 * role that owns it, from that role's own signed-in browser session on its own host. Calls are
 * same-origin `fetch` from the page (cookies + XSRF, exactly like the SPA), so every request crosses
 * nginx -> Laravel/Sanctum -> policies -> PostgreSQL/Redis/queue.
 *
 * The chain leaves real, durable history for the owner to explore: an opened branch day and queue,
 * walk-ins, a completed service session, issued invoices, offline payment records (one validated with
 * a receipt, one left pending for Finance), a submitted cash-up, and the audit events all of that
 * produced. Each run adds one more such day of history; it never edits or deletes existing records.
 */
import { expect, test, type Page } from '@playwright/test';

import {
  ACCOUNTS,
  api,
  businessDate,
  fixtureManifest,
  get,
  idempotencyKey,
  identity,
  statePath,
  writeResult,
  writeRuntime,
  type AccountKey,
  type ApiResult,
} from './support/localUat';

test.describe.configure({ mode: 'serial' });

const steps: { step: string; role: AccountKey; status: number; result: string; detail?: string }[] = [];
test.afterAll(() => writeResult('workflows.json', steps));

function record(step: string, role: AccountKey, res: ApiResult, expected: number[]): void {
  const ok = expected.includes(res.status);
  steps.push({
    step,
    role,
    status: res.status,
    result: ok ? 'pass' : 'fail',
    ...(ok ? {} : { detail: JSON.stringify(res.body).slice(0, 600) }),
  });
  expect(expected, `${step}: HTTP ${res.status} ${JSON.stringify(res.body).slice(0, 600)}`).toContain(res.status);
}

async function asRole(browser: import('@playwright/test').Browser, account: AccountKey): Promise<Page> {
  const context = await browser.newContext({ storageState: statePath(account) });

  return context.newPage();
}

const fixture = fixtureManifest();
const westlands = fixture.branches['WST']?.ulid ?? '';
const shared: Record<string, string> = {};

test('Branch: open the business day and the queue', async ({ browser }) => {
  const page = await asRole(browser, 'merchant_branch');

  const open = await api(page, 'merchant_branch', 'POST', `/api/v1/branches/${westlands}/day/open`);
  // 409/422: today's day is already open from an earlier run — still a valid state to continue from.
  record('branch.day.open', 'merchant_branch', open, [200, 201, 409, 422]);

  const queue = await api(page, 'merchant_branch', 'PUT', '/api/v1/queue/configuration', { branch_id: westlands, queue_is_open: true });
  record('branch.queue.open', 'merchant_branch', queue, [200]);
  expect(get(queue.body, 'data.queue_is_open')).toBe(true);
});

test('Front Office: client, walk-ins, service session, invoice and offline payment', async ({ browser }) => {
  const page = await asRole(browser, 'merchant_front_office');

  // LUAT-D01 (resolved): Front Office reads its OWN narrow selection endpoint; the Branch Manager
  // catalogue read stays refused to it (`service.view` is unchanged).
  record('front_office.catalogue_still_refused', 'merchant_front_office', await api(page, 'merchant_front_office', 'GET', '/api/v1/services?status=active'), [403]);
  const services = await api(page, 'merchant_front_office', 'GET', '/api/v1/front-office/service-options?purpose=walk_in');
  record('front_office.service_options', 'merchant_front_office', services, [200]);
  const serviceList = (get(services.body, 'data') as { id: string; name: string; duration_minutes: number }[]) ?? [];
  const service = serviceList.find((s) => s.name === 'Classic haircut') ?? serviceList[0];
  expect(service, 'a bookable service').toBeDefined();

  const stamp = Date.now().toString().slice(-6);
  const client = await api(page, 'merchant_front_office', 'POST', '/api/v1/clients', {
    branch_id: westlands,
    full_name: `UAT Walk-in ${stamp}`,
    phone: `+2547${stamp.padStart(8, '1')}`,
  });
  record('front_office.client.create', 'merchant_front_office', client, [201]);
  const clientUlid = String(get(client.body, 'data.id'));

  const walkIn = await api(page, 'merchant_front_office', 'POST', '/api/v1/walk-ins', {
    branch_id: westlands,
    assignment_mode: 'next_available',
    service: service?.id,
    client: clientUlid,
  });
  record('front_office.walk_in.create', 'merchant_front_office', walkIn, [201]);
  const entry = String(get(walkIn.body, 'data.id'));

  record('front_office.queue.call', 'merchant_front_office', await api(page, 'merchant_front_office', 'POST', `/api/v1/queue-entries/${entry}/call`), [200]);
  const started = await api(page, 'merchant_front_office', 'POST', `/api/v1/queue-entries/${entry}/start`);
  record('front_office.queue.start', 'merchant_front_office', started, [200]);
  record('front_office.queue.complete', 'merchant_front_office', await api(page, 'merchant_front_office', 'POST', `/api/v1/queue-entries/${entry}/complete`), [200]);

  const sessions = await api(page, 'merchant_front_office', 'GET', '/api/v1/service-sessions?status=completed&per_page=100');
  record('front_office.sessions.list', 'merchant_front_office', sessions, [200]);
  const completedSessions = (get(sessions.body, 'data') as { id: string; status: string; client?: { id: string } }[]) ?? [];
  const session = completedSessions.find((s) => s.client?.id === clientUlid);
  expect(session, 'the completed session from this walk-in').toBeDefined();

  // A second walk-in stays WAITING, so the queue-transfer screen has a real entry to open.
  const waiting = await api(page, 'merchant_front_office', 'POST', '/api/v1/walk-ins', {
    branch_id: westlands,
    assignment_mode: 'next_available',
    service: service?.id,
    new_client: { full_name: `UAT Waiting ${stamp}`, phone: `+2547${stamp.padStart(8, '2')}` },
  });
  record('front_office.walk_in.waiting', 'merchant_front_office', waiting, [201]);
  shared['queueUlid'] = String(get(waiting.body, 'data.id'));

  const invoice = await api(page, 'merchant_front_office', 'POST', '/api/v1/invoices', { client_id: clientUlid, service_session_ids: [session?.id] });
  record('front_office.invoice.create', 'merchant_front_office', invoice, [201]);
  const invoiceUlid = String(get(invoice.body, 'data.id'));
  const finalized = await api(page, 'merchant_front_office', 'POST', `/api/v1/invoices/${invoiceUlid}/finalize`, undefined, idempotencyKey());
  record('front_office.invoice.finalize', 'merchant_front_office', finalized, [200]);
  const total = Number(get(finalized.body, 'data.balance.amount'));
  expect(total).toBeGreaterThan(0);

  // Offline cash payment for the full amount -> pending Finance validation (Front Office records,
  // never validates).
  const payment = await api(page, 'merchant_front_office', 'POST', `/api/v1/invoices/${invoiceUlid}/payment-recording-groups`, {
    components: [{ method: 'cash', amount_minor: total }],
  }, idempotencyKey());
  record('front_office.payment.record_cash', 'merchant_front_office', payment, [201]);
  shared['validatedGroupUlid'] = String(get(payment.body, 'data.id'));

  // A second invoice from a seeded, not-yet-invoiced session (the backend refuses an already-invoiced
  // one), paid by an offline M-Pesa reference and LEFT pending, so Finance's validation queue always
  // has work in it.
  for (const candidate of completedSessions.filter((s) => s.id !== session?.id && s.client !== undefined)) {
    const second = await api(page, 'merchant_front_office', 'POST', '/api/v1/invoices', { client_id: candidate.client?.id, service_session_ids: [candidate.id] });
    if (second.status !== 201) continue;
    record('front_office.invoice.create_from_seeded_session', 'merchant_front_office', second, [201]);
    const secondUlid = String(get(second.body, 'data.id'));
    const secondFinal = await api(page, 'merchant_front_office', 'POST', `/api/v1/invoices/${secondUlid}/finalize`, undefined, idempotencyKey());
    record('front_office.invoice.finalize_second', 'merchant_front_office', secondFinal, [200]);
    const pending = await api(page, 'merchant_front_office', 'POST', `/api/v1/invoices/${secondUlid}/payment-recording-groups`, {
      components: [{ method: 'mpesa_offline', amount_minor: Number(get(secondFinal.body, 'data.balance.amount')), reference: `UAT${stamp}MP` }],
    }, idempotencyKey());
    record('front_office.payment.record_mpesa_offline', 'merchant_front_office', pending, [201]);
    shared['pendingGroupUlid'] = String(get(pending.body, 'data.id'));
    break;
  }
  // An issued invoice with nothing paid yet, for the record-payment screen.
  const third = await api(page, 'merchant_front_office', 'GET', '/api/v1/invoices?status=issued&per_page=50');
  record('front_office.invoices.list', 'merchant_front_office', third, [200]);

  // Tomorrow's appointment, booked by Front Office.
  const tomorrow = `${businessDate(1)}T15:00:00+03:00`;
  const appointment = await api(page, 'merchant_front_office', 'POST', '/api/v1/appointments', {
    branch_id: westlands,
    client: clientUlid,
    service: service?.id,
    starts_at: tomorrow,
  });
  record('front_office.appointment.create', 'merchant_front_office', appointment, [201, 422]);

  shared['invoiceUlid'] = invoiceUlid;
  shared['clientUlid'] = clientUlid;
  writeRuntime(shared);
});

test('Finance: validate the cash payment (maker/checker) and see the receipt', async ({ browser }) => {
  const page = await asRole(browser, 'merchant_finance');

  const validate = await api(page, 'merchant_finance', 'POST', `/api/v1/payment-recording-groups/${shared['validatedGroupUlid']}/validate`, undefined, idempotencyKey());
  record('finance.payment.validate', 'merchant_finance', validate, [201]);
  expect(get(validate.body, 'data.group.status')).toBe('validated');
  expect(get(validate.body, 'data.receipt.id'), 'a receipt is issued only after validation').toBeTruthy();

  const receipts = await api(page, 'merchant_finance', 'GET', '/api/v1/receipts?per_page=50');
  record('finance.receipts.list', 'merchant_finance', receipts, [200]);
  expect(((get(receipts.body, 'data') as unknown[]) ?? []).length).toBeGreaterThan(0);

  const groups = await api(page, 'merchant_finance', 'GET', '/api/v1/payment-recording-groups?status=pending_validation&per_page=50');
  record('finance.validation_queue.list', 'merchant_finance', groups, [200]);
  const pending = ((get(groups.body, 'data') as { id: string }[]) ?? [])[0]?.id;
  writeRuntime({ groupUlid: pending ?? String(shared['validatedGroupUlid']) });
});

test('Branch: submit today\'s cash-up for Finance', async ({ browser }) => {
  const page = await asRole(browser, 'merchant_branch');
  const date = businessDate();

  const draft = await api(page, 'merchant_branch', 'PUT', `/api/v1/branches/${westlands}/cash-ups/${date}`, { counts: [{ method: 'cash', counted_minor: 0 }] });
  // 409/422: the day's cash-up was already submitted by an earlier run.
  record('branch.cash_up.draft', 'merchant_branch', draft, [200, 201, 409, 422]);
  const cashUp = get(draft.body, 'data.id');
  if (draft.status < 300 && typeof cashUp === 'string') {
    record('branch.cash_up.submit', 'merchant_branch', await api(page, 'merchant_branch', 'POST', `/api/v1/cash-ups/${cashUp}/submit`, undefined, idempotencyKey()), [200, 422]);
  }
});

test('Human Resource: roster, eligibility and payout preparation (never approval)', async ({ browser }) => {
  const page = await asRole(browser, 'merchant_human_resource');

  record('hr.staff.list', 'merchant_human_resource', await api(page, 'merchant_human_resource', 'GET', '/api/v1/staff?per_page=50'), [200]);
  const date = businessDate();
  const run = await api(page, 'merchant_human_resource', 'POST', '/api/v1/hr/payout-runs', {
    branch_ulid: westlands,
    period_start: `${date.slice(0, 8)}01`,
    period_end: date,
    currency: 'KES',
  });
  // 422: nothing eligible in the period yet, or an overlapping run from an earlier pass.
  record('hr.payout_run.draft', 'merchant_human_resource', run, [201, 422]);

  // HR prepares; Finance verifies/approves/marks paid. HR may not reach the Finance surface.
  record('hr.cannot_approve_payout', 'merchant_human_resource', await api(page, 'merchant_human_resource', 'GET', '/api/v1/finance/payout-runs'), [403]);
});

test('Personnel: own work and earnings, SMS preview without sending', async ({ browser }) => {
  const page = await asRole(browser, 'merchant_personnel');

  record('personnel.my_appointments', 'merchant_personnel', await api(page, 'merchant_personnel', 'GET', '/api/v1/personnel/me/appointments'), [200]);
  record('personnel.my_earnings', 'merchant_personnel', await api(page, 'merchant_personnel', 'GET', '/api/v1/personnel/me/earnings'), [200]);
  const served = await api(page, 'merchant_personnel', 'GET', '/api/v1/personnel/me/served-clients/sms');
  record('personnel.served_clients', 'merchant_personnel', served, [200]);
  const clientUlids = ((get(served.body, 'data') as { id: string }[]) ?? []).map((c) => c.id).slice(0, 2);
  if (clientUlids.length > 0) {
    const preview = await api(page, 'merchant_personnel', 'POST', '/api/v1/personnel/me/sms-campaigns/preview', {
      message_body: 'Thank you for visiting Acacia Wellness Studio today.',
      client_ulids: clientUlids,
    });
    // Preview only: nothing is queued for sending, and no contact list is ever returned for export.
    record('personnel.sms.preview', 'merchant_personnel', preview, [200]);
  }
});

test('Audit: branch-scoped events from the workflow are visible, read-only', async ({ browser }) => {
  const page = await asRole(browser, 'merchant_audit');

  const events = await api(page, 'merchant_audit', 'GET', `/api/v1/branches/${westlands}/audit-events?per_page=50`);
  record('audit.branch_events.list', 'merchant_audit', events, [200]);
  const list = (get(events.body, 'data') as { id: string }[]) ?? [];
  expect(list.length).toBeGreaterThan(0);
  writeRuntime({ eventUlid: list[0]?.id ?? '' });

  // Read-only: Audit can create nothing.
  record('audit.cannot_create_client', 'merchant_audit', await api(page, 'merchant_audit', 'POST', '/api/v1/clients', { full_name: 'Not allowed', phone: '+254700000999' }), [403]);
});

test('Server-side boundaries hold for real sessions (role, branch and tenant)', async ({ browser }) => {
  const fo = await asRole(browser, 'merchant_front_office');
  // Front Office records but never validates.
  record('negative.front_office_cannot_validate', 'merchant_front_office',
    await api(fo, 'merchant_front_office', 'POST', `/api/v1/payment-recording-groups/${shared['pendingGroupUlid'] ?? shared['validatedGroupUlid']}/validate`, undefined, idempotencyKey()), [403]);
  // A foreign tenant's client ULID resolves inside tenant scope -> 404, never 403 (no existence leak).
  record('negative.cross_tenant_client', 'merchant_front_office', await api(fo, 'merchant_front_office', 'GET', `/api/v1/clients/${fixture.baobab_client_ulid}`), [404]);
  // Another branch of the SAME merchant is out of a Westlands user's branch scope.
  record('negative.cross_branch_client', 'merchant_front_office', await api(fo, 'merchant_front_office', 'GET', `/api/v1/clients/${fixture.kilimani_client_ulid}`), [403, 404]);

  const branch = await asRole(browser, 'merchant_branch');
  record('negative.branch_cannot_validate', 'merchant_branch',
    await api(branch, 'merchant_branch', 'POST', `/api/v1/payment-recording-groups/${shared['pendingGroupUlid'] ?? shared['validatedGroupUlid']}/validate`, undefined, idempotencyKey()), [403]);

  const personnel = await asRole(browser, 'merchant_personnel');
  record('negative.personnel_cannot_list_clients', 'merchant_personnel', await api(personnel, 'merchant_personnel', 'GET', '/api/v1/clients'), [403]);

  // The host is never authorization: a merchant session cookie is host-only, so the same browser
  // carries no session on the platform host.
  const platform = await api(fo, 'super_administrator', 'GET', '/api/v1/me');
  record('negative.no_session_on_other_host', 'merchant_front_office', platform, [401]);

  writeRuntime({ staffUlid: identity('stylist@acacia.servana.test').staff_profile_ulid ?? '' });
  expect(ACCOUNTS.merchant_front_office.host).toBe('office.servana.test');
});
