<?php

declare(strict_types=1);

uses()->group('design-system', 'ui-04', 'contracts', 'source-guard');

/*
 |==============================================================================
 | Phase UI-04 — icon source guard (UI01-ASSET-001; ADR-021 §5; UI/UX plan §9.4).
 |
 | The audited defect: Heroicons was not a dependency at all, the application shell rendered `☰`,
 | `✕`, `☀` and `☾` as literal glyphs on every authenticated page, and the design-system demo
 | rendered emoji sun/moon in a button label.
 |
 | This guard makes recurrence a build failure. It is deliberately narrow:
 |  - it scans PRODUCTION UI implementation only — never legal copy, never role content, never
 |    arbitrary fixture text, because ordinary prose is not an icon implementation;
 |  - it strips comments first, so a comment naming the defect is not itself a violation;
 |  - it has NEGATIVE CONTROLS proving the matcher fires on the exact glyphs UI-01 recorded.
 */

/**
 * Emoji and decorative-symbol ranges that appear as UI iconography.
 *
 * Chosen from what a component author would actually reach for, not "every non-ASCII character":
 * Servana's UI text legitimately contains typographic punctuation (’ — …) and the § sign, and a
 * guard that rejected those would be noise rather than signal.
 */
const UI04_ICON_GLYPH_PATTERN = '/'
    .'[\x{1F300}-\x{1FAFF}]'   // emoji: pictographs, transport, supplemental, symbols & extended
    .'|[\x{1F000}-\x{1F2FF}]'  // mahjong/domino/playing cards, enclosed alphanumerics
    .'|[\x{2600}-\x{27BF}]'    // misc symbols + dingbats: ☀ ☾ ✕ ✓ ★ ➜ ✉ ⚠ …
    .'|[\x{2300}-\x{23FF}]'    // misc technical: ⌘ ⏰ …
    .'|\x{2630}|\x{2261}'      // ☰ trigram / ≡ used as a hamburger
    .'|[\x{FE0F}\x{FE0E}]'     // variation selectors (emoji presentation)
    .'/u';

/**
 * Glyphs that are an icon in one position and legitimate TEXT in another.
 *
 * UI01-ASSET-001 is explicit: "Only glyphs used as icons are in scope. Text content is
 * unaffected." An arrow leading a back-link (`← Back to the queue`) is an icon. The same arrow
 * between two values (`{{ period_start }} → {{ period_end }}`, `Severity (high→low)`) is prose,
 * and rewriting those as components would be a change the defect never asked for.
 *
 * The distinguishing signal is POSITION: an icon sits at the edge of its text run with nothing
 * beside it; a prose arrow is flanked by content on both sides.
 */
const UI04_POSITIONAL_GLYPH_PATTERN = '/'
    .'[\x{2190}-\x{21FF}]'     // arrows: ← → ↑ ↓ …
    .'|[\x{2B00}-\x{2BFF}]'    // supplemental arrows and geometric shapes
    .'|[\x{25A0}-\x{25FF}]'    // geometric shapes: ■ ▲ ● …
    .'/u';

/**
 * Whether a positional glyph at $offset is being used as an ICON rather than as prose.
 *
 * Flanked on BOTH sides by content — a word character, or the `}}`/`{{` of an interpolation —
 * means prose. Anything else (start of the text run, end of it, or adjacent only to markup) is a
 * decorative glyph standing in for an icon.
 */
function ui04GlyphIsIconic(string $line, int $offset, string $glyph): bool
{
    $before = rtrim(substr($line, 0, $offset));
    $after = ltrim(substr($line, $offset + strlen($glyph)));

    $hasContentBefore = preg_match('/(\w|\}\})$/u', $before) === 1;
    $hasContentAfter = preg_match('/^(\w|\{\{)/u', $after) === 1;

    return ! ($hasContentBefore && $hasContentAfter);
}

/** @return list<string> absolute paths of production UI implementation */
function ui04IconScanTargets(): array
{
    $roots = [
        base_path('resources/spa/src/components'),
        base_path('resources/spa/src/layouts'),
        base_path('resources/spa/src/pages'),
        base_path('resources/spa/src/navigation'),
        base_path('resources/spa/src/router'),
        base_path('resources/spa/src/design-system'),
    ];

    $files = [];
    foreach ($roots as $root) {
        foreach (sourceFilesUnder($root, ['vue', 'ts']) as $path) {
            if (str_ends_with($path, '.spec.ts')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

/**
 * Strip comments so a comment quoting the defect is not itself flagged.
 *
 * LINE-PRESERVING: a multi-line comment is replaced by its own newlines rather than removed, so
 * the line numbers this guard reports still point at the real source. A guard that misreports
 * where the violation is cannot be acted on.
 */
function ui04StripIconComments(string $contents): string
{
    $blank = static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]);

    $stripped = (string) preg_replace_callback('#/\*.*?\*/#s', $blank, $contents);
    $stripped = (string) preg_replace_callback('#<!--.*?-->#s', $blank, $stripped);
    $stripped = (string) preg_replace_callback('#\{\{--.*?--\}\}#s', $blank, $stripped);
    $lines = array_map(
        static fn (string $line): string => (string) preg_replace('#(?<!:)//.*$#', '', $line),
        explode("\n", $stripped),
    );

    return implode("\n", $lines);
}

/** @return list<string> */
function ui04ScanForIconGlyphs(string $relative, string $body): array
{
    $violations = [];

    foreach (explode("\n", $body) as $number => $line) {
        // Unconditional: an emoji or dingbat is never prose in Servana's UI.
        if (preg_match_all(UI04_ICON_GLYPH_PATTERN, $line, $matches) > 0) {
            foreach ($matches[0] as $glyph) {
                $violations[] = sprintf('%s:%d icon glyph %s (U+%04X)', $relative, $number + 1, $glyph, mb_ord($glyph));
            }
        }

        // Positional: an arrow or shape counts only when it is used AS an icon.
        //
        // Tags are blanked first (length-preserving, so offsets still line up). Without this, a
        // date range split by an element — `{{ from }}<span …> → {{ to }}` — reads as "adjacent
        // to markup" and is misclassified as an icon. What matters is the TEXT either side.
        $text = (string) preg_replace_callback(
            '/<[^>]*>/',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $line,
        );

        if (preg_match_all(UI04_POSITIONAL_GLYPH_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$glyph, $offset]) {
                if (! ui04GlyphIsIconic($text, $offset, $glyph)) {
                    continue;
                }
                $violations[] = sprintf(
                    '%s:%d decorative glyph %s (U+%04X) used as an icon',
                    $relative,
                    $number + 1,
                    $glyph,
                    mb_ord($glyph),
                );
            }
        }
    }

    return $violations;
}

it('installs exactly one icon library, pinned', function (): void {
    /** @var array{dependencies?: array<string, string>, devDependencies?: array<string, string>} $package */
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    $dependencies = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);

    // The mandated library is present (UI-01 found it absent entirely) …
    expect($dependencies)->toHaveKey('@heroicons/vue');
    // … pinned exactly, so an icon set cannot change under a caret range …
    expect($dependencies['@heroicons/vue'])->toMatch('/^\d+\.\d+\.\d+$/');
    // … and it is the ONLY icon library, so the product cannot end up with two visual languages.
    $competing = array_values(array_filter(
        array_keys($dependencies),
        static fn (string $name): bool => (bool) preg_match(
            '/(font-?awesome|material-icons|@mdi|feather-icons|lucide|bootstrap-icons|remixicon|iconify|phosphor)/i',
            $name,
        ),
    ));
    expect($competing)->toBe([], 'a second icon library was added: '.implode(', ', $competing));
});

it('finds no emoji or decorative glyph used as an icon in production UI source', function (): void {
    $violations = [];

    foreach (ui04IconScanTargets() as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
        $violations = array_merge(
            $violations,
            ui04ScanForIconGlyphs($relative, ui04StripIconComments((string) file_get_contents($path))),
        );
    }

    expect($violations)->toBe([], sprintf(
        "%d icon glyph(s) in production UI source (UI01-ASSET-001).\nUse a Heroicons component from resources/spa/src/design-system/icons.ts:\n%s",
        count($violations),
        implode("\n", $violations),
    ));
});

it('imports Heroicons individually so the catalogue is never bundled', function (): void {
    // A wildcard or default import of the package would ship every icon to every account host.
    $problems = [];

    foreach (ui04IconScanTargets() as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
        $body = ui04StripIconComments((string) file_get_contents($path));

        if (preg_match('/import\s+\*\s+as\s+\w+\s+from\s+[\'"]@heroicons/', $body) === 1) {
            $problems[] = "{$relative}: namespace import of @heroicons";
        }
        // A bare default import of the package root has the same effect.
        if (preg_match('/import\s+\w+\s+from\s+[\'"]@heroicons\/vue[\'"]/', $body) === 1) {
            $problems[] = "{$relative}: default import of the @heroicons/vue root";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('routes every icon through the curated design-system module or a pinned subpath', function (): void {
    $problems = [];

    foreach (ui04IconScanTargets() as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
        $body = ui04StripIconComments((string) file_get_contents($path));

        preg_match_all('/from\s+[\'"](@heroicons\/[^\'"]+)[\'"]/', $body, $matches);
        foreach ($matches[1] as $specifier) {
            // Only the versioned 24px subpaths are permitted, so the size/stroke policy holds.
            if (! in_array($specifier, ['@heroicons/vue/24/outline', '@heroicons/vue/24/solid'], true)) {
                $problems[] = "{$relative}: imports from '{$specifier}' (allowed: 24/outline, 24/solid)";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('keeps the curated icon module free of a runtime name-to-component map', function (): void {
    // UI/UX plan §12.1: a `<SvIcon name="…" />` lookup table would have to reference every icon
    // to resolve an arbitrary string, defeating tree-shaking. Static re-exports only.
    $icons = (string) file_get_contents(base_path('resources/spa/src/design-system/icons.ts'));

    expect($icons)->toContain('export {');
    expect($icons)->not->toContain('import * as');
    // No object literal mapping strings to icon components.
    expect(preg_match('/const\s+\w*(?:ICON_MAP|IconMap|ICONS_BY_NAME)\w*\s*[:=]/', $icons))->toBe(0);
});

it('scans a real, non-empty set of files', function (): void {
    $files = ui04IconScanTargets();

    expect(count($files))->toBeGreaterThan(100, 'the icon scan collected suspiciously few files');
});

/*
 |------------------------------------------------------------------------------
 | Negative controls — the matcher must fire on the EXACT glyphs UI-01 recorded.
 |------------------------------------------------------------------------------
 */

it('detects the audited shell glyphs (negative control)', function (): void {
    // resources/spa/src/components/layout/AppShell.vue:119, :161, :193, :256 as audited.
    $body = "<span aria-hidden=\"true\">☰</span>\n"
        ."<span aria-hidden=\"true\">{{ dark ? '☀' : '☾' }}</span>\n"
        .'<span aria-hidden="true">✕</span>';

    $violations = ui04ScanForIconGlyphs('AppShell.vue', $body);

    expect(count($violations))->toBe(4);
});

it('detects the audited design-system emoji (negative control)', function (): void {
    // resources/spa/src/pages/dev/DesignSystemDemo.vue:61 as audited.
    $body = "{{ dark ? '☀️ Switch to light' : '🌙 Switch to dark' }}";

    expect(ui04ScanForIconGlyphs('DesignSystemDemo.vue', $body))->not->toBe([]);
});

it('does not flag ordinary typographic punctuation (negative control)', function (): void {
    // Proving the matcher is not over-broad. Servana copy legitimately uses these, and a guard
    // that rejected them would be noise — which is how guards get disabled.
    $body = '<p>We couldn’t load this — try again… (Plan §11.5)</p>';

    expect(ui04ScanForIconGlyphs('Fine.vue', $body))->toBe([]);
});

it('does not flag a glyph that appears only in a comment (negative control)', function (): void {
    $body = ui04StripIconComments("// UI-01 found ☰ and ✕ rendered as literal glyphs here.\n<SvIconMenu />");

    expect(ui04ScanForIconGlyphs('AppShell.vue', $body))->toBe([]);
});

it('reports accurate line numbers past a multi-line comment (negative control)', function (): void {
    // A stripper that DELETED comment lines would report this violation on line 2 rather than 5,
    // and the guard would send a reader to the wrong place.
    $body = ui04StripIconComments("<template>\n/*\n a\n b\n*/\n<span>📋</span>");

    $violations = ui04ScanForIconGlyphs('Fake.vue', $body);

    expect($violations)->toHaveCount(1);
    expect($violations[0])->toContain('Fake.vue:6');
});

it('flags an arrow leading a back-link (negative control)', function (): void {
    // The genuine icon case UI-01 recorded across eight detail pages.
    $violations = ui04ScanForIconGlyphs('Detail.vue', '        ← Back to audit log');

    expect($violations)->toHaveCount(1);
    expect($violations[0])->toContain('used as an icon');
});

it('flags a bare reorder arrow (negative control)', function (): void {
    expect(ui04ScanForIconGlyphs('QueueBoard.vue', '                  ↑'))->toHaveCount(1);
});

it('does not flag an arrow between two interpolated values (negative control)', function (): void {
    // `{{ run.period_start }} → {{ run.period_end }}` is a date RANGE, not an icon. UI01-ASSET-001
    // is explicit that text content is out of scope, and rewriting these would be a change the
    // defect never asked for.
    $body = '            {{ run.period_start }} → {{ run.period_end }} · {{ run.currency }}';

    expect(ui04ScanForIconGlyphs('PayoutRuns.vue', $body))->toBe([]);
});

it('does not flag an arrow inside a sort label (negative control)', function (): void {
    $body = "  { value: '-severity', label: 'Severity (high→low)' },";

    expect(ui04ScanForIconGlyphs('AuditEventList.vue', $body))->toBe([]);
});

it('does not flag a date range split by an element (negative control)', function (): void {
    // Compensation.vue:913. Still a RANGE even though a conditional <span> sits between the two
    // values; only the surrounding TEXT decides, which is why tags are blanked first.
    $body = '{{ detail.effective_from }}<span v-if="detail.effective_to"> → {{ detail.effective_to }}</span>';

    expect(ui04ScanForIconGlyphs('Compensation.vue', $body))->toBe([]);
});

it('still flags an arrow that leads a link\'s text (negative control)', function (): void {
    // Proving the tag-blanking above did not make the matcher blind: the arrow is the first thing
    // in the link's TEXT, so it is an icon regardless of the markup around it.
    $body = '<RouterLink :to="{ name: \'audit.events\' }" class="text-link">← Back to audit log</RouterLink>';

    expect(ui04ScanForIconGlyphs('AuditEventDetail.vue', $body))->toHaveCount(1);
});
