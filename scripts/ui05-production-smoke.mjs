#!/usr/bin/env node
// Phase UI-05 — production asset smoke.
//
// Drives the broken-asset matrix over HTTP against the BUILT production pair (nginx + PHP-FPM),
// not the Vite preview origin. The offline contract tests prove the files exist and hash correctly;
// this proves the edge actually serves them, with the right MIME, and actually withholds the
// eleven quarantined working files and the deleted vector logo.
//
// It only READS over HTTP and creates nothing.
//
// Usage: node scripts/ui05-production-smoke.mjs [baseUrl] [accountHost]

import http from 'node:http';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const [, , BASE = 'http://localhost:8099', HOST = 'servana.test'] = process.argv;

const matrix = JSON.parse(readFileSync(join(ROOT, 'docs/frontend/audits/ui-05/broken-asset-matrix.json'), 'utf8'));
const images = JSON.parse(readFileSync(join(ROOT, 'public/assets/landing_page_images/manifest.json'), 'utf8'));
const quarantine = JSON.parse(readFileSync(join(ROOT, 'docs/frontend/audits/ui-05/asset-quarantine.json'), 'utf8'));

/** @type {{name: string, ok: boolean, detail: string}[]} */
const observations = [];

function record(name, ok, detail = '') {
  observations.push({ name, ok, detail });
  console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name}${detail === '' ? '' : ` — ${detail}`}`);
}

const { hostname: ORIGIN_HOST, port: ORIGIN_PORT } = new URL(BASE);

/**
 * Request a path with an EXPLICIT Host header.
 *
 * node:http rather than fetch, for the same reason UI-02 and UI-04 use it: `Host` is a forbidden
 * header name for fetch, which drops it silently — so every request would arrive as `localhost` and
 * the account-host boundary would be exercised against the wrong value.
 */
function get(path, { host = HOST } = {}) {
  return new Promise((resolve_, reject) => {
    const request = http.request(
      { host: ORIGIN_HOST, port: ORIGIN_PORT, path: encodeURI(path), method: 'GET', headers: { Host: host } },
      (response) => {
        const chunks = [];
        response.on('data', (chunk) => chunks.push(chunk));
        response.on('end', () => resolve_({
          status: response.statusCode ?? 0,
          type: response.headers['content-type'] ?? '',
          body: Buffer.concat(chunks),
        }));
      },
    );
    request.on('error', reject);
    request.end();
  });
}

const sha256 = (buffer) => createHash('sha256').update(buffer).digest('hex');

async function main() {
  // ---- everything the pipeline publishes must serve ------------------------
  const bySourcePath = new Map();
  for (const image of images.images) {
    bySourcePath.set(image.source_path, image.source_sha256);
    for (const derivative of image.derivatives) {
      bySourcePath.set(derivative.path, derivative.sha256);
    }
  }

  for (const entry of matrix.must_serve) {
    const response = await get(entry.public_path);
    const expectedHash = bySourcePath.get(entry.path) ?? null;
    const hashOk = expectedHash === null || sha256(response.body) === expectedHash;

    record(
      `serves ${entry.public_path}`,
      response.status === entry.expect_status && response.type.includes(entry.mime_type) && hashOk,
      `${response.status} ${response.type}${hashOk ? '' : ' BYTES DIFFER'}`,
    );
  }

  // ---- everything it withdraws must not ------------------------------------
  for (const entry of matrix.must_not_serve) {
    const response = await get(entry.public_path);
    record(
      `withholds ${entry.public_path}`,
      response.status === entry.expect_status,
      `${response.status} (${entry.reason})`,
    );
  }

  // ---- the quarantine, stated independently of the matrix ------------------
  record(
    'quarantines exactly eleven unapproved brand working files',
    quarantine.files.length === 11 && quarantine.total_files === 11,
    String(quarantine.files.length),
  );

  // ---- the served brand tree carries nothing unapproved --------------------
  // The directory itself must not be listable; nginx must not expose an index.
  const listing = await get('/assets/brand/');
  record(
    'the brand directory is not listable',
    !/Index of/i.test(listing.body.toString('utf8')),
    `${listing.status} ${listing.type}`,
  );

  // ---- path traversal out of the generated image tree ----------------------
  for (const path of [
    '/assets/landing_page_images/generated/../../brand/PNG.png',
    '/assets/landing_page_images/generated/merchant_finance/../../../../.env',
  ]) {
    const response = await get(path);
    record(`denies traversal ${path}`, response.status !== 200, String(response.status));
  }

  // ---- no service worker ---------------------------------------------------
  for (const path of ['/sw.js', '/service-worker.js']) {
    const response = await get(path);
    record(`no service worker script at ${path}`, !/javascript/.test(response.type), `${response.status} ${response.type}`);
  }

  const failed = observations.filter((observation) => !observation.ok);
  console.log(`\n${observations.length} observations, ${failed.length} failures.`);

  if (failed.length > 0) {
    process.exit(1);
  }
}

main().catch((error) => {
  console.error('UI-05 production smoke failed to run:', error);
  process.exit(1);
});
