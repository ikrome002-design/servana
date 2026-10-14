import type { Page } from '@playwright/test';
import { IDS, type AuditScreen } from './releaseAudit';

/**
 * COR-UI08-UI12-VIS-001 — a POPULATED overlay for the corrective evidence capture.
 *
 * The deterministic UI-16 harness answers every unmatched GET with an empty page, so 22 of the 82
 * corrective pages render their (legitimate) empty state. A reviewer judging a redesigned ledger, a
 * lifecycle board or a reconciliation sheet needs to see it with rows in it, so this overlay adds a
 * small, fixed set of SYNTHETIC records for exactly those reads. Every name is invented, every
 * phone and reference is masked, and every amount is integer minor units with the server-formatted
 * string beside it — the overlay never computes money, it only supplies what a server would.
 *
 * It is registered AFTER `prepareVisual`, so Playwright consults it first; a path it does not know
 * falls back to the harness unchanged. It is used only by the evidence writer and the corrective
 * visual-regression gate — never by a functional test.
 */

function id(label: string): string {
  return `01HZZ${label}`.padEnd(26, '0').slice(0, 26);
}

const kes = (minor: number) => ({
  amount: minor,
  currency: 'KES',
  formatted: `KES ${(minor / 100).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
});
const page1 = (total: number, perPage = 20) => ({ current_page: 1, last_page: 1, per_page: perPage, total });
const list = <T>(rows: T[], perPage = 20) => ({ data: rows, meta: page1(rows.length, perPage) });

// --- Branch -------------------------------------------------------------------------------------

const CATEGORIES = [
  { id: id('CATHAIR'), name: 'Hair', sort_order: 1, archived: false, services_count: 3 },
  { id: id('CATNAIL'), name: 'Nails', sort_order: 2, archived: false, services_count: 2 },
  { id: id('CATSPA'), name: 'Spa and massage', sort_order: 3, archived: false, services_count: 2 },
];

const svc = (key: string, name: string, cat: number, price: number, minutes: number, status = 'active') => ({
  id: id(`SVC${key}`),
  category_id: CATEGORIES[cat]!.id,
  category_name: CATEGORIES[cat]!.name,
  name,
  description: null,
  price_minor: price,
  currency: 'KES',
  duration_minutes: minutes,
  status,
  branch_id: IDS.branch,
  can: { view: true, update: true, archive: true },
});

const SERVICES = [
  svc('CUT', 'Signature cut and finish', 0, 250000, 45),
  svc('BRAID', 'Knotless braids, mid-back', 0, 650000, 240),
  svc('COLOUR', 'Root colour refresh', 0, 400000, 90),
  svc('GEL', 'Gel manicure', 1, 180000, 50),
  svc('PEDI', 'Spa pedicure', 1, 220000, 60, 'archived'),
  svc('MASSAGE', 'Deep-tissue massage, 60 min', 2, 450000, 60),
  svc('FACIAL', 'Hydrating facial', 2, 350000, 50),
];

const client = (key: string, name: string, last4: string) => ({
  id: id(`CL${key}`),
  full_name: name,
  phone_masked: `+2547•••••${last4.slice(1)}`,
  phone_last_four: last4,
});

const person = (key: string, name: string) => ({ id: id(`ST${key}`), display_name: name });
const PEOPLE = [person('AMINA', 'Amina Wanjiku'), person('BRIAN', 'Brian Otieno'), person('CAROL', 'Carol Achieng'), person('DAVID', 'David Kiprop')];

const wait = (minutes: number) => ({ label: `About ${minutes} minutes`, minutes, override_minutes: null, override_reason: null, effective_minutes: minutes });
const queue = (n: number, status: string, at: string, service: number, c: ReturnType<typeof client>, assigned: number | null, minutes: number) => ({
  id: id(`QUEUE${n}`),
  status,
  position: n,
  assignment_mode: assigned === null ? 'next_available' : 'manual',
  source: n % 2 === 0 ? 'appointment' : 'walk_in',
  queued_at: at,
  assigned_at: assigned === null ? null : at,
  called_at: status === 'called' || status === 'in_service' ? at : null,
  started_at: status === 'in_service' ? at : null,
  completed_at: null,
  cancelled_at: null,
  no_show_at: null,
  transferred_at: null,
  cancellation_reason: null,
  transfer_reason: null,
  preferred_personnel_override_reason: null,
  estimated_wait: wait(minutes),
  service: { id: SERVICES[service]!.id, name: SERVICES[service]!.name, duration_minutes: SERVICES[service]!.duration_minutes },
  client: c,
  assigned_personnel: assigned === null ? null : PEOPLE[assigned],
  preferred_personnel: null,
  can: { view: true, assign: false, call: false, start: false, complete: false, transfer: false, cancel: false, no_show: false },
});

const QUEUE = [
  queue(1, 'in_service', '2026-07-15T06:10:00+00:00', 0, client('A', 'Wanjiru Mwangi', '4412'), 0, 0),
  queue(2, 'called', '2026-07-15T06:25:00+00:00', 3, client('B', 'Faith Njoroge', '7720'), 2, 5),
  queue(3, 'assigned', '2026-07-15T06:40:00+00:00', 5, client('C', 'Kevin Mutua', '3051'), 3, 15),
  queue(4, 'waiting', '2026-07-15T06:55:00+00:00', 1, client('D', 'Halima Yusuf', '9184'), null, 30),
  queue(5, 'waiting', '2026-07-15T07:05:00+00:00', 6, client('E', 'Grace Chebet', '6637'), null, 45),
];

const appt = (n: number, status: string, start: string, end: string, service: number, c: ReturnType<typeof client>, assigned: number) => ({
  id: id(`APPT${n}`),
  status,
  starts_at: start,
  ends_at: end,
  checked_in_at: status === 'checked_in' ? start : null,
  cancelled_at: status === 'cancelled' ? '2026-07-14T15:00:00+00:00' : null,
  no_show_at: null,
  cancellation_reason: status === 'cancelled' ? 'Client rescheduled by phone' : null,
  service: { id: SERVICES[service]!.id, name: SERVICES[service]!.name, duration_minutes: SERVICES[service]!.duration_minutes },
  client: c,
  preferred_personnel: null,
  assigned_personnel: PEOPLE[assigned],
  can: { view: true, assign: false, transfer: false, reschedule: false, check_in: false, cancel: false, no_show: false },
});

const APPOINTMENTS = [
  appt(1, 'checked_in', '2026-07-15T06:00:00+00:00', '2026-07-15T06:45:00+00:00', 0, client('F', 'Mercy Atieno', '2290'), 0),
  appt(2, 'confirmed', '2026-07-15T07:30:00+00:00', '2026-07-15T08:30:00+00:00', 5, client('G', 'Samuel Kariuki', '5508'), 3),
  appt(3, 'confirmed', '2026-07-15T09:00:00+00:00', '2026-07-15T13:00:00+00:00', 1, client('H', 'Joyce Wambui', '1176'), 1),
  appt(4, 'cancelled', '2026-07-15T11:00:00+00:00', '2026-07-15T11:50:00+00:00', 6, client('I', 'Linet Nyambura', '8843'), 2),
  appt(5, 'confirmed', '2026-07-15T13:30:00+00:00', '2026-07-15T14:20:00+00:00', 3, client('J', 'Ruth Kamau', '4025'), 2),
];

const CALENDAR = [
  { date: '2026-07-24', type: 'modified_hours', closes_branch: false, opens_at: '10:00', closes_at: '16:00', reason: 'Late opening for the monthly team meeting', created_at: '2026-07-01T08:00:00+00:00' },
  { date: '2026-08-12', type: 'public_holiday', closes_branch: true, opens_at: null, closes_at: null, reason: 'Public holiday — branch closed all day', created_at: '2026-07-01T08:05:00+00:00' },
  { date: '2026-08-20', type: 'special_closure', closes_branch: true, opens_at: null, closes_at: null, reason: 'Annual staff training day', created_at: '2026-07-01T08:10:00+00:00' },
  { date: '2026-09-05', type: 'modified_hours', closes_branch: false, opens_at: '08:00', closes_at: '13:00', reason: 'Half day for the building maintenance window', created_at: '2026-07-01T08:15:00+00:00' },
];

const recurring = (weekday: number, start: string, end: string, available = true) => ({ weekday, start_time: start, end_time: end, available });
const AVAILABILITY = {
  timezone: 'Africa/Nairobi',
  current_state: 'available',
  recurring: [
    ...[1, 2, 3, 4, 5].flatMap((d) => [recurring(d, '08:00', '17:00'), recurring(d, '13:00', '14:00', false)]),
    recurring(6, '09:00', '15:00'),
  ],
  exceptions: [{ date: '2026-07-22', start_time: '08:00', end_time: '12:00', available: false }],
  eligible_services: SERVICES.slice(0, 4).filter((s) => s.status === 'active').map((s) => ({ id: s.id, name: s.name })),
  can: { update: true },
};

// --- Human Resource -----------------------------------------------------------------------------

const RULE = {
  id: id('RULE1'), status: 'active', status_label: 'Active', calculation_type: 'percentage', calculation_basis: 'service_price',
  applies_to: 'all_services', service_category_id: null, selected_service_ulids: [], selected_services: [],
  percentage_basis_points: 1500, fixed_amount_minor: null, currency: 'KES', applies_to_preferred_personnel_fee: false,
  effective_from: '2026-06-01', effective_to: null, notes: null, change_reason: 'Standard stylist commission', is_editable: false,
  created_at: '2026-05-20T08:00:00+00:00', approved_at: '2026-05-22T08:00:00+00:00',
};

const capabilities = { can_update_draft: false, can_submit: false, can_approve: false, can_reject: false, can_cancel: false, is_terminal: false };
const plan = (key: string, staff: string, staffName: string, model: string, label: string, status: string, statusLabel: string, salary: number | null, from: string, to: string | null) => ({
  id: id(`PLAN${key}`), status, status_label: statusLabel, staff_profile_id: staff, staff_display_name: staffName, branch_id: IDS.branch,
  compensation_model: model, compensation_model_label: label,
  salary_amount_minor: salary, salary_currency: salary === null ? null : 'KES', salary_period: salary === null ? null : 'monthly', salary_payout_day: salary === null ? null : 28,
  commission_rule: model === 'salary_only' ? undefined : RULE,
  effective_from: from, effective_to: to, is_backdated: false, supersedes_plan_id: null, notes: null,
  change_reason: 'Annual terms review', submitted_at: '2026-05-25T08:00:00+00:00', approved_at: status === 'draft' ? null : '2026-05-27T08:00:00+00:00',
  rejected_at: null, created_at: '2026-05-24T08:00:00+00:00', updated_at: '2026-05-27T08:00:00+00:00',
  capabilities: { ...capabilities, is_terminal: status === 'superseded' },
});

const PLANS = [
  plan('A1', IDS.staff, 'Amina Wanjiku', 'salary_plus_commission', 'Salary plus commission', 'active', 'Active', 4500000, '2026-06-01', null),
  plan('A0', IDS.staff, 'Amina Wanjiku', 'salary_only', 'Salary only', 'superseded', 'Superseded', 4000000, '2026-01-01', '2026-05-31'),
  plan('B1', id('STBRIAN'), 'Brian Otieno', 'commission_only', 'Commission only', 'active', 'Active', null, '2026-06-01', null),
  plan('C1', id('STCAROL'), 'Carol Achieng', 'salary_only', 'Salary only', 'scheduled', 'Scheduled', 3800000, '2026-08-01', null),
  plan('D1', id('STDAVID'), 'David Kiprop', 'salary_plus_commission', 'Salary plus commission', 'draft', 'Draft', 3500000, '2026-08-01', null),
];

const history = (n: number, event: string, label: string, from: string | null, to: string, at: string, reason: string, effective: string) => ({
  id: id(`HIST${n}`), event, event_label: label, from_status: from, to_status: to, changed_fields: null, was_backdated: false,
  change_reason: reason, effective_from: effective, actor_display_name: n % 2 === 0 ? 'Ada Mwangi' : 'Rose Muthoni', occurred_at: at,
});
const HISTORY: Record<string, unknown[]> = {
  [id('PLANA1')]: [
    history(1, 'created', 'Created', null, 'draft', '2026-05-24T08:00:00+00:00', 'Annual terms review', '2026-06-01'),
    history(2, 'submitted', 'Submitted', 'draft', 'pending_approval', '2026-05-25T08:00:00+00:00', 'Ready for approval', '2026-06-01'),
    history(3, 'approved', 'Approved', 'pending_approval', 'scheduled', '2026-05-27T08:00:00+00:00', 'Approved by the owner', '2026-06-01'),
    history(4, 'activated', 'Activated', 'scheduled', 'active', '2026-06-01T00:00:00+00:00', 'Effective date reached', '2026-06-01'),
  ],
  [id('PLANA0')]: [
    history(5, 'superseded', 'Superseded', 'active', 'superseded', '2026-06-01T00:00:00+00:00', 'Replaced by the June terms', '2026-01-01'),
  ],
};

const payoutRun = (key: string, status: string, gross: number, items: number, high: boolean, from: string, to: string) => ({
  id: id(`RUN${key}`), branch_id: IDS.branch, period_start: from, period_end: to, currency: 'KES', status, gross_total_minor: gross,
  high_value_threshold_snapshot_minor: 50000000, is_high_value: high, rejection_reason: null,
  has_external_payment_reference: status === 'paid', paid_at: status === 'paid' ? '2026-07-02T09:00:00+00:00' : null,
  item_count: items, created_at: '2026-07-01T08:00:00+00:00', updated_at: '2026-07-03T08:00:00+00:00',
});
const PAYOUT_RUNS = [
  payoutRun('JUL', 'submitted', 31250000, 6, false, '2026-07-01', '2026-07-15'),
  payoutRun('JUNB', 'pending_merchant_admin_approval', 68400000, 8, true, '2026-06-16', '2026-06-30'),
  payoutRun('JUNA', 'approved', 29875000, 6, false, '2026-06-01', '2026-06-15'),
  payoutRun('MAY', 'paid', 57300000, 7, true, '2026-05-16', '2026-05-31'),
];

// --- Finance ------------------------------------------------------------------------------------

const cashUp = (n: number, date: string, status: string, expected: number, counted: number) => ({
  id: id(`CASHUP${n}`), business_date: date, status,
  expected: kes(expected), counted: kes(counted), variance: kes(counted - expected),
  expected_minor: expected, counted_minor: counted, variance_minor: counted - expected, review_note: null, lines: [],
});
const CASH_UPS = [
  cashUp(1, '2026-07-14', 'submitted', 4825000, 4825000),
  cashUp(2, '2026-07-13', 'submitted', 3910000, 3880000),
  cashUp(3, '2026-07-12', 'correction_requested', 5240000, 5190000),
  cashUp(4, '2026-07-11', 'approved', 4470000, 4470000),
  cashUp(5, '2026-07-10', 'locked', 3685000, 3685000),
];

const PERIOD_LOCKS = [
  { id: id('LOCK3'), scope: 'branch', branch: { id: IDS.branch, name: 'Westlands Branch' }, period_start: '2026-06-16', period_end: '2026-06-30', status: 'locked', exception_required: true, reopen_reason: 'Late refund evidence', reopen_requested_at: '2026-07-10T08:00:00+00:00', reopen_approved_at: null, reopened_at: null, created_at: '2026-07-01T08:00:00+00:00' },
  { id: id('LOCK2'), scope: 'merchant', branch: null, period_start: '2026-06-01', period_end: '2026-06-15', status: 'locked', exception_required: false, reopen_reason: null, reopen_requested_at: null, reopen_approved_at: null, reopened_at: null, created_at: '2026-06-16T08:00:00+00:00' },
  { id: id('LOCK1'), scope: 'merchant', branch: null, period_start: '2026-05-16', period_end: '2026-05-31', status: 'reopened', exception_required: false, reopen_reason: 'Duplicate receipt correction', reopen_requested_at: '2026-06-03T08:00:00+00:00', reopen_approved_at: null, reopened_at: '2026-06-03T09:00:00+00:00', created_at: '2026-06-01T08:00:00+00:00' },
];

const receipt = (n: number, number: number, amount: number, invoice: string, reissue: boolean, ready: boolean, at: string) => ({
  id: id(`RCPT${n}`), receipt_number: number, amount: kes(amount), currency: 'KES', is_reissue: reissue, reissue_of: reissue ? id('RCPT9') : null,
  reason: reissue ? 'Client requested a corrected name' : null, downloadable: ready, file_generation_status: ready ? 'ready' : 'pending',
  created_at: at, invoice: { id: id(`INV${n}`), invoice_number: invoice },
});
const RECEIPTS = [
  receipt(1, 1048, 250000, 'WST-INV-000248', false, true, '2026-07-15T07:50:00+00:00'),
  receipt(2, 1047, 650000, 'WST-INV-000246', false, true, '2026-07-15T07:20:00+00:00'),
  receipt(3, 1046, 180000, 'WST-INV-000243', true, false, '2026-07-14T16:05:00+00:00'),
  receipt(4, 1045, 450000, 'WST-INV-000240', false, true, '2026-07-14T12:40:00+00:00'),
];

const dispute = (n: number, status: string, reason: string, invoice: string, evidence: boolean, at: string) => ({
  id: id(`DISP${n}`), status, reason, resolution_note: status === 'resolved' ? 'Receipt reissued with the corrected amount.' : null,
  has_evidence: evidence, created_at: at, updated_at: at, invoice: { id: id(`INV${n}`), invoice_number: invoice }, payment_record: null,
});
const DISPUTES = [
  dispute(1, 'open', 'Client reports a double M-Pesa charge', 'WST-INV-000247', true, '2026-07-15T06:30:00+00:00'),
  dispute(2, 'open', 'Service price differs from the quoted price', 'WST-INV-000245', false, '2026-07-14T15:10:00+00:00'),
  dispute(3, 'under_review', 'Card terminal slip does not match the receipt', 'WST-INV-000239', true, '2026-07-12T10:00:00+00:00'),
  dispute(4, 'resolved', 'Receipt issued with the wrong amount', 'WST-INV-000231', true, '2026-07-08T09:00:00+00:00'),
  dispute(5, 'rejected', 'Discount claimed after the promotion ended', 'WST-INV-000228', false, '2026-07-05T11:00:00+00:00'),
];

const refund = (n: number, status: string, amount: number, method: string, ref: string | null, invoice: string) => ({
  id: id(`REFUND${n}`), status, amount: kes(amount), currency: 'KES', method, reference_masked: ref, reason: 'Service not completed',
  refund_group: id(`RGROUP${n}`), approved_at: status === 'approved' || status === 'finalized' ? '2026-07-14T10:00:00+00:00' : null,
  finalized_at: status === 'finalized' ? '2026-07-14T15:00:00+00:00' : null, rejected_at: status === 'rejected' ? '2026-07-13T10:00:00+00:00' : null,
  created_at: '2026-07-13T08:00:00+00:00', invoice: { id: id(`INV${n}`), invoice_number: invoice, status: 'paid' }, payment_record: null,
});
const REFUNDS = [
  refund(1, 'requested', 180000, 'mpesa_offline', '••••••7QX2', 'WST-INV-000244'),
  refund(2, 'requested', 90000, 'cash', null, 'WST-INV-000242'),
  refund(3, 'approved', 250000, 'bank_transfer', '••••••0193', 'WST-INV-000236'),
  refund(4, 'finalized', 120000, 'mpesa_offline', '••••••3KD8', 'WST-INV-000230'),
  refund(5, 'rejected', 450000, 'card_terminal', '••••••5521', 'WST-INV-000226'),
];

const LIABILITY_SUMMARY = [{
  currency: 'KES', gross_salary_accrual_minor: 21000000, salary_reversal_minor: -450000, net_salary_liability_minor: 20550000,
  gross_earned_commission_minor: 8640000, commission_reversal_minor: -120000, net_commission_liability_minor: 8520000,
  compensation_adjustment_minor: 150000, combined_net_liability_minor: 29220000,
}];
const entry = (n: number, type: string, entryType: string, status: string, amount: number, staff: string, date: string | null, invoice: string | null) => ({
  id: id(`LIAB${n}`), liability_type: type, entry_type: entryType, status, amount_minor: amount, currency: 'KES', business_date: date,
  staff_profile_id: id(`ST${staff.split(' ')[0]!.toUpperCase()}`), staff_display_name: staff, branch_id: IDS.branch,
  compensation_plan_id: null, commission_rule_id: null, pay_period_start: type === 'salary' ? '2026-07-01' : null, pay_period_end: type === 'salary' ? '2026-07-15' : null,
  invoice_reference: invoice, source_entry_id: null, created_at: `${date ?? '2026-07-15'}T08:00:00+00:00`,
});
const LIABILITY_ENTRIES = [
  entry(1, 'commission', 'earned', 'earned', 37500, 'Amina Wanjiku', '2026-07-15', 'WST-INV-000248'),
  entry(2, 'commission', 'earned', 'earned', 97500, 'Brian Otieno', '2026-07-15', 'WST-INV-000246'),
  entry(3, 'salary', 'accrual', 'pending', 2250000, 'Amina Wanjiku', '2026-07-15', null),
  entry(4, 'commission', 'reversal', 'reversed', -27000, 'Carol Achieng', '2026-07-14', 'WST-INV-000243'),
];
const ADJUSTMENTS = [
  { id: id('ADJ1'), adjustment_type: 'manual', amount_minor: 150000, currency: 'KES', reason: 'Covered an extra Saturday shift on 11 July', staff_profile_id: id('STDAVID'), staff_display_name: 'David Kiprop', branch_id: IDS.branch, has_source: false, created_at: '2026-07-13T09:00:00+00:00' },
  { id: id('ADJ2'), adjustment_type: 'correction', amount_minor: -25000, currency: 'KES', reason: 'Earnings query: commission counted twice on one invoice', staff_profile_id: id('STCAROL'), staff_display_name: 'Carol Achieng', branch_id: IDS.branch, has_source: true, created_at: '2026-07-12T14:00:00+00:00' },
];

const query = (n: number, subject: string, type: string, status: string, body: string, role = 'finance') => ({
  id: id(`QUERY${n}`), staff_profile_id: id(`STQ${n}`), subject_type: subject, query_type: type, body, status, assigned_role: role,
  resolution_note: status === 'resolved' ? 'A correction was recorded as a separate adjustment.' : null, resolved_adjustment_id: null,
  responded_at: status === 'resolved' ? '2026-07-12T14:00:00+00:00' : null, created_at: '2026-07-11T09:00:00+00:00', updated_at: '2026-07-12T14:00:00+00:00',
});
const QUERIES = [
  query(1, 'commission_ledger', 'commission_disagreement', 'open', 'My commission for the braids on 14 July looks lower than the 15% in my terms.'),
  query(2, 'payout_item', 'payout_amount', 'assigned', 'The June second-half payout is short by one shift compared with my schedule.'),
  query(3, 'salary_ledger', 'statement_request', 'open', 'Please share a statement for the first half of July for my bank.'),
  query(4, 'commission_ledger', 'commission_disagreement', 'resolved', 'Commission on invoice WST-INV-000243 was counted twice.'),
];

const exportRecord = (n: number, type: string, status: string, rows: number | null, downloads: number, expires: string | null) => ({
  id: id(`FEXP${n}`), export_type: type, scope: 'branch', branch: { id: IDS.branch, name: 'Westlands Branch' }, status, reason: 'Monthly reconciliation pack',
  row_count: rows, download_count: downloads, expires_at: expires, first_downloaded_at: downloads > 0 ? '2026-07-15T07:00:00+00:00' : null,
  last_downloaded_at: downloads > 0 ? '2026-07-15T07:00:00+00:00' : null, failure_code: status === 'failed' ? 'generation_failed' : null,
  failure_message: status === 'failed' ? 'The export could not be generated. Request it again.' : null, created_at: '2026-07-15T06:00:00+00:00',
});
const EXPORTS = [
  exportRecord(1, 'invoices', 'ready', 248, 1, '2026-07-22T06:02:00+00:00'),
  exportRecord(2, 'payments', 'processing', null, 0, null),
  exportRecord(3, 'receipts', 'ready', 196, 0, '2026-07-21T06:02:00+00:00'),
  exportRecord(4, 'cash_up', 'expired', 31, 2, '2026-07-08T06:02:00+00:00'),
];

/** Path (without `/api/v1`) → body. The first match wins, so details precede collections. */
const OVERLAY: Array<[RegExp, unknown]> = [
  // Branch
  [/^\/service-categories$/, { data: CATEGORIES }],
  [/^\/services$/, list(SERVICES, 25)],
  [/^\/branch\/personnel-options$/, { data: PEOPLE }],
  [/^\/queue-entries$/, list(QUEUE, 25)],
  [/^\/appointments$/, list(APPOINTMENTS, 25)],
  [/^\/branches\/[^/]+\/calendar-exceptions$/, { data: CALENDAR, meta: { from: '2026-07-15', to: '2026-10-13' } }],
  [/^\/staff\/[^/]+\/availability$/, { data: { ...AVAILABILITY, staff: { id: IDS.staff, display_name: 'Amina Wanjiku', employment_status: 'employed', is_active: true } } }],
  // Human Resource
  [/^\/services\/[^/]+\/eligibility$/, { data: [
    { id: id('ELIG1'), staff_profile_id: IDS.staff, staff_name: 'Amina Wanjiku', active: true },
    { id: id('ELIG2'), staff_profile_id: id('STBRIAN'), staff_name: 'Brian Otieno', active: true },
  ] }],
  [/^\/compensation-plans$/, list(PLANS, 100)],
  [/^\/commission-rules$/, { data: [RULE] }],
  [/^\/hr\/payout-runs$/, list(PAYOUT_RUNS, 15)],
  // Finance
  [/^\/cash-ups$/, list(CASH_UPS)],
  [/^\/period-locks$/, list(PERIOD_LOCKS)],
  [/^\/receipts$/, list(RECEIPTS)],
  [/^\/finance-disputes$/, list(DISPUTES)],
  [/^\/refunds$/, list(REFUNDS)],
  [/^\/finance\/payout-runs$/, list(PAYOUT_RUNS, 15)],
  [/^\/compensation\/liabilities\/summary$/, { data: LIABILITY_SUMMARY }],
  [/^\/compensation\/liabilities$/, list(LIABILITY_ENTRIES)],
  [/^\/compensation\/adjustments$/, list(ADJUSTMENTS)],
  [/^\/finance\/earnings-queries$/, list(QUERIES, 25)],
  [/^\/finance-exports$/, list(EXPORTS)],
];

/** HR roster with the four synthetic people, so pickers and boards have more than one row. */
const HR_ROSTER = PEOPLE.map((p, index) => ({
  id: index === 0 ? IDS.staff : p.id,
  first_name: p.display_name.split(' ')[0],
  last_name: p.display_name.split(' ')[1],
  display_name: p.display_name,
  phone: null,
  role: 'personnel',
  role_title: ['Senior Stylist', 'Braider', 'Nail Technician', 'Massage Therapist'][index],
  status: 'active',
  employment_type: 'full_time',
  employment_status: 'employed',
  primary_branch_id: IDS.branch,
  is_active: true,
  can: { view: true, manage: true },
}));

/**
 * Install the overlay. Call AFTER `prepareVisual(page, screen, theme)` and before navigating.
 * Only GET reads are answered; mutations always reach the harness unchanged.
 */
export async function stubPopulated(page: Page, screen: AuditScreen): Promise<void> {
  await page.route('**/api/v1/**', (route) => {
    const request = route.request();
    if (request.method() !== 'GET') return route.fallback();
    const path = new URL(request.url()).pathname.replace(/^\/api\/v1/, '');

    if (screen.role === 'merchant_human_resource' && path === '/staff') {
      return route.fulfill({ json: list(HR_ROSTER, 100) });
    }
    const historyMatch = /^\/compensation-plans\/([^/]+)\/history$/.exec(path);
    if (historyMatch) return route.fulfill({ json: { data: HISTORY[historyMatch[1]!] ?? [] } });

    for (const [pattern, body] of OVERLAY) {
      if (pattern.test(path)) return route.fulfill({ json: body });
    }
    return route.fallback();
  });
}

/**
 * Pages whose first render is a deliberate "choose one" state (a staff or service picker). The
 * populated capture picks the first option so the composition under review is visible.
 */
export async function choosePopulatedSubject(page: Page, screen: AuditScreen): Promise<void> {
  const picker = ({
    'branch-staff-overview': '#bm-staff',
    'personnel-availability': '#staff',
    'service-eligibility': '#service',
  } as Record<string, string>)[screen.key];
  if (!picker) return;
  const select = page.locator(picker);
  if (await select.count() === 0) return;
  const value = await select.locator('option').evaluateAll((options) =>
    (options as HTMLOptionElement[]).map((o) => o.value).find((v) => v !== '') ?? '');
  if (value !== '') await select.selectOption(value);
}
