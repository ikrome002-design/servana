#!/usr/bin/env node
// Phase UI-07 — prove the navigation-contract guards actually fire.
//
// A validator nobody has watched fail is a validator you are assuming works. Each control below
// takes a DISPOSABLE copy of the canonical authority, breaks exactly one invariant, and requires
// the validator to reject it. The real tree is never mutated: every mutation is written to a
// scratch file under the OS temp directory and deleted afterwards.
//
// Control 0 proves the unmodified copy PASSES, so a later failure means the mutation was
// rejected rather than the harness being broken.
//
// Usage: node scripts/ui07-negative-controls.mjs

import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import yaml from 'js-yaml';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const AUTHORITY = join(ROOT, 'docs/frontend/navigation/servana-user-account-navigation-map.yaml');
const scratch = mkdtempSync(join(tmpdir(), 'servana-ui07-'));

const original = yaml.load(readFileSync(AUTHORITY, 'utf8'));

/** Run the real validator against a disposable authority copy. */
function validate(name, contract) {
  const path = join(scratch, `${name}.yaml`);
  writeFileSync(path, yaml.dump(contract, { lineWidth: -1 }), 'utf8');

  const result = spawnSync(
    process.execPath,
    [join(ROOT, 'scripts/generate-ui07-navigation-contract.mjs'), '--validate-only', `--authority=${path}`],
    { cwd: ROOT, encoding: 'utf8' },
  );

  return { code: result.status, output: `${result.stdout}${result.stderr}` };
}

const clone = () => JSON.parse(JSON.stringify(original));

const CONTROLS = [
  [
    'control-unmodified-copy-passes',
    'the unmodified authority is accepted',
    () => clone(),
    'pass',
  ],
  [
    'missing-entry',
    'a removed page (159) is rejected',
    () => {
      const c = clone();
      c.pages.splice(40, 1);

      return c;
    },
    'fail',
  ],
  [
    'extra-entry',
    'an added page (161) is rejected',
    () => {
      const c = clone();
      const extra = JSON.parse(JSON.stringify(c.pages[0]));
      extra.key = 'super_administrator.invented';
      extra.screen_key = 'invented';
      extra.route_name = 'platform.invented';
      extra.route_path = '/invented';
      extra.order = 99;
      c.pages.push(extra);

      return c;
    },
    'fail',
  ],
  [
    'duplicate-entry',
    'a duplicated key is rejected',
    () => {
      const c = clone();
      c.pages[5] = JSON.parse(JSON.stringify(c.pages[4]));

      return c;
    },
    'fail',
  ],
  [
    'wrong-account',
    'a page assigned to the wrong account is rejected',
    () => {
      const c = clone();
      c.pages[0].account_type = 'merchant_finance';

      return c;
    },
    'fail',
  ],
  [
    'unknown-account',
    'a page naming an account that does not exist is rejected',
    () => {
      const c = clone();
      c.pages[0].account_type = 'merchant_marketing';

      return c;
    },
    'fail',
  ],
  [
    'duplicate-route-path-in-one-account',
    'two pages sharing a path within one account are rejected',
    () => {
      const c = clone();
      const first = c.pages.find((p) => p.account_type === 'merchant_finance');
      const second = c.pages.filter((p) => p.account_type === 'merchant_finance')[1];
      second.route_path = first.route_path;

      return c;
    },
    'fail',
  ],
  [
    'duplicate-route-name',
    'two pages sharing a route name are rejected',
    () => {
      const c = clone();
      c.pages[1].route_name = c.pages[0].route_name;

      return c;
    },
    'fail',
  ],
  [
    'duplicate-screen-key',
    'two pages sharing a screen key within one account are rejected',
    () => {
      const c = clone();
      const finance = c.pages.filter((p) => p.account_type === 'merchant_finance');
      finance[1].screen_key = finance[0].screen_key;

      return c;
    },
    'fail',
  ],
  [
    'cross-account-parent',
    'a parent in another account is rejected',
    () => {
      const c = clone();
      const child = c.pages.find((p) => p.parent_key !== null);
      child.parent_key = c.pages.find((p) => p.account_type !== child.account_type).key;

      return c;
    },
    'fail',
  ],
  [
    'parent-cycle',
    'a parent cycle is rejected',
    () => {
      const c = clone();
      const child = c.pages.find((p) => p.parent_key !== null);
      const parent = c.pages.find((p) => p.key === child.parent_key);
      parent.parent_key = child.key;

      return c;
    },
    'fail',
  ],
  [
    'missing-parent',
    'a parent that does not exist is rejected',
    () => {
      const c = clone();
      c.pages.find((p) => p.parent_key !== null).parent_key = 'merchant_finance.does-not-exist';

      return c;
    },
    'fail',
  ],
  [
    'unknown-owner-phase',
    'an owner phase outside UI-08..UI-15 is rejected',
    () => {
      const c = clone();
      c.pages[0].owner_phase = 'UI-99';

      return c;
    },
    'fail',
  ],
  [
    'unknown-status',
    'a status outside the closed vocabulary is rejected',
    () => {
      const c = clone();
      c.pages[0].implementation_status = 'phase_11';

      return c;
    },
    'fail',
  ],
  [
    'unknown-permission',
    'a permission key absent from the canonical matrix is rejected',
    () => {
      const c = clone();
      c.pages[0].permission_all = ['platform.invented.superpower'];

      return c;
    },
    'fail',
  ],
  [
    'planned-page-exposed-as-a-route',
    'a planned page naming a runtime route is rejected',
    () => {
      const c = clone();
      const planned = c.pages.find((p) => p.implementation_status === 'planned');
      planned.runtime_route_name = 'finance.invoices';

      return c;
    },
    'fail',
  ],
  [
    'implemented-page-without-a-route',
    'an implemented page with no runtime route is rejected',
    () => {
      const c = clone();
      c.pages.find((p) => p.implementation_status === 'implemented').runtime_route_name = null;

      return c;
    },
    'fail',
  ],
  [
    'implemented-page-naming-an-unregistered-route',
    'an implemented page naming a route the router does not have is rejected',
    () => {
      const c = clone();
      c.pages.find((p) => p.implementation_status === 'implemented').runtime_route_name = 'finance.imaginary';

      return c;
    },
    'fail',
  ],
  [
    'primary-navigation-on-a-parameterised-route',
    'a primary navigation entry resolving to a parameterised route is rejected',
    () => {
      const c = clone();
      const entry = c.pages.find(
        (p) => p.implementation_status === 'implemented' && p.navigation_visibility === 'primary',
      );
      entry.runtime_route_name = 'branch.detail';

      return c;
    },
    'fail',
  ],
  [
    'gate-blocked-page-without-a-gate',
    'a disabled_by_gate page that does not name its gate is rejected',
    () => {
      const c = clone();
      c.pages.find((p) => p.implementation_status === 'disabled_by_gate').gate = null;

      return c;
    },
    'fail',
  ],
  [
    'non-navigation-route-without-a-reason',
    'a non-primary entry with no recorded reason is rejected',
    () => {
      const c = clone();
      c.pages.find((p) => p.navigation_visibility !== 'primary').non_navigation_reason = null;

      return c;
    },
    'fail',
  ],
  [
    'forbidden-for-its-own-account',
    'a page forbidding its own account is rejected',
    () => {
      const c = clone();
      c.pages[0].forbidden_for = [...c.pages[0].forbidden_for, c.pages[0].account_type];

      return c;
    },
    'fail',
  ],
  [
    'account-count-drift',
    'an account whose page count no longer matches its declared requirement is rejected',
    () => {
      const c = clone();
      c.accounts.find((a) => a.account_type === 'merchant_finance').required_pages = 25;

      return c;
    },
    'fail',
  ],
  [
    'total-drift',
    'a declared total that disagrees with the summed accounts is rejected',
    () => {
      const c = clone();
      c.total_required_pages = 161;

      return c;
    },
    'fail',
  ],
];

let failures = 0;
const results = [];

for (const [name, description, mutate, expected] of CONTROLS) {
  const { code, output } = validate(name, mutate());
  const actual = code === 0 ? 'pass' : 'fail';
  const ok = actual === expected;

  if (!ok) failures += 1;
  results.push({ control: name, description, expected, actual, ok });

  const mark = ok ? 'OK  ' : 'MISS';
  console.log(`${mark} ${name} — ${description}`);
  if (!ok) {
    console.log(output.split('\n').slice(0, 6).map((l) => `       ${l}`).join('\n'));
  }
}

rmSync(scratch, { recursive: true, force: true });

writeFileSync(
  join(ROOT, 'docs/frontend/audits/ui-07/negative-control-results.json'),
  `${JSON.stringify(
    {
      schema: 'ui-07.audit.v1',
      phase: 'UI-07',
      generated_by: 'scripts/ui07-negative-controls.mjs',
      purpose:
        'Proof that each canonical-contract guard rejects the defect it exists to catch. Every mutation is applied to a disposable copy under the OS temp directory; the tracked authority is never modified.',
      controls: results.length,
      passed: results.filter((r) => r.ok).length,
      failed: failures,
      results,
    },
    null,
    2,
  )}\n`,
  'utf8',
);

console.log(`\n${results.length - failures}/${results.length} negative controls behaved as required.`);
process.exit(failures === 0 ? 0 : 1);
