#!/usr/bin/env node
/**
 * Local UI-11 visual-QA fixture server.
 *
 * It proxies the real Vite application, injects the Laravel-owned HR host context into the parsed
 * HTML and serves deterministic read-only API fixtures. Production code is untouched; backend
 * authority and mutation behavior are verified separately against PostgreSQL.
 */
import { createServer } from 'node:http';
import { existsSync, readFileSync } from 'node:fs';
import { extname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const upstream = 'http://127.0.0.1:5173';
const port = 4180;
const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const staffId = '01HZZSTAFF000000000000000';
const branchId = '01HZZBRANCH00000000000000';

const overview = {
  branch: { id: branchId, name: 'Westlands Studio', code: 'WST', town: 'Nairobi' },
  staff: { total: 8, active: 6, by_access_status: { active: 6, invited: 1, suspended: 1, deactivated: 0 }, pending_invitations: 1 },
  readiness: { eligible_staff: 5, without_eligibility: 1, available_staff: 4, without_availability: 2, configured_compensation: 3, without_compensation: 3 },
  compensation: { by_status: { active: 3, draft: 2 }, drafts_requiring_action: 2 },
  payouts: { by_status: { draft: 1, submitted: 2 }, awaiting_finance: 2 },
  tasks: [
    { key: 'pending-invitations', label: 'Pending staff invitations', count: 1, route_name: 'hr.staff-invite' },
    { key: 'eligibility-gaps', label: 'Active staff without service eligibility', count: 1, route_name: 'hr.eligibility' },
    { key: 'availability-gaps', label: 'Active staff without availability', count: 2, route_name: 'hr.availability' },
    { key: 'compensation-gaps', label: 'Active staff without active or scheduled terms', count: 3, route_name: 'hr.compensation' },
    { key: 'draft-plans', label: 'Draft compensation plans', count: 2, route_name: 'hr.compensation' },
  ],
  get_started: { staff_invited: true, eligibility_configured: true, availability_configured: true, compensation_configured: true, missing_compensation_reviewed: false },
  reports: { available: false, reason: 'Phase 21N is blocked by External Gate W' },
  notifications: { available: false, reason: 'Phase 21N is blocked by External Gate W' },
};

const staff = {
  id: staffId, first_name: 'Amina', last_name: 'Wanjiku', display_name: 'Amina Wanjiku',
  phone: '+254 700 000 001', role: 'personnel', role_title: 'Senior stylist', status: 'active',
  employment_type: 'full_time', employment_status: 'employed', primary_branch_id: branchId,
  is_active: true, can: { view: true, manage: true },
};

const bootstrap = {
  user: { id: '01HZZUSER0000000000000000', email: 'hr@westlands.test', name: 'Njeri Kamau', status: 'active', email_verified_at: '2026-08-01T05:00:00Z', is_platform_staff: false, theme_preference: null, resolved_theme: 'light' },
  merchant: { id: '01HZZMERCHANT000000000000', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
  membership: { id: '01HZZMEMBER00000000000000', role: 'hr', status: 'active' },
  memberships: [{ id: '01HZZMEMBER00000000000000', role: 'hr', status: 'active' }],
  permissions: ['staff.view', 'staff.invite', 'staff.suspend', 'personnel.eligibility.manage', 'personnel.availability.manage', 'compensation.plan.view', 'compensation.plan.create', 'compensation.history.view', 'payout_run.create'],
  account_keys: ['merchant_human_resource'], branch_ids: [branchId],
  setup: { required: false, current_step: null, completed_at: '2026-01-01T00:00:00Z' },
  mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
};

const json = (response, body, status = 200) => {
  response.writeHead(status, { 'content-type': 'application/json', 'cache-control': 'no-store' });
  response.end(JSON.stringify(body));
};

createServer(async (request, response) => {
  const url = new URL(request.url ?? '/', `http://${request.headers.host}`);
  if (url.pathname.startsWith('/assets/')) {
    const asset = resolve(join(root, 'public', url.pathname.replace(/^\//, '')));
    const publicRoot = resolve(join(root, 'public'));
    if (asset.startsWith(publicRoot) && existsSync(asset)) {
      const mime = { '.png': 'image/png', '.ico': 'image/x-icon', '.webp': 'image/webp', '.json': 'application/json' }[extname(asset)] ?? 'application/octet-stream';
      response.writeHead(200, { 'content-type': mime, 'cache-control': 'no-store' });
      return response.end(readFileSync(asset));
    }
  }
  if (url.pathname === '/api/v1/me') return json(response, { data: bootstrap });
  if (url.pathname === '/api/v1/hr/workspace') return json(response, { data: { overview } });
  if (url.pathname === '/api/v1/hr/audit-activity') return json(response, {
    data: [{ id: '01HZZEVENT000000000000000', action: 'membership.suspended', severity: 'warning', actor: 'h***@westlands.test', branch: branchId, subject_type: 'MerchantUser', context: { reason: 'Policy review', membership: '01H…0000' }, correlation_id: '01H…0000', created_at: '2026-08-13T05:00:00Z' }],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  });
  if (url.pathname === `/api/v1/staff/${staffId}`) return json(response, { data: staff });
  if (url.pathname === '/api/v1/staff') return json(response, { data: [staff], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } });
  if (url.pathname === '/api/v1/hr/service-options') return json(response, { data: [{ ulid: '01HZZSERVICE00000000000000', name: 'Natural hair consultation' }] });
  if (url.pathname.startsWith('/api/v1/')) return json(response, { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } });

  try {
    const upstreamResponse = await fetch(`${upstream}${url.pathname}${url.search}`, {
      headers: { accept: request.headers.accept ?? '*/*' },
    });
    const contentType = upstreamResponse.headers.get('content-type') ?? 'application/octet-stream';
    let body = Buffer.from(await upstreamResponse.arrayBuffer());
    if (contentType.includes('text/html')) {
      const context = '<script id="servana-account-context" type="application/json">{"account_key":"merchant_human_resource","display_name":"Human Resource","environment":"local","host":"localhost"}</script>';
      body = Buffer.from(body.toString('utf8').replace('</head>', `${context}</head>`));
    }
    response.writeHead(upstreamResponse.status, { 'content-type': contentType, 'cache-control': 'no-store' });
    response.end(body);
  } catch (error) {
    response.writeHead(502, { 'content-type': 'text/plain' });
    response.end(`UI-11 fixture proxy failed: ${error instanceof Error ? error.message : String(error)}`);
  }
}).listen(port, '127.0.0.1', () => {
  console.log(`UI-11 browser fixture listening on http://127.0.0.1:${port}`);
});
