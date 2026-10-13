#!/usr/bin/env node
// Phase UI-17 — isolated release rehearsal: image-slot canary, promotion and rollback.
//
// WHAT THIS IS: a disposable, fully isolated topology on the local Docker engine that exercises the
// release MECHANICS the production runbook relies on, using the real production image pairs:
//
//   blue  = the PREVIOUS release (e.g. the image pair built from the current main merge commit)
//   green = the CANDIDATE release (the image pair built from the change under review)
//   lb    = a front nginx that splits traffic between the two slot edges, standing in for whatever
//           load balancer Phase 25 provisions (UI/UX plan §4.7; Plan §76–§77)
//
// Each slot runs its own php-fpm app, its own nginx edge and a THROWAWAY PostgreSQL 16 with migrations
// applied, so browser routes that need the database (the context-handoff consumer) behave as in a
// real environment. No production secret, data, DNS or certificate is involved; every name is
// prefixed `ui17-rh-`; teardown removes only those resources.
//
// WHAT THIS IS NOT: production DNS/TLS evidence, a production canary, or Phase 25's database
// expand/contract, worker drain, scheduler coordination or PITR restore. Those stay with Phase 25.
//
// Phases recorded in the report:
//   1. blue live            — fingerprint every host on the previous release
//   2. canary (weighted)    — N shell requests; release mix; every shell's entry asset fetched
//      2a. negative control — the same with the hashed-asset fallback DISABLED: proves skew 404s
//   3. promote green        — 100% candidate; scripts/ui17-edge-smoke.mjs must pass in full
//   4. rollback to blue     — timed switch back; all eight hosts serve the previous release again;
//                             a client still holding candidate HTML can still load its assets
//   5. log check            — the synthetic credential sent by the smoke is absent from the
//                             candidate edge's access log
//
// Usage:
//   node scripts/ui17-release-rehearsal.mjs --previous <tag> --candidate <tag> [--port 8170]
//        [--canary-percent 10] [--requests 200] [--out <json>] [--keep]
// where <tag> is the suffix of servana-ui17-{nginx,php}:<tag>.

import { execFileSync, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import http from 'node:http';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const arg = (name, fallback) => {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
};
const PREVIOUS = arg('--previous', null);
const CANDIDATE = arg('--candidate', null);
if (!PREVIOUS || !CANDIDATE) {
  console.error('usage: --previous <tag> --candidate <tag>');
  process.exit(2);
}
const PORT = Number(arg('--port', '8170'));
const CANARY = Number(arg('--canary-percent', '10'));
const REQUESTS = Number(arg('--requests', '200'));
const OUT = arg('--out', null);
const KEEP = process.argv.includes('--keep');
const P = 'ui17-rh';
const SYNTHETIC = 'UI17SYNTHETICCANARY0000000000000000000000';

const registry = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const HOSTS = registry.accounts.map((a) => (a.subdomain === null ? registry.domains.production : `${a.subdomain}.${registry.domains.production}`));

const log = (...parts) => console.log(`[${new Date().toISOString().slice(11, 19)}]`, ...parts);
const docker = (...args) => execFileSync('docker', args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
const dockerQuiet = (...args) => spawnSync('docker', args, { encoding: 'utf8' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function image(kind, tag) {
  const ref = `servana-ui17-${kind}:${tag}`;
  return { ref, id: docker('image', 'inspect', ref, '--format', '{{.Id}}') };
}

function teardown() {
  for (const name of [`${P}-lb`, `${P}-blue-edge`, `${P}-blue-app`, `${P}-blue-pg`, `${P}-green-edge`, `${P}-green-app`, `${P}-green-pg`]) {
    dockerQuiet('rm', '-f', name);
  }
  for (const network of [`${P}-front`, `${P}-blue`, `${P}-green`]) dockerQuiet('network', 'rm', network);
}

function get(host, path, headers = {}) {
  return new Promise((done) => {
    const req = http.request({ host: '127.0.0.1', port: PORT, path, headers: { Host: host, ...headers }, timeout: 15_000 }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => done({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks).toString('utf8') }));
    });
    req.on('error', (e) => done({ status: null, error: e.code, headers: {}, body: '' }));
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.end();
  });
}

const entryOf = (html) => html.match(/<script type="module" src="([^"]+)"/)?.[1] ?? null;

function lbConfig(mode) {
  const splitBlock =
    mode === 'canary' || mode === 'canary-no-asset-fallback'
      ? `split_clients "\${request_id}" $sv_slot {\n    ${CANARY}% green;\n    * blue;\n}`
      : `map $host $sv_slot {\n    default ${mode};\n}`;
  const assets =
    mode === 'canary-no-asset-fallback'
      ? '    location ^~ /spa-assets/ { proxy_pass http://slot_$sv_slot; }'
      : `    # Hashed assets resolve from EITHER release: a client holding one release's HTML can always
    # fetch that release's chunks, whichever slot the asset request lands on (asset-skew safety).
    location ^~ /spa-assets/ {
        proxy_pass http://assets_any;
        proxy_next_upstream error timeout http_404;
    }`;
  return `# ${P} front load balancer — mode: ${mode} (generated by scripts/ui17-release-rehearsal.mjs)
upstream slot_blue  { server ${P}-blue-edge:8080; }
upstream slot_green { server ${P}-green-edge:8080; }
upstream assets_any { server ${P}-green-edge:8080; server ${P}-blue-edge:8080; }
${splitBlock}
# The front tier allowlists the eight account hosts itself. Without this, an unknown host reaches the
# edge, the edge closes the connection (444) and this proxy reports 502 — a 5xx that would page
# someone for what is a correct refusal (UI/UX plan §4.7, §18.5).
server {
    listen 8080 default_server;
    return 444;
}
server {
    listen 8080;
    server_name ${HOSTS.join(' ')};
    proxy_set_header Host $host;
    proxy_set_header X-Correlation-ID $request_id;
    add_header X-Servana-Rehearsal-Slot $sv_slot always;
    add_header X-Servana-Rehearsal-Mode ${mode} always;
${assets}
    location / { proxy_pass http://slot_$sv_slot; }
}
`;
}

let configDir = null;
/**
 * Switch the load balancer and return only once the switch is EFFECTIVE. `nginx -s reload` is
 * asynchronous — old workers keep answering for a moment — so the mode is stamped on every response
 * and twenty consecutive confirmations are required before any measurement.
 */
async function applyLb(mode) {
  writeFileSync(join(configDir, 'lb.conf'), lbConfig(mode));
  docker('cp', join(configDir, 'lb.conf'), `${P}-lb:/etc/nginx/conf.d/default.conf`);
  docker('exec', `${P}-lb`, 'nginx', '-t', '-q');
  docker('exec', `${P}-lb`, 'nginx', '-s', 'reload');
  let streak = 0;
  return waitFor(async () => {
    const probe = await get(HOSTS[0], '/health/host');
    streak = probe.headers['x-servana-rehearsal-mode'] === mode ? streak + 1 : 0;
    return streak >= 20;
  }, 30_000, `lb mode ${mode}`);
}

async function waitFor(predicate, timeoutMs, label) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    if (await predicate()) return Date.now() - started;
    await sleep(250);
  }
  throw new Error(`timed out waiting for ${label}`);
}

async function fingerprint(expected) {
  const rows = [];
  for (const host of HOSTS) {
    const shell = await get(host, '/');
    const entry = entryOf(shell.body);
    const asset = entry ? await get(host, entry) : { status: null };
    rows.push({ host, status: shell.status, entry, asset_status: asset.status, slot: shell.headers['x-servana-rehearsal-slot'] ?? null });
  }
  const ok = rows.every((r) => r.status === 200 && r.asset_status === 200 && (!expected || r.entry === expected));
  return { ok, rows };
}

const report = {
  generated_by: 'scripts/ui17-release-rehearsal.mjs',
  scope: 'isolated local Docker rehearsal of image-slot canary/promotion/rollback mechanics — NOT production, NOT DNS/TLS evidence, NOT Phase 25 database/worker/backup rollback',
  started_at: new Date().toISOString(),
  docker_server: docker('version', '--format', '{{.Server.Version}}'),
  port: PORT,
  phases: {},
};

try {
  teardown();
  configDir = mkdtempSync(join(tmpdir(), 'servana-ui17-rh-'));
  const images = {
    blue: { nginx: image('nginx', PREVIOUS), php: image('php', PREVIOUS), tag: PREVIOUS },
    green: { nginx: image('nginx', CANDIDATE), php: image('php', CANDIDATE), tag: CANDIDATE },
  };
  report.images = images;

  docker('network', 'create', `${P}-front`);
  for (const slot of ['blue', 'green']) {
    const net = `${P}-${slot}`;
    const password = randomBytes(18).toString('hex'); // throwaway, never written anywhere
    docker('network', 'create', net);
    docker('run', '-d', '--name', `${P}-${slot}-pg`, '--network', net, '--network-alias', 'postgres',
      '-e', 'POSTGRES_DB=servana', '-e', 'POSTGRES_USER=servana', '-e', `POSTGRES_PASSWORD=${password}`, 'postgres:16-alpine');
    // Over TCP: during initialisation the image runs a temporary socket-only server, so a socket probe
    // passes before the real server accepts network connections.
    await waitFor(async () => dockerQuiet('exec', `${P}-${slot}-pg`, 'pg_isready', '-h', '127.0.0.1', '-U', 'servana', '-d', 'servana').status === 0, 90_000, `${slot} postgres`);
    docker('run', '-d', '--name', `${P}-${slot}-app`, '--network', net, '--network-alias', 'app',
      '-e', 'DB_CONNECTION=pgsql', '-e', 'DB_HOST=postgres', '-e', 'DB_PORT=5432', '-e', 'DB_DATABASE=servana',
      '-e', 'DB_USERNAME=servana', '-e', `DB_PASSWORD=${password}`,
      '-e', 'SESSION_DRIVER=array', '-e', 'CACHE_STORE=array', '-e', 'QUEUE_CONNECTION=sync', images[slot].php.ref);
    // The entrypoint copies .env, THEN generates APP_KEY, then execs php-fpm: wait for the key, not the file.
    await waitFor(async () => dockerQuiet('exec', `${P}-${slot}-app`, 'grep', '-q', '^APP_KEY=base64:', '.env').status === 0, 90_000, `${slot} app boot`);
    const migrate = dockerQuiet('exec', `${P}-${slot}-app`, 'php', 'artisan', 'migrate', '--force', '--no-interaction');
    if (migrate.status !== 0) throw new Error(`${slot} migrations failed: ${`${migrate.stdout}\n${migrate.stderr}`.trim().slice(-600)}`);
    docker('run', '-d', '--name', `${P}-${slot}-edge`, '--network', net, images[slot].nginx.ref);
    docker('network', 'connect', `${P}-front`, `${P}-${slot}-edge`);
  }
  docker('run', '-d', '--name', `${P}-lb`, '--network', `${P}-front`, '-p', `${PORT}:8080`, 'nginxinc/nginx-unprivileged:1.27-alpine');
  await sleep(1500);

  // Phase 1 — blue live (the previous release serves every host).
  await applyLb('blue');
  const blue = await fingerprint(null);
  const blueEntry = blue.rows[0].entry;
  report.phases.blue_live = {
    ok: blue.ok && blueEntry !== null && blue.rows.every((r) => r.entry === blueEntry && r.slot === 'blue'),
    entry: blueEntry,
    hosts: blue.rows,
  };
  log('blue live', report.phases.blue_live.ok, blueEntry);

  // Phase 2 — canary, with and without the asset fallback. Responses are classified by the slot the
  // load balancer reports, and each slot must serve exactly one, distinct release fingerprint.
  async function canary(mode) {
    await applyLb(mode);
    const tally = { blue: 0, green: 0, shell_errors: 0, asset_errors: 0 };
    const entries = { blue: new Set(), green: new Set() };
    for (let i = 0; i < REQUESTS; i += 1) {
      const host = HOSTS[i % HOSTS.length];
      const shell = await get(host, '/');
      const entry = entryOf(shell.body);
      const slot = shell.headers['x-servana-rehearsal-slot'];
      if (shell.status !== 200 || !entry || !(slot in tally)) {
        tally.shell_errors += 1;
        continue;
      }
      tally[slot] += 1;
      entries[slot].add(entry);
      const asset = await get(host, entry);
      if (asset.status !== 200) tally.asset_errors += 1;
    }
    return {
      mode,
      canary_percent: CANARY,
      requests: REQUESTS,
      ...tally,
      green_share: Number((tally.green / REQUESTS).toFixed(3)),
      blue_entries: [...entries.blue],
      green_entries: [...entries.green],
    };
  }
  const canaryRun = await canary('canary');
  const skewRun = await canary('canary-no-asset-fallback');
  const greenEntry = canaryRun.green_entries[0] ?? skewRun.green_entries[0] ?? null;
  report.phases.canary = {
    ...canaryRun,
    ok:
      canaryRun.shell_errors === 0 && canaryRun.asset_errors === 0 && canaryRun.green > 0 && canaryRun.blue > 0 &&
      canaryRun.blue_entries.length === 1 && canaryRun.blue_entries[0] === blueEntry &&
      canaryRun.green_entries.length === 1 && canaryRun.green_entries[0] !== blueEntry,
  };
  report.phases.canary_negative_control_no_asset_fallback = {
    ...skewRun,
    expected: 'asset_errors > 0 — proves hashed-asset skew is real and the fallback is what removes it',
    ok: skewRun.asset_errors > 0 && skewRun.shell_errors === 0,
  };
  log('canary', JSON.stringify(report.phases.canary));
  log('skew control', JSON.stringify(report.phases.canary_negative_control_no_asset_fallback));

  // Phase 3 — promote green; the full edge smoke must pass against the candidate.
  await applyLb('green');
  const promoted = await fingerprint(greenEntry);
  const smoke = spawnSync(process.execPath, [join(ROOT, 'scripts/ui17-edge-smoke.mjs'), '--origin', `http://127.0.0.1:${PORT}`, '--out', join(configDir, 'smoke-green.json')], { encoding: 'utf8' });
  const smokeReport = JSON.parse(readFileSync(join(configDir, 'smoke-green.json'), 'utf8'));
  report.phases.promote_green = {
    ok: promoted.ok && promoted.rows.every((r) => r.slot === 'green') && smoke.status === 0,
    entry: greenEntry,
    hosts: promoted.rows,
    edge_smoke: { exit: smoke.status, checks: smokeReport.checks, failures: smokeReport.failures },
  };
  report.edge_smoke_report = smokeReport;
  log('promote green', report.phases.promote_green.ok, `smoke exit ${smoke.status} (${smokeReport.checks} checks)`);

  // Phase 4 — rollback to blue, timed from the switch to all eight hosts serving the previous release.
  const heldGreenAsset = greenEntry; // a browser that loaded green HTML just before the rollback
  const t0 = Date.now();
  await applyLb('blue');
  let rolledBack = null;
  await waitFor(async () => {
    rolledBack = await fingerprint(blueEntry);
    return rolledBack.ok && rolledBack.rows.every((r) => r.slot === 'blue');
  }, 60_000, 'rollback');
  const rollbackMs = Date.now() - t0;
  const heldAsset = await get(HOSTS[0], heldGreenAsset);
  report.phases.rollback = {
    ok: rolledBack.ok && heldAsset.status === 200,
    seconds_to_all_hosts_previous_release: Number((rollbackMs / 1000).toFixed(2)),
    timing_note: 'includes the 20-response propagation confirmation (~5 s at a 250 ms poll), so it is an upper bound on the switch itself',
    entry: blueEntry,
    hosts: rolledBack.rows,
    candidate_asset_still_served_after_rollback: heldAsset.status,
    note: 'The candidate slot is kept running (drained of HTML traffic) so clients holding candidate HTML still resolve its hashed chunks; it is removed only after the drain window.',
  };
  log('rollback', report.phases.rollback.ok, `${report.phases.rollback.seconds_to_all_hosts_previous_release}s`);
  // Phase 5 — the synthetic credential the smoke sent must never reach the candidate edge's log.
  const greenLog = spawnSync('docker', ['logs', `${P}-green-edge`], { encoding: 'utf8' });
  const blueLog = spawnSync('docker', ['logs', `${P}-blue-edge`], { encoding: 'utf8' });
  const greenLines = `${greenLog.stdout}${greenLog.stderr}`.split('\n').filter((l) => l.includes('"path"'));
  report.phases.access_log_credential_check = {
    ok: !`${greenLog.stdout}${greenLog.stderr}`.includes(SYNTHETIC) && greenLines.length > 0,
    candidate_edge_lines_inspected: greenLines.length,
    candidate_edge_contains_synthetic_token: `${greenLog.stdout}${greenLog.stderr}`.includes(SYNTHETIC),
    previous_edge_contains_synthetic_token: `${blueLog.stdout}${blueLog.stderr}`.includes(SYNTHETIC),
    note: 'The smoke (which sends the synthetic token) runs only against the promoted candidate slot, so the previous edge never received it here; the previous release logging the raw token is proven separately against the N-1 image (security-host-matrix.json → before).',
    sample_candidate_line: greenLines.find((l) => l.includes('/auth/switch')) ?? null,
  };
  log('log check', JSON.stringify(report.phases.access_log_credential_check).slice(0, 300));
} catch (error) {
  report.error = String(error?.message ?? error);
  console.error(error);
} finally {
  report.finished_at = new Date().toISOString();
  report.passed = !report.error && Object.values(report.phases).every((phase) => phase.ok);
  if (!KEEP) {
    teardown();
    report.teardown = `removed every ${P}-* container and network`;
  } else report.teardown = 'kept (--keep)';
  if (configDir) rmSync(configDir, { recursive: true, force: true });
  if (OUT) {
    mkdirSync(dirname(resolve(ROOT, OUT)), { recursive: true });
    writeFileSync(resolve(ROOT, OUT), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  }
  log(report.passed ? 'REHEARSAL PASSED' : 'REHEARSAL FAILED');
  process.exit(report.passed ? 0 : 1);
}
