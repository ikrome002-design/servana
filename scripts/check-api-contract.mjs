// API contract check (Plan §23/§24; Phase 10 REM-ROUTE-001).
//
// Fails when:
//   - the committed generated TypeScript is stale vs docs/api/openapi.json;
//   - a test-only route leaks into the OpenAPI document;
//   - an OpenAPI operationId is duplicated;
//   - an OpenAPI path is missing from the generated TypeScript.
//
// (OpenAPI ⇄ live-route staleness is enforced separately by the PHP
// OpenApiContractTest, which can run the route-derived generator.)

import { execSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const specPath = resolve(root, 'docs/api/openapi.json');
const tsPath = resolve(root, 'resources/spa/src/types/generated/api.ts');

const fail = (msg) => {
  console.error(`api:contract:check FAILED — ${msg}`);
  process.exit(1);
};

const norm = (s) => s.replace(/\r\n/g, '\n').trimEnd();

const spec = JSON.parse(readFileSync(specPath, 'utf8'));
const committedTs = readFileSync(tsPath, 'utf8');

// 1. No test-only routes in the production inventory.
for (const path of Object.keys(spec.paths ?? {})) {
  if (path.includes('/testing/')) fail(`test-only path present in OpenAPI: ${path}`);
}

// 2. No duplicate operationIds; no test-only operationIds.
const seen = new Set();
let operationCount = 0;
for (const [, methods] of Object.entries(spec.paths ?? {})) {
  for (const [, op] of Object.entries(methods)) {
    if (!op || typeof op !== 'object' || !('operationId' in op)) continue;
    const id = op.operationId;
    operationCount += 1;
    if (id.startsWith('testing.')) fail(`test-only operationId present: ${id}`);
    if (seen.has(id)) fail(`duplicate operationId: ${id}`);
    seen.add(id);
  }
}

// 3. Every OpenAPI path must be present in the committed TypeScript.
for (const path of Object.keys(spec.paths ?? {})) {
  if (!committedTs.includes(`"${path}"`)) fail(`OpenAPI path missing from generated TypeScript: ${path}`);
}

// 4. The committed TypeScript must be byte-current with the spec (no drift).
const dir = mkdtempSync(join(tmpdir(), 'servana-apicheck-'));
const tmpTs = join(dir, 'api.ts');
try {
  execSync(`npx --no-install openapi-typescript "${specPath}" -o "${tmpTs}"`, { cwd: root, stdio: 'pipe' });
  const regenerated = readFileSync(tmpTs, 'utf8');
  if (norm(regenerated) !== norm(committedTs)) {
    fail('generated TypeScript is stale — run `npm run api:types` and commit resources/spa/src/types/generated/api.ts');
  }
} finally {
  rmSync(dir, { recursive: true, force: true });
}

console.log(`api:contract:check OK — ${Object.keys(spec.paths ?? {}).length} paths, ${operationCount} operations.`);
