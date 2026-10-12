import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Phase UI-16 source guards — each pins a defect class the UI-16 browser audit found, and each has
 * a negative control proving the guard still fires (a guard that cannot fail proves nothing).
 *
 *  - UI16-RESP-001: `style.css` sets `--breakpoint-sm/xl/2xl: initial`, so only the token-owned
 *    `md` (768) and `lg` (1025) screens exist. 76 `sm:`/`xl:` utilities across 45 files compiled to
 *    NOTHING — every `sm:grid-cols-2` silently stayed one column at every width.
 *  - UI16-FUNC-001: a `<SvDialog>` used without an import is not an error to vue-tsc or the build;
 *    Vue renders it as an unknown element whose children are ALWAYS visible, so the Front Office
 *    payment "confirmation modal" was an inline block that never opened or closed.
 *  - UI16-DATA-001: `help="entry.hint"` (no colon) is a static string, so the platform billing page
 *    printed the literal text "entry.hint" under three fields.
 */

const SRC = resolve(__dirname, '..');

function vueFiles(dir: string = SRC): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) out.push(...vueFiles(path));
    else if (entry.endsWith('.vue')) out.push(path);
  }
  return out;
}

/** The `<template>` of a single-file component, with HTML comments removed. */
function templateOf(source: string): string {
  const start = source.indexOf('<template>');
  if (start === -1) return '';
  return source.slice(start).replace(/<!--[\s\S]*?-->/g, '');
}

/** Responsive prefixes that the configured screens do not define. */
export function deadBreakpointClasses(template: string): string[] {
  const found: string[] = [];
  for (const attr of template.matchAll(/\s:?class="([^"]*)"/g)) {
    for (const hit of attr[1]!.matchAll(/(?<![\w-])((?:sm|xl|2xl):[\w[\]/.-]+)/g)) found.push(hit[1]!);
  }
  return found;
}

/** `Sv*` components a template renders that its script never imports. */
export function unimportedSvComponents(source: string, ownName: string): string[] {
  const template = templateOf(source);
  const used = new Set(Array.from(template.matchAll(/<(Sv[A-Z]\w*)\b/g), (m) => m[1]!));
  return [...used].filter((name) => name !== ownName && !new RegExp(`import\\s+${name}\\b|import\\s*\\{[^}]*\\b${name}\\b`).test(source));
}

/** Static attributes whose value is a member expression — almost always a missing `:`. */
export function staticExpressionAttributes(template: string): string[] {
  return Array.from(
    template.matchAll(/\s(help|label|title|description|placeholder|eyebrow|hint|message|empty-message|error-message|aria-label)="([a-z_][\w]*\.[a-z_][\w.]*)"/gi),
    (m) => `${m[1]}="${m[2]}"`,
  );
}

/**
 * UI16-CONTENT-003: `new Date(x).toLocaleString()` formats in the VIEWER'S zone and locale, so a
 * Nairobi business time read from a browser elsewhere shows the wrong hour ("8/12/2026, 8:00:00 AM").
 * Display goes through `utils/dates` (Africa/Nairobi) or names the zone explicitly.
 */
export function zonelessDateFormatting(source: string): string[] {
  return Array.from(
    source.matchAll(/new Date\([^)]*\)\.toLocale(?:Date|Time)?String\(([^)]*)\)/g),
    (m) => m[0],
  ).filter((call) => !/timeZone/.test(call));
}

describe('UI-16 source guards', () => {
  const files = vueFiles();

  it('scans the whole SPA, so a silent empty scan cannot pass', () => {
    // 269 single-file components when UI-16 landed; a scan that finds far fewer is broken.
    expect(files.length).toBeGreaterThan(250);
  });

  it('uses no sm:, xl: or 2xl: utility — only the md and lg screens exist (UI16-RESP-001)', () => {
    const offenders = files.flatMap((file) =>
      deadBreakpointClasses(templateOf(readFileSync(file, 'utf8'))).map((cls) => `${relative(SRC, file)}: ${cls}`),
    );
    expect(offenders).toEqual([]);
  });

  it('imports every Sv component a template renders (UI16-FUNC-001)', () => {
    const offenders = files.flatMap((file) => {
      const name = file.split(/[\\/]/).pop()!.replace(/\.vue$/, '');
      return unimportedSvComponents(readFileSync(file, 'utf8'), name).map((c) => `${relative(SRC, file)}: <${c}>`);
    });
    expect(offenders).toEqual([]);
  });

  it('binds every expression-valued attribute (UI16-DATA-001)', () => {
    const offenders = files.flatMap((file) =>
      staticExpressionAttributes(templateOf(readFileSync(file, 'utf8'))).map((a) => `${relative(SRC, file)}: ${a}`),
    );
    expect(offenders).toEqual([]);
  });

  it('formats no date in the viewer zone (UI16-CONTENT-003)', () => {
    const offenders = files.flatMap((file) =>
      zonelessDateFormatting(readFileSync(file, 'utf8')).map((call) => `${relative(SRC, file)}: ${call}`),
    );
    expect(offenders).toEqual([]);
  });

  describe('negative controls — each guard still fires', () => {
    it('detects a zoneless date format, and accepts a zoned one', () => {
      expect(zonelessDateFormatting('{{ new Date(row.at).toLocaleString() }}')).toHaveLength(1);
      expect(zonelessDateFormatting("new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Nairobi' })")).toEqual([]);
    });

    it('detects a dead breakpoint, and ignores the configured ones', () => {
      expect(deadBreakpointClasses('<div class="grid sm:grid-cols-2 md:grid-cols-3 xl:gap-4">')).toEqual(['sm:grid-cols-2', 'xl:gap-4']);
      expect(deadBreakpointClasses('<div class="md:flex lg:block">')).toEqual([]);
    });

    it('detects an unimported Sv component, and accepts an imported one', () => {
      const broken = "<script setup lang=\"ts\">\nimport SvButton from '@/components/ui/SvButton.vue';\n</script>\n<template><SvDialog><SvButton /></SvDialog></template>";
      expect(unimportedSvComponents(broken, 'Page')).toEqual(['SvDialog']);
      expect(unimportedSvComponents(broken.replace("import SvButton", "import SvDialog from 'x';\nimport SvButton"), 'Page')).toEqual([]);
    });

    it('detects a static member-expression attribute, and accepts a bound one', () => {
      expect(staticExpressionAttributes('<SvTextInput help="entry.hint" />')).toEqual(['help="entry.hint"']);
      expect(staticExpressionAttributes('<SvTextInput :help="entry.hint" label="Grace days" />')).toEqual([]);
    });
  });
});
