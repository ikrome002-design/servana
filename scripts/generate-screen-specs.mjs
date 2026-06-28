#!/usr/bin/env node
// Generates one §27.1-format screen specification per inventory entry that
// declares a `spec` path (implemented, phase_11, and access-state screens).
// Source of truth: docs/frontend/screens/inventory.json. Run: node scripts/generate-screen-specs.mjs
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const screensDir = join(root, 'docs', 'frontend', 'screens');
const inventory = JSON.parse(readFileSync(join(screensDir, 'inventory.json'), 'utf8'));

function list(arr) {
  return arr && arr.length ? arr.map((x) => `\`${x}\``).join(', ') : '—';
}

function spec(s) {
  const route = s.route ? `\`${s.route}\`` : 'none (rendered within a layout/boundary)';
  const perms = list(s.permissions);
  const roles = list(s.roles);
  return `# Screen specification — ${s.title}

> Generated from \`docs/frontend/screens/inventory.json\` (Plan §27.1). Status: **${s.status}** · Owning phase: **${s.phase}**. Edit the inventory + regenerate (\`node scripts/generate-screen-specs.mjs\`); the owning phase writes the final detailed spec before implementing future behavior.

- **Screen key:** \`${s.key}\`
- **Route name and URL:** ${route}
- **Layout:** \`${s.layout}\`
- **Allowed roles:** ${roles}
- **Required permissions:** ${perms} (frontend visibility only; backend EnsurePermission + policy is authoritative)
- **Merchant / branch / own scope:** per role boundary (Plan §14–§16); branch-scoped roles resolve branch from the bootstrap.
- **Required entitlement:** none for the Phase 11 foundation; entitlement gating applies in the owning feature phase.
- **Billing-state behavior:** read-only-grace and suspended-billing follow the §19.2 allowlist; foundation surfaces are read-only.
- **API dependencies:** \`GET /api/v1/me\` bootstrap; ${s.status === 'implemented' ? 'plus this screen’s existing endpoints.' : 'no new endpoints in Phase 11.'}
- **Fields and displayed data:** ${s.summary}
- **Primary / secondary / destructive actions:** navigation and (where live) the screen’s existing actions; destructive actions require typed confirmation (Plan §31). No future-phase actions are live.
- **Confirmation behavior:** destructive/financial confirmations show readable amounts; legal acknowledgement requires explicit, non-prefilled consent.
- **Loading / empty / error / success states:** via \`SvStateBoundary\`; landing/get-started show useful empty states.
- **No-permission / no-branch states:** permissioned controls hidden via \`PermissionGate\`; branch-scoped roles show a no-branch boundary.
- **Locked / read-only / suspended-billing states:** read-only foundation; suspended/locked handled by the owning phase.
- **Mobile / tablet / desktop transformation:** responsive at 360 / 768 / 1280; merchant roles use sidebar (desktop) + drawer (mobile); Super Administrator uses header nav collapsing to a disclosure.
- **Keyboard and screen-reader behavior:** skip link, landmarks, visible focus, 44px targets, aria-current on the active nav item, drawer focus return.
- **Dark-mode requirements:** light + dark via design tokens; AA contrast (ADR-009: no white text on Savannah-Orange CTA).
- **Audit events triggered:** none new in Phase 11.
- **Unit / component / e2e tests:** see \`resources/spa/src/**/*.spec.ts\` and \`tests/e2e/role-*.spec.ts\` (navigation parity, role entry routes, get-started persistence, landing content, layout placement, responsive/dark/axe).
`;
}

let written = 0;
for (const s of inventory.screens) {
  if (!s.spec) continue;
  const out = join(screensDir, s.spec);
  mkdirSync(dirname(out), { recursive: true });
  writeFileSync(out, spec(s));
  written += 1;
}

if (!existsSync(screensDir)) {
  throw new Error('screens dir missing');
}
console.log(`Generated ${written} screen specifications under docs/frontend/screens/.`);
