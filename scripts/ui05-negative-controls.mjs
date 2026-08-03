#!/usr/bin/env node
// Phase UI-05 — negative controls for the content and asset generators.
//
// A generator that fails closed is only worth anything if the failures actually fire. Each control
// below breaks ONE thing in a disposable copy of the repository and requires the generator to exit
// non-zero with a message naming that specific problem. A control that stops failing is itself a
// defect: it means the guard it exercises has gone quiet.
//
// Nothing here touches the working tree. Every mutation happens inside an OS temporary directory
// that is removed afterwards, so no negative-control mutation can be left behind and committed.
//
// Usage: node scripts/ui05-negative-controls.mjs [--json]

import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync, symlinkSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const AS_JSON = process.argv.includes('--json');

/** Everything a generator reads. Copied per control so a mutation cannot escape the sandbox. */
const SANDBOX_PATHS = [
  'scripts/generate-role-content.mjs',
  'scripts/generate-landing-images.mjs',
  'config/account-hosts.json',
  'config/landing-image-selection.json',
  'config/brand-asset-quarantine.json',
  'docs/landing_page',
  'docs/legal',
  'docs/support/faq',
  'docs/brand/quarantine',
  'docs/frontend/audits/ui-05',
  'docs/frontend/source-inventory',
  'resources/spa/src/content/generated',
  'public/assets/brand',
  'public/assets/landing_page_images',
];

function sandbox() {
  const dir = mkdtempSync(join(tmpdir(), 'servana-ui05-nc-'));
  for (const path of SANDBOX_PATHS) {
    const from = join(ROOT, path);
    if (existsSync(from)) {
      cpSync(from, join(dir, path), { recursive: true });
    }
  }
  // The asset generator imports `sharp`. Link rather than copy: node_modules is hundreds of
  // megabytes and no control mutates it.
  symlinkSync(join(ROOT, 'node_modules'), join(dir, 'node_modules'), process.platform === 'win32' ? 'junction' : 'dir');

  return dir;
}

/**
 * The timestamp the committed manifest already carries.
 *
 * The sandbox has no git history, so the generator's git fallback cannot run there. Feeding it the
 * value the artifacts were built with keeps the UNMUTATED baseline byte-identical — which is what
 * makes "the mutation caused this failure" a sound conclusion. Using an arbitrary epoch instead
 * would make every baseline report STALE and every control pass for the wrong reason.
 */
const BASELINE_EPOCH = String(Math.floor(
  Date.parse(JSON.parse(readFileSync(join(ROOT, 'docs/frontend/audits/ui-05/content-source-manifest.json'), 'utf8')).source_timestamp) / 1000,
));

const readJson = (dir, path) => JSON.parse(readFileSync(join(dir, path), 'utf8'));
const writeJson = (dir, path, value) => writeFileSync(join(dir, path), `${JSON.stringify(value, null, 2)}\n`, 'utf8');

/** Run a generator inside the sandbox and capture how it failed. */
function run(dir, script, args = ['--check']) {
  try {
    const stdout = execFileSync(process.execPath, [join(dir, 'scripts', script), ...args], {
      cwd: dir,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, SOURCE_DATE_EPOCH: BASELINE_EPOCH },
    });
    return { exitCode: 0, output: stdout };
  } catch (error) {
    return { exitCode: error.status ?? -1, output: `${error.stdout ?? ''}${error.stderr ?? ''}` };
  }
}

const CONTROLS = [
  {
    id: 'missing-source-file',
    description: 'one of the forty source documents is deleted',
    script: 'generate-role-content.mjs',
    expect: /Missing faq source for merchant_finance/,
    mutate: (dir) => unlinkSync(join(dir, 'docs/support/faq/merchant_finance_faq.md')),
  },
  {
    id: 'duplicate-role-category-mapping',
    description: 'two accounts claim the same content key',
    script: 'generate-role-content.mjs',
    expect: /will not cross-map/,
    mutate: (dir) => {
      const registry = readJson(dir, 'config/account-hosts.json');
      registry.accounts.find((a) => a.account_key === 'merchant_audit').legal_content_key = 'merchant_finance';
      writeJson(dir, 'config/account-hosts.json', registry);
    },
  },
  {
    id: 'source-hash-changed-without-regeneration',
    description: 'an approved source document is edited but the artifacts are not regenerated',
    script: 'generate-role-content.mjs',
    expect: /STALE/,
    mutate: (dir) => {
      const path = join(dir, 'docs/legal/privacy_policy/merchant_branch_privacy_policy.md');
      writeFileSync(path, `${readFileSync(path, 'utf8')}\n\nAn unapproved edit.\n`, 'utf8');
    },
  },
  {
    id: 'generated-module-hand-edited',
    description: 'a generated module is edited by hand',
    script: 'generate-role-content.mjs',
    expect: /STALE/,
    mutate: (dir) => {
      const path = join(dir, 'resources/spa/src/content/generated/merchant_audit/terms-of-service.generated.ts');
      writeFileSync(path, `${readFileSync(path, 'utf8')}\n// hand edit\n`, 'utf8');
    },
  },
  {
    id: 'unsafe-raw-html-in-source',
    description: 'raw HTML appears in an approved document',
    script: 'generate-role-content.mjs',
    expect: /raw HTML <script>/,
    mutate: (dir) => {
      const path = join(dir, 'docs/legal/data_policy/merchant_personnel_data_policy.md');
      writeFileSync(path, `${readFileSync(path, 'utf8')}\n<script>alert(1)</script>\n`, 'utf8');
    },
  },
  {
    id: 'unsafe-link-scheme-in-source',
    description: 'a javascript: link appears in an approved document',
    script: 'generate-role-content.mjs',
    expect: /unsafe link target — javascript:/,
    mutate: (dir) => {
      const path = join(dir, 'docs/support/faq/merchant_branch_faq.md');
      writeFileSync(path, `${readFileSync(path, 'utf8')}\n[Press here](javascript:alert(1))\n`, 'utf8');
    },
  },
  {
    id: 'landing-section-maps-to-no-region',
    description: 'a landing document grows a section the region map does not know',
    script: 'generate-role-content.mjs',
    expect: /maps to no plan region/,
    mutate: (dir) => {
      const path = join(dir, 'docs/landing_page/merchant_finance_landing_page_content.md');
      writeFileSync(path, `${readFileSync(path, 'utf8')}\n\n## 17. Completely New Section\n\nBody.\n`, 'utf8');
    },
  },
  {
    id: 'manifest-image-missing',
    description: 'a selected source image is deleted',
    script: 'generate-landing-images.mjs',
    expect: /does not exist/,
    mutate: (dir) => unlinkSync(join(dir, 'public/assets/landing_page_images/merchant_finance/1.png')),
  },
  {
    id: 'derivative-missing',
    description: 'a generated derivative is deleted',
    script: 'generate-landing-images.mjs',
    expect: /Missing derivative/,
    mutate: (dir) => {
      const manifest = readJson(dir, 'public/assets/landing_page_images/manifest.json');
      unlinkSync(join(dir, manifest.images[0].derivatives[0].path));
    },
  },
  {
    id: 'derivative-hash-stale',
    description: 'a derivative\'s bytes change without the manifest being regenerated',
    script: 'generate-landing-images.mjs',
    expect: /Stale artifact/,
    mutate: (dir) => {
      const manifest = readJson(dir, 'public/assets/landing_page_images/manifest.json');
      const target = join(dir, manifest.images[0].derivatives[0].path);
      const bytes = readFileSync(target);
      // Append a byte: still a decodable file, different hash — exactly the silent-drift case.
      writeFileSync(target, Buffer.concat([bytes, Buffer.from([0x00])]));
    },
  },
  {
    id: 'image-selected-from-another-role',
    description: 'a role selects an image that is not in its own directory',
    script: 'generate-landing-images.mjs',
    expect: /is not a plain file name in the account's own directory/,
    mutate: (dir) => {
      const selection = readJson(dir, 'config/landing-image-selection.json');
      selection.roles.merchant_audit[0].file = '../merchant_finance/1.png';
      writeJson(dir, 'config/landing-image-selection.json', selection);
    },
  },
  {
    id: 'image-targets-a-region-the-source-lacks',
    description: 'a role maps an image to a landing region its own content does not supply',
    script: 'generate-landing-images.mjs',
    expect: /which its landing document does not supply/,
    mutate: (dir) => {
      const selection = readJson(dir, 'config/landing-image-selection.json');
      selection.roles.super_administrator[1].region = 'testimonials';
      writeJson(dir, 'config/landing-image-selection.json', selection);
    },
  },
  {
    id: 'non-decorative-image-without-alt-text',
    description: 'a non-decorative image is left with empty alternative text',
    script: 'generate-landing-images.mjs',
    expect: /needs descriptive alternative text|uses its file name/,
    mutate: (dir) => {
      const selection = readJson(dir, 'config/landing-image-selection.json');
      selection.roles.merchant_branch[1].alt = '3.png';
      writeJson(dir, 'config/landing-image-selection.json', selection);
    },
  },
  {
    id: 'too-many-images-selected',
    description: 'a role selects more than four primary images',
    script: 'generate-landing-images.mjs',
    expect: /the policy allows 2–4/,
    mutate: (dir) => {
      const selection = readJson(dir, 'config/landing-image-selection.json');
      selection.roles.merchant_personnel.push({ file: '3.png', region: 'benefits', loading: 'lazy', fetch_priority: 'auto', alt: 'A description long enough to pass the alt-text length rule.' });
      selection.roles.merchant_personnel.push({ file: '6.png', region: 'use_cases', loading: 'lazy', fetch_priority: 'auto', alt: 'Another description long enough to pass the alt-text rule.' });
      writeJson(dir, 'config/landing-image-selection.json', selection);
    },
  },
  {
    id: 'quarantined-file-returned-to-the-public-tree',
    description: 'an unapproved brand working file reappears under public/assets/brand',
    script: 'generate-landing-images.mjs',
    expect: /still inside the publicly served brand tree|still served from the brand tree/,
    mutate: (dir) => {
      const record = readJson(dir, 'config/brand-asset-quarantine.json');
      const file = record.files[0];
      cpSync(join(dir, file.quarantine_path), join(dir, file.original_path));
    },
  },
  {
    id: 'quarantined-bytes-altered',
    description: 'an archived file\'s bytes change in quarantine',
    script: 'generate-landing-images.mjs',
    expect: /does not match the recorded hash|is \d+ bytes; the record says/,
    mutate: (dir) => {
      const record = readJson(dir, 'config/brand-asset-quarantine.json');
      const target = join(dir, record.files[0].quarantine_path);
      writeFileSync(target, Buffer.concat([readFileSync(target), Buffer.from([0x00])]));
    },
  },
  {
    id: 'deleted-logo-restored',
    description: 'the authorised Logo.svg deletion is reversed',
    script: 'generate-landing-images.mjs',
    expect: /must stay absent/,
    mutate: (dir) => writeFileSync(join(dir, 'public/assets/brand/Logo.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>', 'utf8'),
  },
];

function main() {
  const results = [];

  for (const control of CONTROLS) {
    const dir = sandbox();
    try {
      // Prove the sandbox is clean BEFORE mutating: a control that "fails" in an already-broken
      // copy proves nothing at all.
      const baseline = run(dir, control.script);
      control.mutate(dir);
      const mutated = run(dir, control.script);

      const passed = baseline.exitCode === 0
        && mutated.exitCode !== 0
        && control.expect.test(mutated.output);

      results.push({
        id: control.id,
        description: control.description,
        script: control.script,
        baseline_exit_code: baseline.exitCode,
        mutated_exit_code: mutated.exitCode,
        expected_pattern: String(control.expect),
        passed,
        detail: passed ? null : `baseline=${baseline.exitCode} mutated=${mutated.exitCode}\n${mutated.output.slice(0, 600)}`,
      });
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }

  const failed = results.filter((result) => !result.passed);

  if (AS_JSON) {
    console.log(JSON.stringify({ total: results.length, failed: failed.length, controls: results }, null, 2));
  } else {
    for (const result of results) {
      console.log(`${result.passed ? 'ok  ' : 'FAIL'}  ${result.id} — ${result.description}`);
      if (!result.passed) {
        console.log(result.detail);
      }
    }
    console.log(`\n${results.length} negative controls, ${failed.length} failures.`);
  }

  process.exit(failed.length === 0 ? 0 : 1);
}

main();
