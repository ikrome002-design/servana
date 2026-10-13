#!/usr/bin/env node
// Phase UI-17 — read-only DNS + TLS probe for the eight production account hosts (UI/UX plan §4.6).
//
// Answers, for every canonical host, from REAL public resolvers and the REAL delegated nameservers:
//   - does the parent (.ke registry) delegate the zone, and to which nameservers?
//   - does each delegated nameserver actually ANSWER for the zone (lame-delegation check)?
//   - what A / AAAA / CNAME records do public resolvers return?
//   - when an address exists: does TLS on 443 with SNI present a trusted certificate whose SAN covers
//     the host, and how many days remain? Does plain HTTP redirect to HTTPS?
//
// It only ever READS: DNS queries, a TLS handshake, one anonymous HTTP GET. It never creates, updates
// or deletes a record, never requests a certificate, and needs no credential.
//
// Usage: node scripts/ui17-dns-tls-probe.mjs [--out <json>] [--min-cert-days 21]

import dgram from 'node:dgram';
import dns from 'node:dns/promises';
import http from 'node:http';
import tls from 'node:tls';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const arg = (name, fallback) => {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
};
const OUT = arg('--out', null);
const MIN_CERT_DAYS = Number(arg('--min-cert-days', '21'));
const registry = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const apex = registry.domains.production;
const HOSTS = registry.accounts.map((a) => ({ key: a.account_key, host: a.subdomain === null ? apex : `${a.subdomain}.${apex}` }));
const PUBLIC_RESOLVERS = { cloudflare: '1.1.1.1', google: '8.8.8.8' };

const outcome = async (fn) => {
  try {
    return { ok: true, value: await fn() };
  } catch (error) {
    return { ok: false, error: error.code ?? error.message };
  }
};

function resolverFor(ip) {
  const r = new dns.Resolver({ timeout: 5000, tries: 2 });
  r.setServers([ip]);
  return r;
}

/**
 * One raw, non-recursive DNS query over UDP. Node's resolver library treats a REFERRAL (NS records
 * in the authority section, which is exactly what a parent zone returns) as "no data", and hides
 * the RCODE a lame server returns, so the two facts this probe exists to establish need the wire.
 */
const RCODES = ['NOERROR', 'FORMERR', 'SERVFAIL', 'NXDOMAIN', 'NOTIMP', 'REFUSED'];
function rawQuery(server, name, type) {
  const qtype = { NS: 2, SOA: 6 }[type];
  const id = Math.floor(Math.random() * 65535);
  const labels = Buffer.concat([...name.split('.').map((l) => Buffer.concat([Buffer.from([l.length]), Buffer.from(l)])), Buffer.from([0])]);
  const packet = Buffer.concat([Buffer.from([id >> 8, id & 255, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0]), labels, Buffer.from([0, qtype, 0, 1])]);
  const readName = (buf, offset) => {
    const parts = [];
    let jumped = false;
    let end = offset;
    for (let guard = 0; guard < 64; guard += 1) {
      const len = buf[offset];
      if (len === 0) {
        if (!jumped) end = offset + 1;
        break;
      }
      if ((len & 0xc0) === 0xc0) {
        if (!jumped) end = offset + 2;
        offset = ((len & 0x3f) << 8) | buf[offset + 1];
        jumped = true;
        continue;
      }
      parts.push(buf.subarray(offset + 1, offset + 1 + len).toString());
      offset += len + 1;
    }
    return { name: parts.join('.'), end };
  };
  return new Promise((done) => {
    const socket = dgram.createSocket('udp4');
    const timer = setTimeout(() => {
      socket.close();
      done({ error: 'timeout' });
    }, 6000);
    socket.on('message', (buf) => {
      clearTimeout(timer);
      socket.close();
      const rcode = RCODES[buf[3] & 0x0f] ?? String(buf[3] & 0x0f);
      const counts = [buf.readUInt16BE(4), buf.readUInt16BE(6), buf.readUInt16BE(8), buf.readUInt16BE(10)];
      let offset = 12;
      for (let i = 0; i < counts[0]; i += 1) offset = readName(buf, offset).end + 4;
      const records = [];
      for (const section of ['answer', 'authority']) {
        const total = section === 'answer' ? counts[1] : counts[2];
        for (let i = 0; i < total; i += 1) {
          const owner = readName(buf, offset);
          const rtype = buf.readUInt16BE(owner.end);
          const rdlength = buf.readUInt16BE(owner.end + 8);
          const rdata = owner.end + 10;
          records.push({ section, owner: owner.name, type: rtype, value: rtype === 2 ? readName(buf, rdata).name : null });
          offset = rdata + rdlength;
        }
      }
      done({ rcode, authoritative: (buf[2] & 0x04) !== 0, records });
    });
    socket.send(packet, 53, server, (error) => {
      if (error) {
        clearTimeout(timer);
        socket.close();
        done({ error: error.code });
      }
    });
  });
}

// 1. Delegation from the parent zone, asked of the parent's own nameservers (a referral).
const tld = apex.split('.').slice(1).join('.');
const tldNs = await outcome(() => resolverFor(PUBLIC_RESOLVERS.google).resolveNs(tld));
let delegation = { ok: false, error: 'tld nameservers unknown' };
if (tldNs.ok) {
  for (const ns of tldNs.value) {
    const ip = await outcome(() => resolverFor(PUBLIC_RESOLVERS.google).resolve4(ns));
    if (!ip.ok) continue;
    const answer = await rawQuery(ip.value[0], apex, 'NS');
    const names = (answer.records ?? []).filter((r) => r.type === 2 && r.owner === apex).map((r) => r.value);
    if (names.length > 0) {
      delegation = { ok: true, value: [...new Set(names)].sort(), asked: ns, rcode: answer.rcode };
      break;
    }
  }
}

// 2. Does each delegated nameserver answer AUTHORITATIVELY for the zone? (lame-delegation check)
const nameservers = [];
for (const ns of delegation.ok ? delegation.value : []) {
  const ip = await outcome(() => resolverFor(PUBLIC_RESOLVERS.google).resolve4(ns));
  const soa = ip.ok ? await rawQuery(ip.value[0], apex, 'SOA') : { error: 'no address' };
  const answers = soa.rcode === 'NOERROR' && soa.authoritative === true;
  nameservers.push({ nameserver: ns, address: ip.ok ? ip.value[0] : null, rcode: soa.rcode ?? null, authoritative: soa.authoritative ?? null, answers_for_zone: answers, error: soa.error ?? null });
}
const lame = nameservers.length > 0 && nameservers.every((n) => !n.answers_for_zone);

function certificate(host) {
  return new Promise((done) => {
    const socket = tls.connect({ host, port: 443, servername: host, timeout: 15_000 }, () => {
      const cert = socket.getPeerCertificate();
      socket.end();
      const san = (cert.subjectaltname ?? '').split(',').map((s) => s.trim().replace(/^DNS:/, ''));
      done({
        authorized: socket.authorized,
        authorization_error: socket.authorizationError ?? null,
        issuer: cert.issuer?.O ?? cert.issuer?.CN ?? null,
        san,
        covers_host: san.some((n) => n === host || (n.startsWith('*.') && host.endsWith(n.slice(1)) && host.split('.').length === n.split('.').length)),
        valid_to: cert.valid_to,
        days_remaining: Math.floor((new Date(cert.valid_to).getTime() - Date.now()) / 86_400_000),
      });
    });
    socket.on('error', (e) => done({ error: e.code ?? e.message }));
    socket.on('timeout', () => socket.destroy(new Error('timeout')));
  });
}

const rows = [];
for (const { key, host } of HOSTS) {
  const row = { account_key: key, host, resolvers: {} };
  for (const [name, ip] of Object.entries(PUBLIC_RESOLVERS)) {
    const r = resolverFor(ip);
    row.resolvers[name] = {
      a: await outcome(() => r.resolve4(host)),
      aaaa: await outcome(() => r.resolve6(host)),
      cname: await outcome(() => r.resolveCname(host)),
    };
  }
  const addresses = Object.values(row.resolvers).flatMap((x) => [...(x.a.ok ? x.a.value : []), ...(x.aaaa.ok ? x.aaaa.value : [])]);
  row.resolves = addresses.length > 0;
  if (row.resolves) {
    row.tls = await certificate(host);
    row.http_redirect = await new Promise((done) =>
      http.get({ host, port: 80, path: '/', timeout: 15_000 }, (res) => done({ status: res.statusCode, location: res.headers.location ?? null })).on('error', (e) => done({ error: e.code })),
    );
    row.healthy =
      row.tls.authorized === true && row.tls.covers_host === true && row.tls.days_remaining >= MIN_CERT_DAYS &&
      [301, 308].includes(row.http_redirect.status) && String(row.http_redirect.location).startsWith(`https://${host}`);
  } else {
    row.tls = { skipped: 'host does not resolve — no TLS endpoint to test' };
    row.healthy = false;
  }
  rows.push(row);
  console.log(`${row.healthy ? 'ok  ' : 'FAIL'} ${host.padEnd(20)} resolves=${row.resolves} ${row.resolves ? `tls=${row.tls.authorized} days=${row.tls.days_remaining}` : `(${Object.values(row.resolvers).map((x) => x.a.error).join('/')})`}`);
}

const report = {
  generated_by: 'scripts/ui17-dns-tls-probe.mjs',
  run_at: new Date().toISOString(),
  apex,
  public_resolvers: PUBLIC_RESOLVERS,
  parent_delegation: delegation.ok ? { asked: delegation.asked, nameservers: delegation.value } : { error: delegation.error },
  delegated_nameservers: nameservers,
  lame_delegation: lame,
  hosts: rows,
  all_hosts_healthy: rows.every((r) => r.healthy),
};
console.log(`\ndelegation: ${delegation.ok ? delegation.value.join(', ') : delegation.error}; lame=${lame}; healthy ${rows.filter((r) => r.healthy).length}/${rows.length}`);
if (OUT) {
  mkdirSync(dirname(resolve(ROOT, OUT)), { recursive: true });
  writeFileSync(resolve(ROOT, OUT), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
}
process.exit(report.all_hosts_healthy ? 0 : 1);
